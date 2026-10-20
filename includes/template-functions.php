<?php
defined( 'ABSPATH' ) || exit;

/**
 * Template helpers used by themes. All functions are safe to call outside the loop with a post ID.
 */

function nl_format_price( $value, string $currency = '$' ): string {
	if ( $value === '' || $value === null ) {
		return 'Price on request';
	}
	return $currency . number_format( (float) $value, 0, '.', ',' );
}

function nl_format_km( $value ): string {
	return $value === '' || $value === null ? '—' : number_format( (float) $value, 0, '.', ',' ) . ' km';
}

function nl_vehicle_meta( int $post_id, string $key ) {
	return NL_Meta::get( $post_id, $key );
}

function nl_vehicle_term( int $post_id, string $tax ): string {
	$terms = get_the_terms( $post_id, 'vehicle_' . $tax );
	return $terms && ! is_wp_error( $terms ) ? $terms[0]->name : '';
}

/** Ordered list of spec rows for a vehicle: [label => value]. Empty values are dropped. */
function nl_vehicle_specs( int $post_id ): array {
	$cc    = nl_vehicle_meta( $post_id, 'engine_cc' );
	$hp    = nl_vehicle_meta( $post_id, 'power_hp' );
	$specs = [
		'Year'         => nl_vehicle_meta( $post_id, 'year' ),
		'Mileage'      => nl_vehicle_meta( $post_id, 'mileage_km' ) !== '' ? nl_format_km( nl_vehicle_meta( $post_id, 'mileage_km' ) ) : '',
		'Engine'       => $cc !== '' ? number_format( (float) $cc, 0, '.', ',' ) . ' cc' . ( $hp !== '' ? ' / ' . $hp . ' hp' : '' ) : '',
		'Fuel'         => nl_vehicle_term( $post_id, 'fuel' ),
		'Transmission' => nl_vehicle_term( $post_id, 'transmission' ),
		'Drivetrain'   => nl_vehicle_term( $post_id, 'drive' ),
		'Body'         => nl_vehicle_term( $post_id, 'body' ),
		'Colour'       => nl_vehicle_meta( $post_id, 'color' ),
		'Seats'        => nl_vehicle_meta( $post_id, 'seats' ),
		'Trim'         => nl_vehicle_meta( $post_id, 'trim' ),
		'Auction grade' => nl_vehicle_meta( $post_id, 'grade' ),
		'Stock no.'    => nl_vehicle_meta( $post_id, 'stock_no' ),
		'VIN / chassis' => nl_vehicle_meta( $post_id, 'vin' ),
		'Location'     => nl_vehicle_meta( $post_id, 'location' ),
	];
	return array_filter( $specs, static fn ( $v ) => $v !== '' && $v !== null );
}

function nl_vehicle_features( int $post_id ): array {
	$raw = (string) nl_vehicle_meta( $post_id, 'features' );
	return array_values( array_filter( array_map( 'trim', preg_split( '/\r?\n/', $raw ) ) ) );
}

/** Attachment IDs for the gallery, falling back to the featured image. */
function nl_vehicle_gallery( int $post_id ): array {
	$ids = array_filter( array_map( 'intval', (array) get_post_meta( $post_id, '_nl_gallery', true ) ) );
	if ( ! $ids && has_post_thumbnail( $post_id ) ) {
		$ids = [ get_post_thumbnail_id( $post_id ) ];
	}
	return array_values( $ids );
}

/** Short one-line summary used on cards: "2019 · 45,000 km · 2,500 cc · Automatic". */
function nl_vehicle_summary( int $post_id ): string {
	$parts = array_filter( [
		nl_vehicle_meta( $post_id, 'year' ),
		nl_vehicle_meta( $post_id, 'mileage_km' ) !== '' ? nl_format_km( nl_vehicle_meta( $post_id, 'mileage_km' ) ) : '',
		nl_vehicle_meta( $post_id, 'engine_cc' ) !== '' ? number_format( (float) nl_vehicle_meta( $post_id, 'engine_cc' ), 0, '.', ',' ) . ' cc' : '',
		nl_vehicle_term( $post_id, 'transmission' ),
	] );
	return implode( ' · ', $parts );
}

