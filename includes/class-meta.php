<?php
defined( 'ABSPATH' ) || exit;

/**
 * Vehicle meta fields: registration, meta box, saving.
 */
class NL_Meta {

	/** key => [label, type, args]. Keys are stored with a leading underscore. */
	public static function fields(): array {
		return [
			'model'      => [ 'Model', 'text', [ 'placeholder' => 'Alphard' ] ],
			'trim'       => [ 'Trim / grade', 'text', [ 'placeholder' => '2.5 Executive Lounge' ] ],
			'year'       => [ 'Year', 'number', [ 'min' => 1950, 'max' => (int) gmdate( 'Y' ) + 1 ] ],
			'mileage_km' => [ 'Mileage, km', 'number', [ 'min' => 0, 'step' => 1 ] ],
			'engine_cc'  => [ 'Engine, cc', 'number', [ 'min' => 0, 'step' => 10 ] ],
			'power_hp'   => [ 'Power, hp', 'number', [ 'min' => 0 ] ],
			'color'      => [ 'Colour', 'text', [] ],
			'seats'      => [ 'Seats', 'number', [ 'min' => 1, 'max' => 12 ] ],
			'vin'        => [ 'VIN / chassis no.', 'text', [ 'class' => 'nl-vin' ] ],
			'stock_no'   => [ 'Stock / lot no.', 'text', [] ],
			'grade'      => [ 'Auction grade', 'text', [ 'placeholder' => '4.5' ] ],
			'price_usd'  => [ 'Price, USD', 'number', [ 'min' => 0, 'step' => 1 ] ],
			'location'   => [ 'Location', 'text', [ 'placeholder' => 'Yokohama port' ] ],
			'features'   => [ 'Features (one per line)', 'textarea', [] ],
		];
	}

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_boxes' ] );
		add_action( 'save_post_' . NL_Post_Types::POST_TYPE, [ __CLASS__, 'save' ], 10, 2 );
		add_filter( 'manage_' . NL_Post_Types::POST_TYPE . '_posts_columns', [ __CLASS__, 'columns' ] );
		add_action( 'manage_' . NL_Post_Types::POST_TYPE . '_posts_custom_column', [ __CLASS__, 'column_content' ], 10, 2 );
		add_filter( 'manage_edit-' . NL_Post_Types::POST_TYPE . '_sortable_columns', [ __CLASS__, 'sortable_columns' ] );
		add_action( 'pre_get_posts', [ __CLASS__, 'admin_orderby' ] );
	}

	public static function register_meta(): void {
		foreach ( self::fields() as $key => [ , $type ] ) {
			register_post_meta( NL_Post_Types::POST_TYPE, '_nl_' . $key, [
				'type'              => $type === 'number' ? 'number' : 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => $type === 'number' ? [ __CLASS__, 'sanitize_number' ] : 'sanitize_textarea_field',
				'auth_callback'     => static fn () => current_user_can( 'edit_posts' ),
			] );
		}
		register_post_meta( NL_Post_Types::POST_TYPE, '_nl_gallery', [
			'type'         => 'array',
			'single'       => true,
			'show_in_rest' => [ 'schema' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ] ],
			'auth_callback' => static fn () => current_user_can( 'edit_posts' ),
		] );
	}

	public static function sanitize_number( $value ) {
		if ( $value === '' || $value === null ) {
			return '';
		}
		$value = str_replace( [ ' ', ',' ], '', (string) $value );
		return is_numeric( $value ) ? $value + 0 : '';
	}

	public static function get( int $post_id, string $key ) {
		return get_post_meta( $post_id, '_nl_' . $key, true );
	}

	public static function add_meta_boxes(): void {
		add_meta_box( 'nl_vehicle_specs', 'Vehicle specifications', [ __CLASS__, 'render_specs_box' ], NL_Post_Types::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'nl_vehicle_gallery', 'Photo gallery', [ __CLASS__, 'render_gallery_box' ], NL_Post_Types::POST_TYPE, 'side', 'default' );
	}

	public static function render_specs_box( WP_Post $post ): void {
		wp_nonce_field( 'nl_save_vehicle', 'nl_vehicle_nonce' );
		echo '<div class="nl-fields">';
		foreach ( self::fields() as $key => [ $label, $type, $args ] ) {
			$id    = 'nl_' . $key;
			$value = self::get( $post->ID, $key );
			echo '<p class="nl-field nl-field--' . esc_attr( $type ) . '"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
			if ( $type === 'textarea' ) {
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" rows="5">' . esc_textarea( (string) $value ) . '</textarea>';
			} else {
				$attrs = '';
				foreach ( $args as $a => $v ) {
					$attrs .= ' ' . esc_attr( $a ) . '="' . esc_attr( (string) $v ) . '"';
				}
				echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="' . esc_attr( (string) $value ) . '"' . $attrs . '>';
				if ( $key === 'vin' ) {
					echo ' <button type="button" class="button" id="nl-decode-vin">Decode VIN</button><span class="nl-vin-result"></span>';
				}
			}
			echo '</p>';
		}
		echo '</div>';
	}

	public static function render_gallery_box( WP_Post $post ): void {
		$ids = (array) get_post_meta( $post->ID, '_nl_gallery', true );
		$ids = array_filter( array_map( 'intval', $ids ) );
		echo '<div class="nl-gallery" id="nl-gallery">';
		echo '<ul class="nl-gallery__list">';
		foreach ( $ids as $id ) {
			$thumb = wp_get_attachment_image( $id, 'thumbnail' );
			if ( $thumb ) {
				echo '<li data-id="' . esc_attr( (string) $id ) . '">' . $thumb . '<button type="button" class="nl-gallery__remove" aria-label="Remove">&times;</button></li>';
			}
		}
		echo '</ul>';
		echo '<input type="hidden" name="nl_gallery" id="nl_gallery" value="' . esc_attr( implode( ',', $ids ) ) . '">';
		echo '<button type="button" class="button" id="nl-gallery-add">Add photos</button>';
		echo '<p class="description">Drag to reorder. The first photo is used as the card image when no featured image is set.</p>';
		echo '</div>';
	}

	public static function save( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST['nl_vehicle_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nl_vehicle_nonce'] ), 'nl_save_vehicle' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( self::fields() as $key => [ , $type ] ) {
			$name = 'nl_' . $key;
			if ( ! isset( $_POST[ $name ] ) ) {
				continue;
			}
			$raw   = wp_unslash( $_POST[ $name ] );
			$value = $type === 'number' ? self::sanitize_number( $raw ) : sanitize_textarea_field( $raw );
			if ( $value === '' ) {
				delete_post_meta( $post_id, '_nl_' . $key );
			} else {
				update_post_meta( $post_id, '_nl_' . $key, $value );
			}
		}
		if ( isset( $_POST['nl_gallery'] ) ) {
			$ids = array_filter( array_map( 'intval', explode( ',', sanitize_text_field( wp_unslash( $_POST['nl_gallery'] ) ) ) ) );
			update_post_meta( $post_id, '_nl_gallery', array_values( $ids ) );
			if ( $ids && ! has_post_thumbnail( $post_id ) ) {
				set_post_thumbnail( $post_id, $ids[0] );
			}
		}
	}

	public static function columns( array $columns ): array {
		$new = [];
		foreach ( $columns as $k => $v ) {
			if ( $k === 'title' ) {
				$new['nl_photo'] = 'Photo';
			}
			$new[ $k ] = $v;
			if ( $k === 'title' ) {
				$new['nl_year']  = 'Year';
				$new['nl_price'] = 'Price';
				$new['nl_stock'] = 'Stock no.';
			}
		}
		unset( $new['taxonomy-vehicle_fuel'], $new['taxonomy-vehicle_transmission'], $new['taxonomy-vehicle_drive'] );
		return $new;
	}

	public static function column_content( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'nl_photo':
				echo get_the_post_thumbnail( $post_id, [ 60, 45 ] );
				break;
			case 'nl_year':
				echo esc_html( (string) self::get( $post_id, 'year' ) );
				break;
			case 'nl_price':
				echo esc_html( nl_format_price( self::get( $post_id, 'price_usd' ) ) );
				break;
			case 'nl_stock':
				echo esc_html( (string) self::get( $post_id, 'stock_no' ) );
				break;
		}
	}

	public static function sortable_columns( array $columns ): array {
		$columns['nl_year']  = 'nl_year';
		$columns['nl_price'] = 'nl_price';
		return $columns;
	}

	public static function admin_orderby( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		$orderby = $query->get( 'orderby' );
		if ( $orderby === 'nl_year' || $orderby === 'nl_price' ) {
			$query->set( 'meta_key', $orderby === 'nl_year' ? '_nl_year' : '_nl_price_usd' );
			$query->set( 'orderby', 'meta_value_num' );
		}
	}
}
