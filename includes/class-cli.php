<?php
defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI: wp northline import <file> [--dry-run] [--decode-vin] [--no-photos]
 *         wp northline flush-vin-cache
 */
class NL_CLI {

	/**
	 * Import vehicles from a CSV file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the CSV file.
	 *
	 * [--dry-run]
	 * : Validate and report without writing anything.
	 *
	 * [--decode-vin]
	 * : Fill empty fields by decoding 17-character VINs via NHTSA.
	 *
	 * [--no-photos]
	 * : Skip downloading photos.
	 */
	public function import( array $args, array $assoc ): void {
		$file = $args[0];
		if ( ! is_readable( $file ) ) {
			WP_CLI::error( "Cannot read $file" );
		}
		$rows = NL_Importer::read( $file );
		WP_CLI::log( count( $rows ) . ' rows found' );
		$importer = new NL_Importer(
			isset( $assoc['dry-run'] ),
			isset( $assoc['decode-vin'] ),
			! isset( $assoc['no-photos'] ),
			static fn ( string $m ) => WP_CLI::log( $m )
		);
		$report = $importer->run( $rows );
		foreach ( $report['errors'] as $e ) {
			WP_CLI::warning( $e );
		}
		WP_CLI::success( sprintf( 'Created %d, updated %d, skipped %d, photos %d', $report['created'], $report['updated'], $report['skipped'], $report['photos'] ) );
	}

	/** Delete cached NHTSA responses. */
	public function flush_vin_cache(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_nl_vin_%' OR option_name LIKE '_transient_timeout_nl_vin_%'" );
		WP_CLI::success( 'VIN cache cleared' );
	}
}

WP_CLI::add_command( 'northline', 'NL_CLI' );
