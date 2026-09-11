<?php
/**
 * Plugin Name:          Sales by State Report for Easy Digital Downloads (EDD)
 * Plugin URI:           https://salesbystate.com/
 * Description:          See a yearly breakdown of Easy Digital Downloads sales by state / county / province for a given country, filterable by order status.
 * Version:              1.0.0
 * Author:               Rodolfo Melogli
 * Author URI:           https://www.businessbloomer.com/
 * Developer:            Rodolfo Melogli
 * Developer URI:        https://www.businessbloomer.com/
 * Text Domain:          sales-by-state-report-for-edd
 * Domain Path:          /languages
 * Requires at least:    6.4
 * Requires PHP:         7.4
 * Requires Plugins:     easy-digital-downloads
 * EDD requires at least: 3.0
 * EDD tested up to:     3.7.0
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package SalesByStateReportForEDD
 * @copyright 2026 Rodolfo Melogli
 */

defined( 'ABSPATH' ) || exit;

define( 'SBSEDD_VERSION', '1.0.0' );
define( 'SBSEDD_FILE', __FILE__ );
define( 'SBSEDD_DIR', plugin_dir_path( __FILE__ ) );
define( 'SBSEDD_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'SBSEDD\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = SBSEDD_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! defined( 'EDD_VERSION' ) && ! function_exists( 'EDD' ) ) {
			add_action(
				'admin_notices',
				function () {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}

					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'Sales by State Report for Easy Digital Downloads requires Easy Digital Downloads to be installed and active.', 'sales-by-state-report-for-edd' )
					);
				}
			);

			return;
		}

		SBSEDD\Plugin::instance()->init();
	},
	20
);

register_activation_hook(
	SBSEDD_FILE,
	function () {
		require_once SBSEDD_DIR . 'src/Install/Schema.php';
		SBSEDD\Install\Schema::install();
	}
);

register_deactivation_hook(
	SBSEDD_FILE,
	function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'sbsedd_backfill_batch', array(), 'sales-by-state-report-for-edd' );
		}
	}
);
