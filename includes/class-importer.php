<?php
defined( 'ABSPATH' ) || exit;

/**
 * CSV importer. Creates or updates vehicles, assigns taxonomy terms and sideloads photos.
 *
 * Expected columns (header names, case-insensitive, order does not matter):
 * title, make, model, trim, year, mileage_km, engine_cc, power_hp, color, seats, vin, stock_no, grade,
 * price_usd, location, body, fuel, transmission, drive, status, features (pipe-separated), description,
 * photos (pipe-separated URLs or local paths).
 *
 * Rows are matched to existing vehicles by `vin`, then by `stock_no`.
 */
class NL_Importer {

	public const COLUMNS = [ 'title', 'make', 'model', 'trim', 'year', 'mileage_km', 'engine_cc', 'power_hp', 'color', 'seats', 'vin', 'stock_no', 'grade', 'price_usd', 'location', 'body', 'fuel', 'transmission', 'drive', 'status', 'features', 'description', 'photos' ];

	private const TAX_COLUMNS = [ 'make' => 'vehicle_make', 'body' => 'vehicle_body', 'fuel' => 'vehicle_fuel', 'transmission' => 'vehicle_transmission', 'drive' => 'vehicle_drive', 'status' => 'vehicle_status' ];

	/** @var array{created:int,updated:int,skipped:int,photos:int,errors:string[]} */
	public array $report = [ 'created' => 0, 'updated' => 0, 'skipped' => 0, 'photos' => 0, 'errors' => [] ];

	public function __construct(
		private bool $dry_run = false,
		private bool $decode_vin = false,
		private bool $import_photos = true,
		private ?Closure $logger = null
	) {}

