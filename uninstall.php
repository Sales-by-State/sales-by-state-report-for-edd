<?php
/**
 * Removes the plugin's data when it is deleted.
 *
 * @package SalesByStateReportForEDD
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$sbsedd_options = array(
	'sbsedd_db_version',
	'sbsedd_backfill_cursor',
	'sbsedd_year_start',
);

foreach ( $sbsedd_options as $sbsedd_option ) {
	delete_option( $sbsedd_option );
}

if ( is_multisite() ) {
	foreach ( $sbsedd_options as $sbsedd_option ) {
		delete_site_option( $sbsedd_option );
	}
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sbsedd_order_state" );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'sbsedd_backfill_batch', array(), 'sales-by-state-report-for-edd' );
}
