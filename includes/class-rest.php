<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST API: GET /wp-json/northline/v1/vehicles  (filters + pagination, used by "load more")
 *           GET /wp-json/northline/v1/models?make=toyota
 *           GET /wp-json/northline/v1/vin/{vin}
 */
class NL_Rest {

	public const NS = 'northline/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes(): void {
		register_rest_route( self::NS, '/vehicles', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'vehicles' ],
			'permission_callback' => '__return_true',
			'args'                => array_fill_keys( NL_Query::params(), [ 'type' => 'string' ] ) + [ 'per_page' => [ 'type' => 'integer', 'default' => NL_Query::PER_PAGE, 'minimum' => 1, 'maximum' => 48 ] ],
		] );
		register_rest_route( self::NS, '/models', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => static fn ( WP_REST_Request $r ) => NL_Query::models_for_make( sanitize_title( (string) $r->get_param( 'make' ) ) ),
			'permission_callback' => '__return_true',
			'args'                => [ 'make' => [ 'type' => 'string', 'required' => true ] ],
		] );
		register_rest_route( self::NS, '/vin/(?P<vin>[A-Za-z0-9]{17})', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => static function ( WP_REST_Request $r ) {
				$result = NL_Vin_Decoder::decode( (string) $r['vin'] );
				return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
			},
			'permission_callback' => static fn () => current_user_can( 'edit_posts' ),
		] );
	}

	public static function vehicles( WP_REST_Request $request ): WP_REST_Response {
		$filters = NL_Query::sanitize( $request->get_params() );
		$query   = NL_Query::query( $filters, (int) $request->get_param( 'per_page' ) );
		$items   = [];
		foreach ( $query->posts as $post ) {
			$items[] = self::vehicle_to_array( $post );
		}
		$response = new WP_REST_Response( [
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => (int) ( $filters['paged'] ?? 1 ),
			'items'       => $items,
		] );
		$response->header( 'X-WP-Total', (string) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (string) $query->max_num_pages );
		return $response;
	}

	public static function vehicle_to_array( WP_Post $post ): array {
		$data = [
			'id'        => $post->ID,
			'title'     => get_the_title( $post ),
			'url'       => get_permalink( $post ),
			'image'     => get_the_post_thumbnail_url( $post, 'medium_large' ) ?: '',
			'image_alt' => get_the_title( $post ),
			'html'      => nl_render_card( $post ),
		];
		foreach ( [ 'year', 'mileage_km', 'engine_cc', 'power_hp', 'price_usd', 'stock_no', 'grade', 'model', 'trim' ] as $k ) {
			$data[ $k ] = NL_Meta::get( $post->ID, $k );
		}
		foreach ( NL_Post_Types::TAXONOMIES as $tax => $labels ) {
			$terms        = get_the_terms( $post, $tax );
			$data[ str_replace( 'vehicle_', '', $tax ) ] = $terms && ! is_wp_error( $terms ) ? $terms[0]->name : '';
		}
		return $data;
	}
}
