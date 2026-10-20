<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers the `vehicle` post type and its taxonomies.
 */
class NL_Post_Types {

	public const POST_TYPE = 'vehicle';

	/** Taxonomy slug => [singular, plural, rewrite slug]. */
	public const TAXONOMIES = [
		'vehicle_make'         => [ 'Make', 'Makes', 'make' ],
		'vehicle_body'         => [ 'Body type', 'Body types', 'body' ],
		'vehicle_fuel'         => [ 'Fuel', 'Fuel types', 'fuel' ],
		'vehicle_transmission' => [ 'Transmission', 'Transmissions', 'transmission' ],
		'vehicle_drive'        => [ 'Drivetrain', 'Drivetrains', 'drive' ],
		'vehicle_status'       => [ 'Status', 'Statuses', 'status' ],
	];

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_filter( 'enter_title_here', [ __CLASS__, 'title_placeholder' ], 10, 2 );
	}

	public static function register(): void {
		register_post_type( self::POST_TYPE, [
			'labels'       => [
				'name'               => 'Vehicles',
				'singular_name'      => 'Vehicle',
				'add_new_item'       => 'Add vehicle',
				'edit_item'          => 'Edit vehicle',
				'new_item'           => 'New vehicle',
				'view_item'          => 'View vehicle',
				'search_items'       => 'Search vehicles',
				'not_found'          => 'No vehicles found',
				'all_items'          => 'All vehicles',
				'menu_name'          => 'Inventory',
			],
			'public'       => true,
			'has_archive'  => 'inventory',
			'rewrite'      => [ 'slug' => 'inventory', 'with_front' => false ],
			'menu_icon'    => 'dashicons-car',
			'menu_position' => 5,
			'supports'     => [ 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ],
			'show_in_rest' => true,
			'rest_base'    => 'vehicles',
		] );

		foreach ( self::TAXONOMIES as $slug => [ $singular, $plural, $rewrite ] ) {
			register_taxonomy( $slug, self::POST_TYPE, [
				'labels'            => [
					'name'          => $plural,
					'singular_name' => $singular,
					'menu_name'     => $plural,
				],
				'public'            => true,
				'hierarchical'      => false,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => [ 'slug' => 'inventory/' . $rewrite, 'with_front' => false ],
			] );
		}
	}

	public static function title_placeholder( string $text, WP_Post $post ): string {
		return $post->post_type === self::POST_TYPE ? 'e.g. Toyota Alphard 2.5 Executive Lounge' : $text;
	}

	/** Default statuses created on first use. */
	public static function ensure_default_terms(): void {
		foreach ( [ 'In stock', 'In transit', 'Sold', 'At auction' ] as $name ) {
			if ( ! term_exists( $name, 'vehicle_status' ) ) {
				wp_insert_term( $name, 'vehicle_status' );
			}
		}
	}
}
