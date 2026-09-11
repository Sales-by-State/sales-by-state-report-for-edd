<?php
/**
 * Site Health and Downloads → Tools entries for the report table.
 *
 * @package SalesByStateReportForEDD
 */

namespace SBSEDD\Admin;

use SBSEDD\Data\Backfill;
use SBSEDD\Install\Schema;
use SBSEDD\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a Site Health section and an EDD Tools tab.
 */
class Tools {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'debug_information', array( $this, 'debug_information' ) );
		add_filter( 'edd_tools_tabs', array( $this, 'tools_tab' ) );
		add_action( 'edd_tools_tab_sbsedd', array( $this, 'render_tools_tab' ) );
		add_action( 'edd_sbsedd_backfill', array( $this, 'handle_backfill' ) );
		add_action( 'edd_sbsedd_rebuild', array( $this, 'handle_rebuild' ) );
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
	}

	/**
	 * Add the tab under Downloads → Tools.
	 *
	 * @param array $tabs Existing tabs.
	 * @return array
	 */
	public function tools_tab( $tabs ) {
		if ( ! Plugin::can_manage() ) {
			return $tabs;
		}

		$tabs['sbsedd'] = __( 'Sales by State', 'sales-by-state-report-for-edd' );

		return $tabs;
	}

	/**
	 * Render the EDD Tools tab.
	 *
	 * @return void
	 */
	public function render_tools_tab() {
		if ( ! Plugin::can_manage() ) {
			return;
		}

		$counts    = Schema::counts();
		$remaining = Backfill::remaining();
		$url       = function_exists( 'edd_get_admin_url' )
			? edd_get_admin_url(
				array(
					'page' => 'edd-tools',
					'tab'  => 'sbsedd',
				)
			)
			: admin_url( 'edit.php?post_type=download&page=edd-tools&tab=sbsedd' );
		?>
		<div class="postbox">
			<h3><span><?php esc_html_e( 'Build report table', 'sales-by-state-report-for-edd' ); ?></span></h3>
			<div class="inside">
				<p>
					<?php
					printf(
						/* translators: 1: rows in the report table, 2: total sale orders. */
						esc_html__( 'Reads existing Easy Digital Downloads orders into the report table. %1$s of %2$s done.', 'sales-by-state-report-for-edd' ),
						esc_html( number_format_i18n( $counts['rows'] ) ),
						esc_html( number_format_i18n( $counts['orders'] ) )
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( $url ); ?>">
					<input type="hidden" name="edd_action" value="sbsedd_backfill" />
					<?php wp_nonce_field( 'sbsedd_tools', 'sbsedd_tools_nonce' ); ?>
					<?php
					submit_button(
						$remaining > 0
							/* translators: %s: number of orders left. */
							? sprintf( __( 'Process %s remaining', 'sales-by-state-report-for-edd' ), number_format_i18n( $remaining ) )
							: __( 'Run', 'sales-by-state-report-for-edd' ),
						'secondary',
						'submit',
						false
					);
					?>
				</form>
			</div>
		</div>
		<div class="postbox">
			<h3><span><?php esc_html_e( 'Rebuild from scratch', 'sales-by-state-report-for-edd' ); ?></span></h3>
			<div class="inside">
				<p><?php esc_html_e( 'Empties the report table and starts again from the first order. Use this if the figures look wrong rather than merely incomplete.', 'sales-by-state-report-for-edd' ); ?></p>
				<form method="post" action="<?php echo esc_url( $url ); ?>">
					<input type="hidden" name="edd_action" value="sbsedd_rebuild" />
					<?php wp_nonce_field( 'sbsedd_tools', 'sbsedd_tools_nonce' ); ?>
					<?php submit_button( __( 'Rebuild', 'sales-by-state-report-for-edd' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<div class="postbox">
			<h3><span><?php esc_html_e( 'Data check', 'sales-by-state-report-for-edd' ); ?></span></h3>
			<div class="inside">
				<p><?php echo esc_html( $this->data_check_text() ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Process one backfill batch from the Tools tab.
	 *
	 * @return void
	 */
	public function handle_backfill() {
		if ( ! $this->verify_tools_request() ) {
			return;
		}

		$result = Backfill::run_batch( 1000 );
		set_transient( 'sbsedd_tools_notice', $this->backfill_message( $result ), 30 );
		wp_safe_redirect( $this->tools_url() );
		exit;
	}

	/**
	 * Empty the table and process the first batch.
	 *
	 * @return void
	 */
	public function handle_rebuild() {
		if ( ! $this->verify_tools_request() ) {
			return;
		}

		Backfill::reset();
		$result = Backfill::run_batch( 1000 );
		set_transient(
			'sbsedd_tools_notice',
			sprintf(
				/* translators: 1: orders processed, 2: orders remaining. */
				__( 'Table emptied. Processed %1$s orders, %2$s remaining.', 'sales-by-state-report-for-edd' ),
				number_format_i18n( $result['processed'] ),
				number_format_i18n( $result['remaining'] )
			),
			30
		);
		wp_safe_redirect( $this->tools_url() );
		exit;
	}

	/**
	 * Show a notice after a Tools action.
	 *
	 * @return void
	 */
	public function admin_notice() {
		$notice = get_transient( 'sbsedd_tools_notice' );

		if ( ! $notice ) {
			return;
		}

		delete_transient( 'sbsedd_tools_notice' );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( $notice )
		);
	}

	/**
	 * Add a Site Health section.
	 *
	 * @param array $info Existing debug information.
	 * @return array
	 */
	public function debug_information( $info ) {
		if ( ! Plugin::can_view() ) {
			return $info;
		}

		$info['sbsedd-sales-by-state'] = array(
			'label'  => __( 'Sales by State Report for Easy Digital Downloads', 'sales-by-state-report-for-edd' ),
			'fields' => $this->fields(),
		);

		return $info;
	}

	/**
	 * Field list for Site Health.
	 *
	 * @return array
	 */
	private function fields() {
		global $wpdb;

		if ( ! Schema::table_exists() ) {
			return array(
				'table' => array(
					'label' => __( 'Report table', 'sales-by-state-report-for-edd' ),
					'value' => __( 'Missing', 'sales-by-state-report-for-edd' ),
				),
			);
		}

		$counts    = Schema::counts();
		$remaining = Backfill::remaining();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$by_status = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$wpdb->prefix}sbsedd_order_state GROUP BY status ORDER BY total DESC", ARRAY_A );
		$countries = $wpdb->get_results( "SELECT billing_country AS country, COUNT(*) AS total FROM {$wpdb->prefix}sbsedd_order_state GROUP BY billing_country ORDER BY total DESC", ARRAY_A );
		$no_state  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sbsedd_order_state WHERE billing_state = ''" );
		// phpcs:enable

		$fields = array(
			'rows'      => array(
				'label' => __( 'Rows in report table', 'sales-by-state-report-for-edd' ),
				'value' => number_format_i18n( $counts['rows'] ),
			),
			'orders'    => array(
				'label' => __( 'Sale orders in Easy Digital Downloads', 'sales-by-state-report-for-edd' ),
				'value' => number_format_i18n( $counts['orders'] ),
			),
			'remaining' => array(
				'label' => __( 'Orders still to import', 'sales-by-state-report-for-edd' ),
				'value' => number_format_i18n( $remaining ),
			),
		);

		if ( $no_state > 0 ) {
			$fields['no_state'] = array(
				'label' => __( 'Rows with no state', 'sales-by-state-report-for-edd' ),
				'value' => number_format_i18n( $no_state ),
			);
		}

		$bits = array();

		foreach ( (array) $by_status as $row ) {
			$bits[] = $row['status'] . ': ' . number_format_i18n( (int) $row['total'] );
		}

		if ( $bits ) {
			$fields['by_status'] = array(
				'label' => __( 'By status', 'sales-by-state-report-for-edd' ),
				'value' => implode( ', ', $bits ),
			);
		}

		$bits = array();

		foreach ( (array) $countries as $row ) {
			$code   = '' === $row['country'] ? __( '(blank)', 'sales-by-state-report-for-edd' ) : $row['country'];
			$bits[] = $code . ': ' . number_format_i18n( (int) $row['total'] );
		}

		if ( $bits ) {
			$fields['countries'] = array(
				'label' => __( 'Countries', 'sales-by-state-report-for-edd' ),
				'value' => implode( ', ', $bits ),
			);
		}

		return $fields;
	}

	/**
	 * Read-only summary for the Tools tab.
	 *
	 * @return string
	 */
	private function data_check_text() {
		$fields = $this->fields();
		$bits   = array();

		foreach ( $fields as $field ) {
			$bits[] = $field['label'] . ': ' . $field['value'];
		}

		return implode( ' — ', $bits );
	}

	/**
	 * Message after a backfill batch.
	 *
	 * @param array $result Batch result.
	 * @return string
	 */
	private function backfill_message( array $result ) {
		if ( empty( $result['complete'] ) ) {
			return sprintf(
				/* translators: 1: orders processed, 2: orders remaining. */
				__( 'Processed %1$s orders. %2$s still to go — run the tool again, or leave Downloads → Sales by State open.', 'sales-by-state-report-for-edd' ),
				number_format_i18n( $result['processed'] ),
				number_format_i18n( $result['remaining'] )
			);
		}

		return sprintf(
			/* translators: %s: orders processed. */
			__( 'Processed %s orders. The report table is complete.', 'sales-by-state-report-for-edd' ),
			number_format_i18n( $result['processed'] )
		);
	}

	/**
	 * Capability and nonce check for Tools POSTs.
	 *
	 * @return bool
	 */
	private function verify_tools_request() {
		if ( ! Plugin::can_manage() ) {
			return false;
		}

		check_admin_referer( 'sbsedd_tools', 'sbsedd_tools_nonce' );

		return true;
	}

	/**
	 * Tools tab URL.
	 *
	 * @return string
	 */
	private function tools_url() {
		if ( function_exists( 'edd_get_admin_url' ) ) {
			return edd_get_admin_url(
				array(
					'page' => 'edd-tools',
					'tab'  => 'sbsedd',
				)
			);
		}

		return admin_url( 'edit.php?post_type=download&page=edd-tools&tab=sbsedd' );
	}
}
