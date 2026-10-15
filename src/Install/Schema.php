<?php
/**
 * Report table schema.
 *
 * @package SalesByStateReportForEDD
 */

namespace SBSEDD\Install;

use SBSEDD\Data\OrderSource;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and versions the report table.
 */
class Schema {

	const DB_VERSION     = 1;
	const VERSION_OPTION = 'sbsedd_db_version';

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'sbsedd_order_state';
	}

	/**
	 * Install the table if the schema version has moved.
	 *
	 * Runs on every request, so the common case (installed and up to date)
	 * must cost nothing: the version option is autoloaded and read from the
	 * options WordPress already has in memory. The install, which also
	 * restarts the import when the table is empty, only runs when that
	 * version is missing or different: on first load, on an upgrade, or once
	 * on sites where 1.0.x stored the option without autoload.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( self::is_current() ) {
			return;
		}

		self::install();
	}

	/**
	 * Whether the installed schema matches this build, without a query.
	 *
	 * An option stored without autoload is not in the loaded list, so it
	 * counts as "not current" once, and install() then switches its autoload
	 * on.
	 *
	 * @return bool
	 */
	private static function is_current() {
		$alloptions = wp_load_alloptions();

		if ( ! isset( $alloptions[ self::VERSION_OPTION ] ) ) {
			return false;
		}

		return self::DB_VERSION === (int) $alloptions[ self::VERSION_OPTION ];
	}

	/**
	 * Create or migrate the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->has_cap( 'collation' ) ? $wpdb->get_charset_collate() : '';

		$sql = "CREATE TABLE {$wpdb->prefix}sbsedd_order_state (
			order_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(32) NOT NULL DEFAULT '',
			date_created DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			date_paid DATETIME NULL DEFAULT NULL,
			billing_country CHAR(2) NOT NULL DEFAULT '',
			billing_state VARCHAR(50) NOT NULL DEFAULT '',
			shipping_country CHAR(2) NOT NULL DEFAULT '',
			shipping_state VARCHAR(50) NOT NULL DEFAULT '',
			currency CHAR(3) NOT NULL DEFAULT '',
			total_sales DECIMAL(26,8) NOT NULL DEFAULT 0,
			tax_total DECIMAL(26,8) NOT NULL DEFAULT 0,
			shipping_total DECIMAL(26,8) NOT NULL DEFAULT 0,
			net_total DECIMAL(26,8) NOT NULL DEFAULT 0,
			PRIMARY KEY (order_id),
			KEY shipping (shipping_country, shipping_state, status),
			KEY billing (billing_country, billing_state, status),
			KEY paid (date_paid),
			KEY created (date_created)
		) {$collate};";

		dbDelta( $sql );

		if ( ! self::table_exists() ) {
			return;
		}

		$counts = self::counts();

		if ( 0 === $counts['rows'] ) {
			delete_option( 'sbsedd_backfill_cursor' );
		}

		update_option( self::VERSION_OPTION, self::DB_VERSION, true );

		// update_option() leaves autoload alone when the value is unchanged.
		wp_set_option_autoload( self::VERSION_OPTION, true );
	}

	/**
	 * Whether the report table exists.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;

		// Only a positive answer is remembered, so a check made before
		// install() creates the table never sticks.
		static $exists = false;

		if ( $exists ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'sbsedd_order_state' )
		);

		return $exists;
	}

	/**
	 * Row count alongside the number of sale orders on the site.
	 *
	 * @return array{rows:int,orders:int}
	 */
	public static function counts() {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array(
				'rows'   => 0,
				'orders' => OrderSource::count(),
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sbsedd_order_state" );

		return array(
			'rows'   => $rows,
			'orders' => OrderSource::count(),
		);
	}

	/**
	 * Empty the table.
	 *
	 * @return void
	 */
	public static function truncate() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}sbsedd_order_state" );
	}
}
