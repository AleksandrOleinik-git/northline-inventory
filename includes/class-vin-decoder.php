<?php
defined( 'ABSPATH' ) || exit;

/**
 * Decodes 17-character VINs through the free NHTSA vPIC API (no key required).
 * Japanese domestic chassis numbers are not VINs and are returned as "not decodable".
 */
class NL_Vin_Decoder {

	private const ENDPOINT = 'https://vpic.nhtsa.dot.gov/api/vehicles/DecodeVinValues/%s?format=json';

	public static function is_vin( string $vin ): bool {
		return (bool) preg_match( '/^[A-HJ-NPR-Z0-9]{17}$/i', $vin );
	}

	/**
	 * @return array{make:string,model:string,year:string,body:string,fuel:string,transmission:string,drive:string,engine_cc:string,power_hp:string,seats:string,trim:string}|WP_Error
	 */
	public static function decode( string $vin ) {
		$vin = strtoupper( trim( $vin ) );
		if ( ! self::is_vin( $vin ) ) {
			return new WP_Error( 'nl_not_vin', 'Not a 17-character VIN (Japanese chassis numbers cannot be decoded).' );
		}
		$cached = get_transient( 'nl_vin_' . $vin );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$response = wp_remote_get( sprintf( self::ENDPOINT, rawurlencode( $vin ) ), [ 'timeout' => 15 ] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$row  = $body['Results'][0] ?? null;
		if ( ! is_array( $row ) ) {
			return new WP_Error( 'nl_bad_response', 'Unexpected response from NHTSA.' );
		}
		if ( ! empty( $row['ErrorCode'] ) && (int) $row['ErrorCode'] !== 0 && empty( $row['Make'] ) ) {
			return new WP_Error( 'nl_vin_error', $row['ErrorText'] ?? 'VIN could not be decoded.' );
		}
		$cc = '';
		if ( ! empty( $row['DisplacementCC'] ) ) {
			$cc = (string) round( (float) $row['DisplacementCC'] );
		} elseif ( ! empty( $row['DisplacementL'] ) ) {
			$cc = (string) round( (float) $row['DisplacementL'] * 1000 );
		}
		$result = [
			'make'         => ucwords( strtolower( (string) ( $row['Make'] ?? '' ) ) ),
			'model'        => (string) ( $row['Model'] ?? '' ),
			'year'         => (string) ( $row['ModelYear'] ?? '' ),
			'body'         => self::simplify_body( (string) ( $row['BodyClass'] ?? '' ) ),
			'fuel'         => self::simplify_fuel( (string) ( $row['FuelTypePrimary'] ?? '' ), (string) ( $row['ElectrificationLevel'] ?? '' ) ),
			'transmission' => self::simplify_transmission( (string) ( $row['TransmissionStyle'] ?? '' ) ),
			'drive'        => self::simplify_drive( (string) ( $row['DriveType'] ?? '' ) ),
			'engine_cc'    => $cc,
			'power_hp'     => ! empty( $row['EngineHP'] ) ? (string) round( (float) $row['EngineHP'] ) : '',
			'seats'        => (string) ( $row['Seats'] ?? '' ),
			'trim'         => (string) ( $row['Trim'] ?? '' ),
		];
		set_transient( 'nl_vin_' . $vin, $result, WEEK_IN_SECONDS );
		return $result;
	}

	private static function simplify_body( string $body ): string {
		$b = strtolower( $body );
		foreach ( [ 'suv' => 'SUV', 'pickup' => 'Pickup', 'van' => 'Van', 'minivan' => 'Minivan', 'wagon' => 'Wagon', 'hatchback' => 'Hatchback', 'coupe' => 'Coupe', 'convertible' => 'Convertible', 'sedan' => 'Sedan', 'truck' => 'Truck' ] as $needle => $label ) {
			if ( str_contains( $b, $needle ) ) {
				return $label;
			}
		}
		return $body;
	}

	private static function simplify_fuel( string $fuel, string $electrification ): string {
		$e = strtolower( $electrification );
		if ( str_contains( $e, 'bev' ) ) {
			return 'Electric';
		}
		if ( str_contains( $e, 'phev' ) ) {
			return 'Plug-in hybrid';
		}
		if ( str_contains( $e, 'hev' ) || str_contains( strtolower( $fuel ), 'hybrid' ) ) {
			return 'Hybrid';
		}
		$f = strtolower( $fuel );
		if ( str_contains( $f, 'diesel' ) ) {
			return 'Diesel';
		}
		if ( str_contains( $f, 'gasoline' ) ) {
			return 'Petrol';
		}
		if ( str_contains( $f, 'electric' ) ) {
			return 'Electric';
		}
		return $fuel;
	}

	private static function simplify_transmission( string $t ): string {
		$s = strtolower( $t );
		if ( str_contains( $s, 'manual' ) ) {
			return 'Manual';
		}
		if ( str_contains( $s, 'cvt' ) || str_contains( $s, 'continuously' ) ) {
			return 'CVT';
		}
		if ( $s !== '' ) {
			return 'Automatic';
		}
		return '';
	}

	private static function simplify_drive( string $d ): string {
		$s = strtolower( $d );
		if ( str_contains( $s, 'awd' ) || str_contains( $s, 'all' ) ) {
			return 'AWD';
		}
		if ( str_contains( $s, '4wd' ) || str_contains( $s, '4x4' ) ) {
			return '4WD';
		}
		if ( str_contains( $s, 'rwd' ) || str_contains( $s, 'rear' ) ) {
			return 'RWD';
		}
		if ( str_contains( $s, 'fwd' ) || str_contains( $s, 'front' ) ) {
			return 'FWD';
		}
		return $d;
	}
}