	/** Parse a CSV file into associative rows keyed by normalised header. */
	public static function read( string $path ): array {
		$fh = fopen( $path, 'r' );
		if ( ! $fh ) {
			return [];
		}
		$first = fgets( $fh );
		if ( $first === false ) {
			return [];
		}
		$delimiter = substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ',';
		rewind( $fh );
		$header = fgetcsv( $fh, 0, $delimiter );
		if ( ! $header ) {
			return [];
		}
		$header = array_map( static fn ( $h ) => strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h ) ) ), $header );
		$rows   = [];
		while ( ( $data = fgetcsv( $fh, 0, $delimiter ) ) !== false ) {
			if ( count( array_filter( $data, static fn ( $v ) => trim( (string) $v ) !== '' ) ) === 0 ) {
				continue;
			}
			$row = [];
			foreach ( $header as $i => $col ) {
				$row[ $col ] = trim( (string) ( $data[ $i ] ?? '' ) );
			}
			$rows[] = $row;
		}
		fclose( $fh );
		return $rows;
	}

	public function run( array $rows ): array {
		NL_Post_Types::ensure_default_terms();
		foreach ( $rows as $i => $row ) {
			try {
				$this->import_row( $row, $i + 2 );
			} catch ( Throwable $e ) {
				$this->report['errors'][] = "Row " . ( $i + 2 ) . ': ' . $e->getMessage();
				$this->report['skipped']++;
			}
		}
		return $this->report;
	}

	private function log( string $msg ): void {
		if ( $this->logger ) {
			( $this->logger )( $msg );
		}
	}

	private function import_row( array $row, int $line ): void {
		if ( $this->decode_vin && ! empty( $row['vin'] ) && NL_Vin_Decoder::is_vin( $row['vin'] ) ) {
			$decoded = NL_Vin_Decoder::decode( $row['vin'] );
			if ( ! is_wp_error( $decoded ) ) {
				foreach ( $decoded as $k => $v ) {
					if ( $v !== '' && empty( $row[ $k ] ) ) {
						$row[ $k ] = $v;
					}
				}
			}
		}
		$title = $row['title'] ?? '';
		if ( $title === '' ) {
			$title = trim( implode( ' ', array_filter( [ $row['make'] ?? '', $row['model'] ?? '', $row['trim'] ?? '' ] ) ) );
		}
		if ( $title === '' ) {
			throw new RuntimeException( 'no title, make or model' );
		}
		$existing = $this->find_existing( $row );
		$this->log( sprintf( '%s %s (line %d)', $existing ? 'Update' : 'Create', $title, $line ) );
		if ( $this->dry_run ) {
			$this->report[ $existing ? 'updated' : 'created' ]++;
			return;
		}
		$postarr = [
			'post_type'    => NL_Post_Types::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $row['description'] ?? '',
		];
		if ( $existing ) {
			$postarr['ID'] = $existing;
			$post_id       = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
		}
		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( $post_id->get_error_message() );
		}
		foreach ( NL_Meta::fields() as $key => [ , $type ] ) {
			if ( ! array_key_exists( $key, $row ) ) {
				continue;
			}
			$value = $row[ $key ];
			if ( $key === 'features' ) {
				$value = implode( "\n", array_filter( array_map( 'trim', preg_split( '/\s*\|\s*|\r?\n/', $value ) ) ) );
			}
			$value = $type === 'number' ? NL_Meta::sanitize_number( $value ) : sanitize_textarea_field( $value );
			if ( $value === '' ) {
				delete_post_meta( $post_id, '_nl_' . $key );
			} else {
				update_post_meta( $post_id, '_nl_' . $key, $value );
			}
		}
		foreach ( self::TAX_COLUMNS as $col => $tax ) {
			if ( ! empty( $row[ $col ] ) ) {
				wp_set_object_terms( $post_id, $this->normalise_term( $col, $row[ $col ] ), $tax, false );
			}
		}
		if ( $this->import_photos && ! empty( $row['photos'] ) ) {
			$this->attach_photos( $post_id, preg_split( '/\s*\|\s*/', $row['photos'] ) );
		}
		$this->report[ $existing ? 'updated' : 'created' ]++;
	}

	private function normalise_term( string $col, string $value ): string {
		$value = trim( $value );
		if ( $col === 'make' ) {
			$upper = [ 'BMW', 'GMC', 'RAM', 'MG', 'BYD', 'KIA' ];
			return in_array( strtoupper( $value ), $upper, true ) ? strtoupper( $value ) : ucwords( strtolower( $value ) );
		}
		if ( $col === 'transmission' ) {
			$v = strtolower( $value );
			if ( in_array( $v, [ 'at', 'fat', 'dat', 'iat', 'automatic', 'auto', 'автомат' ], true ) ) {
				return 'Automatic';
			}
			if ( in_array( $v, [ 'mt', 'manual', 'f5', 'f6', '5mt', '6mt' ], true ) ) {
				return 'Manual';
			}
			if ( $v === 'cvt' ) {
				return 'CVT';
			}
		}
		return ucfirst( $value );
	}

	private function find_existing( array $row ): int {
		foreach ( [ 'vin', 'stock_no' ] as $key ) {
			if ( empty( $row[ $key ] ) ) {
				continue;
			}
			$found = get_posts( [
				'post_type'      => NL_Post_Types::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_nl_' . $key,
				'meta_value'     => $row[ $key ],
			] );
			if ( $found ) {
				return (int) $found[0];
			}
		}
		return 0;
	}

	private function attach_photos( int $post_id, array $sources ): void {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$ids      = [];
		$existing = (array) get_post_meta( $post_id, '_nl_gallery', true );
		foreach ( $sources as $src ) {
			$src = trim( $src );
			if ( $src === '' ) {
				continue;
			}
			$known = $this->find_attachment_by_source( $src );
			if ( $known ) {
				$ids[] = $known;
				continue;
			}
			if ( preg_match( '#^https?://#i', $src ) ) {
				$tmp = download_url( $src, 60 );
				if ( is_wp_error( $tmp ) ) {
					$this->report['errors'][] = "Photo $src: " . $tmp->get_error_message();
					continue;
				}
				$name = basename( (string) wp_parse_url( $src, PHP_URL_PATH ) );
			} else {
				$path = $this->resolve_local( $src );
				if ( ! $path ) {
					$this->report['errors'][] = "Photo $src: file not found";
					continue;
				}
				$tmp = wp_tempnam( basename( $path ) );
				copy( $path, $tmp );
				$name = basename( $path );
			}
			if ( ! preg_match( '/\.(jpe?g|png|webp|gif)$/i', $name ) ) {
				$name .= '.jpg';
			}
			$file = [ 'name' => $name, 'tmp_name' => $tmp ];
			$id   = media_handle_sideload( $file, $post_id, null );
			if ( is_wp_error( $id ) ) {
				@unlink( $tmp );
				$this->report['errors'][] = "Photo $src: " . $id->get_error_message();
				continue;
			}
			update_post_meta( $id, '_nl_source', $src );
			$ids[] = (int) $id;
			$this->report['photos']++;
		}
		if ( ! $ids ) {
			return;
		}
		$ids = array_values( array_unique( array_merge( array_map( 'intval', $existing ), $ids ) ) );
		update_post_meta( $post_id, '_nl_gallery', $ids );
		if ( ! has_post_thumbnail( $post_id ) ) {
			set_post_thumbnail( $post_id, $ids[0] );
		}
	}

	private function find_attachment_by_source( string $src ): int {
		$found = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_nl_source', 'meta_value' => $src ] );
		return $found ? (int) $found[0] : 0;
	}

	private function resolve_local( string $src ): string {
		$candidates = [ $src, ABSPATH . ltrim( $src, '/' ), wp_upload_dir()['basedir'] . '/' . ltrim( $src, '/' ) ];
		foreach ( $candidates as $c ) {
			$real = realpath( $c );
			if ( $real && is_file( $real ) ) {
				return $real;
			}
		}
		return '';
	}
}
