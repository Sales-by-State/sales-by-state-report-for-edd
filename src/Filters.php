<?php
/**
 * The values the report's three filters can take.
 *
 * @package SalesByStateReportForEDD
 */

namespace SBSEDD;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the filter options and validates what comes back from the browser.
 */
class Filters {

	/**
	 * Option holding the first year offered by the year filter.
	 */
	const YEAR_START_OPTION = 'sbsedd_year_start';

	/**
	 * Number of years offered when the list is first created.
	 */
	const YEAR_WINDOW = 10;

	/**
	 * The columns shown in the report table and summary.
	 *
	 * @return array<string,array{label:string,type:string}>
	 */
	public static function measures() {
		return array(
			'net_revenue'   => array(
				'label' => __( 'Net Sales', 'sales-by-state-report-for-edd' ),
				'type'  => 'currency',
			),
			'gross_revenue' => array(
				'label' => __( 'Gross Sales', 'sales-by-state-report-for-edd' ),
				'type'  => 'currency',
			),
		);
	}

	/**
	 * Measure keys.
	 *
	 * @return string[]
	 */
	public static function measure_keys() {
		return array_keys( self::measures() );
	}

	/**
	 * Order statuses the filter offers.
	 *
	 * @return array<string,string>
	 */
	public static function order_statuses() {
		$statuses = function_exists( 'edd_get_payment_statuses' )
			? edd_get_payment_statuses()
			: array(
				'complete'           => __( 'Completed', 'sales-by-state-report-for-edd' ),
				'pending'            => __( 'Pending', 'sales-by-state-report-for-edd' ),
				'processing'         => __( 'Processing', 'sales-by-state-report-for-edd' ),
				'refunded'           => __( 'Refunded', 'sales-by-state-report-for-edd' ),
				'partially_refunded' => __( 'Partially Refunded', 'sales-by-state-report-for-edd' ),
				'failed'             => __( 'Failed', 'sales-by-state-report-for-edd' ),
				'abandoned'          => __( 'Abandoned', 'sales-by-state-report-for-edd' ),
				'on_hold'            => __( 'On Hold', 'sales-by-state-report-for-edd' ),
				'revoked'            => __( 'Revoked', 'sales-by-state-report-for-edd' ),
			);

		$skip    = array( 'trash' );
		$offered = array();

		foreach ( (array) $statuses as $key => $label ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key || in_array( $key, $skip, true ) ) {
				continue;
			}

			$offered[ $key ] = html_entity_decode( (string) $label, ENT_QUOTES, 'UTF-8' );
		}

		return $offered;
	}

	/**
	 * The statuses ticked when the report is opened with no explicit filter.
	 *
	 * @return string[]
	 */
	public static function default_statuses() {
		/**
		 * Filters the order statuses the report starts on.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $statuses Status keys.
		 */
		$statuses = (array) apply_filters( 'sbsedd_default_statuses', array( 'complete' ) );

		return array_values( array_intersect( $statuses, array_keys( self::order_statuses() ) ) );
	}

	/**
	 * Reduce a request value to statuses this report recognises.
	 *
	 * @param string|array $value Comma-separated list or array of statuses.
	 * @return string[]
	 */
	public static function normalize_statuses( $value ) {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$offered  = array_keys( self::order_statuses() );
		$accepted = array();

		foreach ( $value as $status ) {
			$status = sanitize_key( trim( (string) $status ) );

			if ( 'completed' === $status ) {
				$status = 'complete';
			}

			if ( in_array( $status, $offered, true ) ) {
				$accepted[] = $status;
			}
		}

		return array_values( array_unique( $accepted ) );
	}

	/**
	 * Years the filter offers, newest first.
	 *
	 * @return int[]
	 */
	public static function years() {
		$current  = self::default_year();
		$earliest = (int) get_option( self::YEAR_START_OPTION, 0 );

		if ( $earliest <= 0 ) {
			$earliest = $current - ( self::YEAR_WINDOW - 1 );
			update_option( self::YEAR_START_OPTION, $earliest, false );
		}

		if ( $earliest > $current ) {
			$earliest = $current;
		}

		return array_map( 'intval', range( $current, $earliest ) );
	}

	/**
	 * The year the report opens on.
	 *
	 * @return int
	 */
	public static function default_year() {
		return (int) current_time( 'Y' );
	}

	/**
	 * Reduce a request value to a year the filter offers.
	 *
	 * @param mixed $year Requested year.
	 * @return int
	 */
	public static function normalize_year( $year ) {
		$year = (int) $year;

		return in_array( $year, self::years(), true ) ? $year : self::default_year();
	}

	/**
	 * The country the report opens on.
	 *
	 * @return string Two-letter country code.
	 */
	public static function default_country() {
		$code = 'US';

		if ( function_exists( 'edd_get_option' ) ) {
			$base = strtoupper( (string) edd_get_option( 'base_country', 'US' ) );

			if ( preg_match( '/^[A-Z]{2}$/', $base ) ) {
				$code = $base;
			}
		}

		if ( self::states_for( $code ) ) {
			return $code;
		}

		foreach ( array_keys( self::countries_with_states() ) as $candidate ) {
			return $candidate;
		}

		return $code;
	}

	/**
	 * Reduce a request value to a two-letter country code.
	 *
	 * @param mixed $country Requested country.
	 * @return string
	 */
	public static function normalize_country( $country ) {
		$country = strtoupper( (string) $country );

		return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : self::default_country();
	}

	/**
	 * Countries the country dropdown always offers.
	 *
	 * @return array<string,string>
	 */
	public static function countries_with_states() {
		$labels = array(
			'US' => __( 'United States', 'sales-by-state-report-for-edd' ),
			'CA' => __( 'Canada', 'sales-by-state-report-for-edd' ),
			'GB' => __( 'United Kingdom', 'sales-by-state-report-for-edd' ),
		);

		if ( function_exists( 'edd_get_country_list' ) ) {
			$edd = edd_get_country_list();

			foreach ( $labels as $code => $fallback ) {
				if ( ! empty( $edd[ $code ] ) ) {
					$labels[ $code ] = html_entity_decode( (string) $edd[ $code ], ENT_QUOTES, 'UTF-8' );
				}
			}
		}

		return $labels;
	}

	/**
	 * State code => name for a country, from EDD's shop states.
	 *
	 * @param string $country Country code.
	 * @return array<string,string>
	 */
	public static function states_for( $country ) {
		$country = strtoupper( (string) $country );
		$states  = array();

		if ( function_exists( 'edd_get_shop_states' ) ) {
			$states = edd_get_shop_states( $country );
		}

		if ( ! is_array( $states ) ) {
			return array();
		}

		$out = array();

		foreach ( $states as $code => $label ) {
			$code = (string) $code;

			if ( '' === $code ) {
				continue;
			}

			$out[ $code ] = html_entity_decode( (string) $label, ENT_QUOTES, 'UTF-8' );
		}

		return $out;
	}
}
