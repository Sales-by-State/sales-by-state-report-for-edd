<?php
/**
 * Locates Easy Digital Downloads sale orders.
 *
 * @package SalesByStateReportForEDD
 */

namespace SBSEDD\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Reads order IDs from EDD's orders table.
 *
 * Refund records (`type = refund`) are skipped. Table names are written as
 * literals so every identifier in the SQL is fixed.
 */
class OrderSource {

	/**
	 * Total number of sale orders on the site.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}edd_orders WHERE type = 'sale'" );
	}

	/**
	 * Number of sale orders above a cursor.
	 *
	 * @param int $cursor Highest order ID already processed.
	 * @return int
	 */
	public static function count_after( $cursor ) {
		global $wpdb;

		$cursor = max( 0, (int) $cursor );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}edd_orders WHERE type = 'sale' AND id > %d",
				$cursor
			)
		);
	}

	/**
	 * The next batch of sale order IDs after a cursor.
	 *
	 * @param int $cursor Highest order ID already processed.
	 * @param int $limit  Batch size.
	 * @return int[]
	 */
	public static function ids_after( $cursor, $limit ) {
		global $wpdb;

		$cursor = max( 0, (int) $cursor );
		$limit  = max( 1, (int) $limit );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}edd_orders
				 WHERE type = 'sale'
				   AND id > %d
				 ORDER BY id ASC
				 LIMIT %d",
				$cursor,
				$limit
			)
		);

		return array_map( 'intval', (array) $ids );
	}
}
