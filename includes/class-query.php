<?php
defined( 'ABSPATH' ) || exit;

/**
 * Builds WP_Query arguments for the inventory from request parameters.
 * Used by the archive template and the REST endpoint alike.
 */
class NL_Query {

	public const PER_PAGE = 12;

	/** Allowed sort keys => [meta key or null, order]. */
	public const SORTS = [
		'newest'     => [ null, 'DESC' ],
		'price_asc'  => [ '_nl_price_usd', 'ASC' ],
		'price_desc' => [ '_nl_price_usd', 'DESC' ],
		'year_desc'  => [ '_nl_year', 'DESC' ],
		'year_asc'   => [ '_nl_year', 'ASC' ],
		'km_asc'     => [ '_nl_mileage_km', 'ASC' ],
	];

	/** Request parameter names understood by the filter. */
	public static function params(): array {
		return [ 'make', 'model', 'body', 'fuel', 'transmission', 'drive', 'status', 'year_min', 'year_max', 'price_min', 'price_max', 'km_max', 'q', 'sort', 'paged' ];
	}

	/** Pick and sanitise filter values from an array such as $_GET. */
	public static function sanitize( array $input ): array {
		$out = [];
		foreach ( self::params() as $p ) {
			if ( ! isset( $input[ $p ] ) || $input[ $p ] === '' ) {
				continue;
			}
			$v = sanitize_text_field( wp_unslash( (string) $input[ $p ] ) );
			if ( in_array( $p, [ 'year_min', 'year_max', 'price_min', 'price_max', 'km_max', 'paged' ], true ) ) {
				$v = (int) preg_replace( '/\D/', '', $v );
				if ( $v <= 0 ) {
					continue;
				}
			}
			if ( $p === 'sort' && ! isset( self::SORTS[ $v ] ) ) {
				continue;
			}
			$out[ $p ] = $v;
		}
		return $out;
	}

	public static function args( array $f, int $per_page = self::PER_PAGE ): array {
		$args = [
			'post_type'      => NL_Post_Types::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $f['paged'] ?? 1,
			'tax_query'      => [ 'relation' => 'AND' ],
			'meta_query'     => [ 'relation' => 'AND' ],
		];
		foreach ( [ 'make', 'body', 'fuel', 'transmission', 'drive', 'status' ] as $tax ) {
			if ( ! empty( $f[ $tax ] ) ) {
				$args['tax_query'][] = [ 'taxonomy' => 'vehicle_' . $tax, 'field' => 'slug', 'terms' => explode( ',', $f[ $tax ] ) ];
			}
		}
		if ( ! empty( $f['model'] ) ) {
			$args['meta_query'][] = [ 'key' => '_nl_model', 'value' => $f['model'], 'compare' => 'LIKE' ];
		}
		if ( ! empty( $f['year_min'] ) ) {
			$args['meta_query'][] = [ 'key' => '_nl_year', 'value' => $f['year_min'], 'compare' => '>=', 'type' => 'NUMERIC' ];
		}
		if ( ! empty( $f['year_max'] ) ) {
			$args['meta_query'][] = [ 'key' => '_nl_year', 'value' => $f['year_max'], 'compare' => '<=', 'type' => 'NUMERIC' ];
		}
		if ( ! empty( $f['price_min'] ) ) {
			$args['meta_query'][] = [ 'key' => '_nl_price_usd', 'value' => $f['price_min'], 'compare' => '>=', 'type' => 'NUMERIC' ];
		}
		if ( ! empty( $f['price_max'] ) ) {
			$args['meta_query'][] = [ 'key' => '_nl_price_usd', 'value' => $f['price_max'], 'compare' => '<=', 'type' => 'NUMERIC' ];
		}
		if ( ! empty( $f['km_max'] ) ) {
			$args['meta_query'][] = [ 'key' => '_nl_mileage_km', 'value' => $f['km_max'], 'compare' => '<=', 'type' => 'NUMERIC' ];
		}
		if ( ! empty( $f['q'] ) ) {
			$args['s'] = $f['q'];
		}
		[ $meta_key, $order ] = self::SORTS[ $f['sort'] ?? 'newest' ];
		if ( $meta_key ) {
			$args['meta_key'] = $meta_key;
			$args['orderby']  = 'meta_value_num';
		} else {
			$args['orderby'] = 'date';
		}
		$args['order'] = $order;
		return $args;
	}

	public static function query( array $f, int $per_page = self::PER_PAGE ): WP_Query {
		return new WP_Query( self::args( $f, $per_page ) );
	}

	/** Terms with counts for a filter dropdown. */
	public static function terms( string $tax ): array {
		$terms = get_terms( [ 'taxonomy' => 'vehicle_' . $tax, 'hide_empty' => true, 'orderby' => 'name' ] );
		return is_wp_error( $terms ) ? [] : $terms;
	}

	/** Distinct models for a make (slug), for the dependent model select. */
	public static function models_for_make( string $make_slug ): array {
		global $wpdb;
		$term = get_term_by( 'slug', $make_slug, 'vehicle_make' );
		if ( ! $term ) {
			return [];
		}
		$sql = $wpdb->prepare(
			"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status = 'publish' AND p.post_type = %s
			 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.term_id = %d
			 WHERE pm.meta_key = '_nl_model' AND pm.meta_value <> '' ORDER BY pm.meta_value",
			NL_Post_Types::POST_TYPE,
			$term->term_id
		);
		return array_map( 'strval', (array) $wpdb->get_col( $sql ) );
	}

	/** Min/max of a numeric meta across published vehicles. */
	public static function range( string $meta_key ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT MIN(CAST(pm.meta_value AS UNSIGNED)) AS mn, MAX(CAST(pm.meta_value AS UNSIGNED)) AS mx
			 FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_status = 'publish' AND p.post_type = %s AND pm.meta_key = %s AND pm.meta_value <> ''",
			NL_Post_Types::POST_TYPE,
			$meta_key
		), ARRAY_A );
		return [ (int) ( $row['mn'] ?? 0 ), (int) ( $row['mx'] ?? 0 ) ];
	}
}