/** Render a vehicle card. Themes may override by providing `northline/vehicle-card.php`. */
function nl_render_card( WP_Post|int $post ): string {
	$post = get_post( $post );
	if ( ! $post ) {
		return '';
	}
	$template = locate_template( 'northline/vehicle-card.php' );
	ob_start();
	if ( $template ) {
		include $template;
	} else {
		$status = nl_vehicle_term( $post->ID, 'status' );
		?>
		<article class="vehicle-card">
			<a class="vehicle-card__media" href="<?php echo esc_url( get_permalink( $post ) ); ?>">
				<?php echo get_the_post_thumbnail( $post, 'medium_large', [ 'loading' => 'lazy' ] ); ?>
				<?php if ( $status ) : ?><span class="vehicle-card__status"><?php echo esc_html( $status ); ?></span><?php endif; ?>
			</a>
			<div class="vehicle-card__body">
				<h3 class="vehicle-card__title"><a href="<?php echo esc_url( get_permalink( $post ) ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a></h3>
				<p class="vehicle-card__summary"><?php echo esc_html( nl_vehicle_summary( $post->ID ) ); ?></p>
				<p class="vehicle-card__price"><?php echo esc_html( nl_format_price( nl_vehicle_meta( $post->ID, 'price_usd' ) ) ); ?></p>
			</div>
		</article>
		<?php
	}
	return (string) ob_get_clean();
}

/** Schema.org Vehicle + Offer JSON-LD for a single vehicle page. */
function nl_vehicle_jsonld( int $post_id ): string {
	$make  = nl_vehicle_term( $post_id, 'make' );
	$price = nl_vehicle_meta( $post_id, 'price_usd' );
	$data  = [
		'@context'     => 'https://schema.org',
		'@type'        => 'Vehicle',
		'name'         => get_the_title( $post_id ),
		'url'          => get_permalink( $post_id ),
		'image'        => array_values( array_filter( array_map( static fn ( $id ) => wp_get_attachment_image_url( $id, 'large' ), nl_vehicle_gallery( $post_id ) ) ) ),
		'brand'        => $make ? [ '@type' => 'Brand', 'name' => $make ] : null,
		'model'        => nl_vehicle_meta( $post_id, 'model' ) ?: null,
		'vehicleModelDate' => nl_vehicle_meta( $post_id, 'year' ) ?: null,
		'mileageFromOdometer' => nl_vehicle_meta( $post_id, 'mileage_km' ) !== '' ? [ '@type' => 'QuantitativeValue', 'value' => (int) nl_vehicle_meta( $post_id, 'mileage_km' ), 'unitCode' => 'KMT' ] : null,
		'vehicleEngine' => nl_vehicle_meta( $post_id, 'engine_cc' ) !== '' ? [ '@type' => 'EngineSpecification', 'engineDisplacement' => [ '@type' => 'QuantitativeValue', 'value' => (int) nl_vehicle_meta( $post_id, 'engine_cc' ), 'unitCode' => 'CMQ' ] ] : null,
		'vehicleTransmission' => nl_vehicle_term( $post_id, 'transmission' ) ?: null,
		'fuelType'     => nl_vehicle_term( $post_id, 'fuel' ) ?: null,
		'bodyType'     => nl_vehicle_term( $post_id, 'body' ) ?: null,
		'color'        => nl_vehicle_meta( $post_id, 'color' ) ?: null,
		'vehicleIdentificationNumber' => nl_vehicle_meta( $post_id, 'vin' ) ?: null,
		'offers'       => $price !== '' ? [ '@type' => 'Offer', 'price' => (float) $price, 'priceCurrency' => 'USD', 'availability' => 'https://schema.org/InStock', 'url' => get_permalink( $post_id ) ] : null,
	];
	return wp_json_encode( array_filter( $data, static fn ( $v ) => $v !== null && $v !== [] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}
