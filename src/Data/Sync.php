<?php
/**
 * Keeps the report table in step with Easy Digital Downloads orders.
 *
 * @package SalesByStateReportForEDD
 */

namespace SBSEDD\Data;

use SBSEDD\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Writes one row per sale order.
 */
class Sync {

	/**
	 * Register hooks.
	 *
	 * BerlinDB fires `edd_order_added`, `edd_order_updated`, and `edd_order_deleted`.
	 * Address changes and status transitions also rewrite the row.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'edd_order_added', array( $this, 'on_order' ), 20, 1 );
		add_action( 'edd_order_updated', array( $this, 'on_order' ), 20, 1 );
		add_action( 'edd_order_deleted', array( $this, 'on_delete' ), 20, 1 );
		add_action( 'edd_order_destroyed', array( $this, 'on_delete' ), 20, 1 );
		add_action( 'edd_transition_order_status', array( $this, 'on_status' ), 20, 3 );
		add_action( 'edd_order_address_added', array( $this, 'on_address' ), 20, 2 );
		add_action( 'edd_order_address_updated', array( $this, 'on_address' ), 20, 2 );
		add_action( 'edd_order_address_deleted', array( $this, 'on_address_deleted' ), 20, 1 );
	}

	/**
	 * Handle an order ID from an add/update hook.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function on_order( $order_id ) {
		$this->upsert( (int) $order_id );
	}

	/**
	 * Handle a status change.
	 *
	 * @param string $old_status Previous status.
	 * @param string $new_status New status.
	 * @param int    $order_id   Order ID.
	 * @return void
	 */
	public function on_status( $old_status, $new_status, $order_id ) {
		unset( $old_status, $new_status );
		$this->upsert( (int) $order_id );
	}

	/**
	 * Handle an address write.
	 *
	 * @param int   $address_id Address ID.
	 * @param array $data       Address data.
	 * @return void
	 */
	public function on_address( $address_id, $data = array() ) {
		$order_id = isset( $data['order_id'] ) ? (int) $data['order_id'] : 0;

		if ( ! $order_id && function_exists( 'edd_get_order_address' ) ) {
			$address  = edd_get_order_address( (int) $address_id );
			$order_id = $address && ! empty( $address->order_id ) ? (int) $address->order_id : 0;
		}

		if ( $order_id ) {
			$this->upsert( $order_id );
		}
	}

	/**
	 * Address deletions do not include the order ID. Reload from remaining addresses
	 * is not possible after delete, so skip unless the hook payload carries an ID.
	 *
	 * @param int $address_id Address ID.
	 * @return void
	 */
	public function on_address_deleted( $address_id ) {
		unset( $address_id );
	}

	/**
	 * Remove the row when an order is deleted.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function on_delete( $order_id ) {
		$this->delete( (int) $order_id );
	}

	/**
	 * Insert or update the row for one order.
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	public function upsert( $order_id ) {
		global $wpdb;

		$order_id = (int) $order_id;

		if ( ! $order_id ) {
			return false;
		}

		$row = self::build_row( $order_id );

		if ( ! $row ) {
			$this->delete( $order_id );
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->replace(
			Schema::table(),
			$row,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f' )
		);
	}

	/**
	 * Remove the row for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function delete( $order_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Schema::table(), array( 'order_id' => (int) $order_id ), array( '%d' ) );
	}

	/**
	 * Build the row for an order from EDD tables.
	 *
	 * EDD stores money as decimals. The report groups by billing region because
	 * downloads are digital and do not carry a shipping address.
	 *
	 * @param int $order_id Order ID.
	 * @return array<string,mixed>|false
	 */
	public static function build_row( $order_id ) {
		global $wpdb;

		$order_id = (int) $order_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$order = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT o.id, o.status, o.type, o.currency, o.total, o.tax,
				        o.date_created, o.date_completed,
				        billing.country AS billing_country,
				        billing.region AS billing_state
				 FROM {$wpdb->prefix}edd_orders o
				 LEFT JOIN {$wpdb->prefix}edd_order_addresses billing
				        ON billing.order_id = o.id AND billing.type = %s
				 WHERE o.id = %d",
				'billing',
				$order_id
			)
		);

		if ( ! $order || 'sale' !== $order->type ) {
			return false;
		}

		$billing_country = strtoupper( substr( (string) $order->billing_country, 0, 2 ) );
		$billing_state   = (string) $order->billing_state;
		$total           = round( (float) $order->total, 2 );
		$tax             = round( (float) $order->tax, 2 );
		$created         = self::normalize_datetime( $order->date_created );
		$paid            = self::normalize_datetime( $order->date_completed );

		return array(
			'order_id'         => (int) $order->id,
			'status'           => substr( sanitize_key( (string) $order->status ), 0, 32 ),
			'date_created'     => $created ? $created : '0000-00-00 00:00:00',
			'date_paid'        => $paid ? $paid : null,
			'billing_country'  => $billing_country,
			'billing_state'    => substr( $billing_state, 0, 50 ),
			'shipping_country' => $billing_country,
			'shipping_state'   => substr( $billing_state, 0, 50 ),
			'currency'         => strtoupper( substr( (string) $order->currency, 0, 3 ) ),
			'total_sales'      => $total,
			'tax_total'        => $tax,
			'shipping_total'   => 0,
			'net_total'        => $total - $tax,
		);
	}

	/**
	 * Normalise a datetime string.
	 *
	 * @param mixed $value Datetime.
	 * @return string|null
	 */
	private static function normalize_datetime( $value ) {
		$value = (string) $value;

		if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
			return null;
		}

		$ts = strtotime( $value );

		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
	}
}
