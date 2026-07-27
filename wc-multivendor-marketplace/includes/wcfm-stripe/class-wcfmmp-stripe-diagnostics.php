<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}










class WCFMmp_Stripe_Diagnostics {

	const NONCE_ACTION = 'wcfmmp-stripe-diagnostics';

	


	public static function init() {
		add_action( 'begin_wcfm_settings_form_style', array( __CLASS__, 'render_panel' ), 15 );
		add_action( 'wp_ajax_wcfmmp_stripe_refresh_vendor_caches', array( __CLASS__, 'ajax_refresh' ) );
	}

	 

	




	public static function connected_vendors() {
		return get_users( array(
			'meta_key'     => 'stripe_user_id',
			'meta_compare' => 'EXISTS',
			'fields'       => 'ID',
			'number'       => 200,
		) );
	}

	





	public static function vendor_row( $vendor_id ) {
		$vendor_id    = (int) $vendor_id;
		$currency     = get_woocommerce_currency();
		$capabilities = get_user_meta( $vendor_id, 'stripe_account_capabilities', true );
		if ( is_object( $capabilities ) ) {
			 
			 
			$capabilities = json_decode( wp_json_encode( $capabilities ) );
		}
		$summary = array();
		if ( is_object( $capabilities ) ) {
			foreach ( get_object_vars( $capabilities ) as $capability => $state ) {
				if ( is_scalar( $state ) ) {
					$summary[] = $capability . ':' . $state;
				}
			}
		}

		$sct    = class_exists( 'WCFMmp_Stripe_Preflight' ) ? WCFMmp_Stripe_Preflight::validate_vendor( $vendor_id, $currency, 'transfers_charges' ) : array( 'pass' => true, 'reason' => 'n/a' );
		$direct = class_exists( 'WCFMmp_Stripe_Preflight' ) ? WCFMmp_Stripe_Preflight::validate_vendor( $vendor_id, $currency, 'direct_charges' ) : array( 'pass' => true, 'reason' => 'n/a' );

		$account = (string) get_user_meta( $vendor_id, 'stripe_user_id', true );
		$updated = (int) get_user_meta( $vendor_id, 'stripe_account_updated', true );

		return array(
			'vendor_id'       => $vendor_id,
			'store'           => function_exists( 'wcfm_get_vendor_store_name' ) ? wcfm_get_vendor_store_name( $vendor_id ) : ( '#' . $vendor_id ),
			'account'         => $account ? substr( $account, 0, 12 ) . '…' : '—',
			'country'         => (string) get_user_meta( $vendor_id, 'stripe_account_country', true ),
			'payouts'         => (string) get_user_meta( $vendor_id, 'stripe_payouts_enabled', true ),
			'charges'         => (string) get_user_meta( $vendor_id, 'stripe_charges_enabled', true ),
			'agreement'       => (string) get_user_meta( $vendor_id, 'stripe_service_agreement', true ),
			'capabilities'    => $summary ? implode( ', ', $summary ) : '—',
			'sct_verdict'     => $sct,
			'direct_verdict'  => $direct,
			'updated'         => $updated,
		);
	}

	




	public static function held_withdrawals() {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT w.ID, w.vendor_id, w.order_ids, w.created, wm.`value` AS held_reason
			 FROM {$wpdb->prefix}wcfm_marketplace_withdraw_request w
			 INNER JOIN {$wpdb->prefix}wcfm_marketplace_withdraw_request_meta wm ON wm.withdraw_id = w.ID AND wm.`key` = 'held_reason'
			 WHERE w.withdraw_status = 'pending'
			 ORDER BY w.ID DESC LIMIT 100"
		);
	}

	 

	


	public static function render_panel() {
		global $WCFMmp;

		if ( ! apply_filters( 'wcfm_is_allow_withdrawal_manage', true ) ) {
			return;
		}
		$options = get_option( 'wcfm_withdrawal_options', array() );
		$methods = isset( $options['payment_methods'] ) ? (array) $options['payment_methods'] : array();
		if ( ! in_array( 'stripe_split', $methods, true ) ) {
			return;
		}

		$engine   = function_exists( 'wcfmmp_stripe_split_engine' ) ? wcfmmp_stripe_split_engine() : 'legacy';
		$platform = class_exists( 'WCFMmp_Stripe_Preflight' ) ? WCFMmp_Stripe_Preflight::platform_cache() : array();

		$sdk_version = '—';
		$sdk_path    = __( 'not loaded on this request', 'wc-multivendor-marketplace' );
		if ( class_exists( '\Stripe\Stripe' ) ) {
			$sdk_reflection = new ReflectionClass( '\Stripe\Stripe' );
			$sdk_version    = \Stripe\Stripe::VERSION;
			$sdk_path       = $sdk_reflection->getFileName();
		}

		$registration = get_option( WCFMmp_Stripe_Webhook_Handler::REGISTRATION_OPTION, array() );
		$registration = is_array( $registration ) ? $registration : array();
		$test         = isset( $options['test_mode'] );
		$webhook_id   = $test ? ( isset( $registration['id_test'] ) ? $registration['id_test'] : '' ) : ( isset( $registration['id'] ) ? $registration['id'] : '' );
		$last_hook    = (int) get_option( WCFMmp_Stripe_Webhook_Handler::LAST_RECEIVED, 0 );

		?>
		<!-- collapsible -->
		<div class="page_collapsible" id="wcfm_settings_form_stripe_split_status_head">
			<label class="wcfmfa fa-heartbeat" style="font-size:18px;"></label>
			<?php esc_html_e( 'Stripe Split Pay Status', 'wc-multivendor-marketplace' ); ?><span></span>
		</div>
		<div class="wcfm-container">
			<div id="wcfm_settings_form_stripe_split_status_expander" class="wcfm-content">
				<h2><?php esc_html_e( 'Stripe Split Pay Status', 'wc-multivendor-marketplace' ); ?></h2>
				<div class="wcfm_clearfix"></div>

				<table class="widefat striped" style="margin-bottom:15px;">
					<tbody>
						<tr><td><strong><?php esc_html_e( 'Payment engine', 'wc-multivendor-marketplace' ); ?></strong></td><td><?php
							echo esc_html( $engine );
							$forced_source = class_exists( 'WCFMmp_Stripe_Environment' ) ? WCFMmp_Stripe_Environment::forced_legacy_source() : '';
							if ( $forced_source ) {
								$forced_labels = array(
									'sdk_floor' => __( 'forced to legacy: loaded stripe-php below the modern floor', 'wc-multivendor-marketplace' ),
									'constant'  => __( 'forced to legacy: WCFMMP_STRIPE_FORCE_LEGACY constant', 'wc-multivendor-marketplace' ),
									'filter'    => __( 'forced to legacy: wcfmmp_stripe_force_legacy_engine filter', 'wc-multivendor-marketplace' ),
								);
								echo ' <span style="color:#b32d2e;">(' . esc_html( $forced_labels[ $forced_source ] ) . ')</span>';
							}
						?></td></tr>
						<tr><td><strong><?php esc_html_e( 'Loaded stripe-php', 'wc-multivendor-marketplace' ); ?></strong></td><td><?php echo esc_html( $sdk_version ); ?> <code style="font-size:11px;"><?php echo esc_html( $sdk_path ); ?></code></td></tr>
						<tr><td><strong><?php esc_html_e( 'Pinned API version', 'wc-multivendor-marketplace' ); ?></strong></td><td><?php echo esc_html( WCFMmp_Stripe_Client_Factory::API_VERSION ); ?></td></tr>
						<tr><td><strong><?php esc_html_e( 'Webhook endpoint', 'wc-multivendor-marketplace' ); ?></strong></td><td><?php echo $webhook_id ? esc_html( $webhook_id ) : esc_html__( 'not registered', 'wc-multivendor-marketplace' ); ?></td></tr>
						<tr><td><strong><?php esc_html_e( 'Last webhook received', 'wc-multivendor-marketplace' ); ?></strong></td><td><?php echo $last_hook ? esc_html( human_time_diff( $last_hook ) . ' ' . __( 'ago', 'wc-multivendor-marketplace' ) ) : '—'; ?></td></tr>
						<tr><td><strong><?php esc_html_e( 'Platform account', 'wc-multivendor-marketplace' ); ?></strong></td><td><?php echo isset( $platform['country'] ) && $platform['country'] ? esc_html( $platform['country'] . ' / ' . $platform['currency'] ) : esc_html__( 'not cached yet — save settings or refresh below', 'wc-multivendor-marketplace' ); ?></td></tr>
					</tbody>
				</table>

				<h3><?php esc_html_e( 'Connected vendors', 'wc-multivendor-marketplace' ); ?></h3>
				<table class="widefat striped" style="margin-bottom:15px;">
					<thead><tr>
						<th><?php esc_html_e( 'Store', 'wc-multivendor-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Account', 'wc-multivendor-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Country', 'wc-multivendor-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Payouts', 'wc-multivendor-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Charges', 'wc-multivendor-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Agreement', 'wc-multivendor-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Capabilities', 'wc-multivendor-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Transfers verdict', 'wc-multivendor-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Direct verdict', 'wc-multivendor-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Updated', 'wc-multivendor-marketplace' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( self::connected_vendors() as $vendor_id ) : $row = self::vendor_row( $vendor_id ); ?>
						<tr>
							<td><?php echo esc_html( $row['store'] ); ?></td>
							<td><code><?php echo esc_html( $row['account'] ); ?></code></td>
							<td><?php echo esc_html( $row['country'] ? $row['country'] : '—' ); ?></td>
							<td><?php echo esc_html( $row['payouts'] ? $row['payouts'] : '—' ); ?></td>
							<td><?php echo esc_html( $row['charges'] ? $row['charges'] : '—' ); ?></td>
							<td><?php echo esc_html( $row['agreement'] ? $row['agreement'] : '—' ); ?></td>
							<td style="font-size:11px;"><?php echo esc_html( $row['capabilities'] ); ?></td>
							<td><?php echo $row['sct_verdict']['pass'] ? '<span style="color:green;">&#10004;</span>' : '<span style="color:#b32d2e;">&#10008; ' . esc_html( $row['sct_verdict']['reason'] ) . '</span>'; ?></td>
							<td><?php echo $row['direct_verdict']['pass'] ? '<span style="color:green;">&#10004;</span>' : '<span style="color:#b32d2e;">&#10008; ' . esc_html( $row['direct_verdict']['reason'] ) . '</span>'; ?></td>
							<td><?php echo $row['updated'] ? esc_html( human_time_diff( $row['updated'] ) . ' ' . __( 'ago', 'wc-multivendor-marketplace' ) ) : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<h3><?php esc_html_e( 'Held funds', 'wc-multivendor-marketplace' ); ?></h3>
				<table class="widefat striped" style="margin-bottom:15px;">
					<thead><tr>
						<th><?php esc_html_e( 'Order', 'wc-multivendor-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Store', 'wc-multivendor-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Reason', 'wc-multivendor-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Age', 'wc-multivendor-marketplace' ); ?></th>
					</tr></thead>
					<tbody>
					<?php $held_rows = self::held_withdrawals(); ?>
					<?php if ( empty( $held_rows ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No held funds.', 'wc-multivendor-marketplace' ); ?></td></tr>
					<?php else : foreach ( $held_rows as $held ) : $held_order_id = (int) $held->order_ids; ?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_post_link( $held_order_id ) ); ?>">#<?php echo esc_html( $held_order_id ); ?></a></td>
							<td><?php echo esc_html( function_exists( 'wcfm_get_vendor_store_name' ) ? wcfm_get_vendor_store_name( (int) $held->vendor_id ) : ( '#' . $held->vendor_id ) ); ?></td>
							<td><code><?php echo esc_html( $held->held_reason ); ?></code></td>
							<td><?php echo esc_html( human_time_diff( strtotime( $held->created ) ) . ' ' . __( 'ago', 'wc-multivendor-marketplace' ) ); ?></td>
						</tr>
					<?php endforeach; endif; ?>
					</tbody>
				</table>

				<p>
					<button type="button" class="wcfm_submit_button" id="wcfmmp_stripe_refresh_caches"><?php esc_html_e( 'Refresh vendor caches', 'wc-multivendor-marketplace' ); ?></button>
					<span id="wcfmmp_stripe_refresh_result" style="margin-left:8px;"></span>
				</p>
				<script type="text/javascript">
				jQuery( function( $ ) {
					$( '#wcfmmp_stripe_refresh_caches' ).on( 'click', function() {
						var $button = $( this ).prop( 'disabled', true );
						$.post( ajaxurl, {
							action: 'wcfmmp_stripe_refresh_vendor_caches',
							nonce:  '<?php echo esc_js( wp_create_nonce( self::NONCE_ACTION ) ); ?>'
						} ).done( function( response ) {
							if ( response && response.success ) {
								$( '#wcfmmp_stripe_refresh_result' ).text( response.data.refreshed + ' refreshed, ' + response.data.failed + ' failed' );
								window.location.reload();
							} else {
								$( '#wcfmmp_stripe_refresh_result' ).text( response && response.data ? response.data : 'error' );
								$button.prop( 'disabled', false );
							}
						} ).fail( function() {
							$( '#wcfmmp_stripe_refresh_result' ).text( 'error' );
							$button.prop( 'disabled', false );
						} );
					} );
				} );
				</script>
				<div class="wcfm_clearfix"></div>
			</div>
		</div>
		<?php
	}

	 

	



	public static function ajax_refresh() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'You are not allowed to do this.', 'wc-multivendor-marketplace' ), 403 );
		}
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), self::NONCE_ACTION ) ) {
			wp_send_json_error( __( 'Security check failed. Reload the page and try again.', 'wc-multivendor-marketplace' ), 403 );
		}

		wp_send_json_success( self::refresh_all() );
	}

	





	public static function refresh_all() {
		$refreshed = 0;
		$failed    = 0;

		WCFMmp_Stripe_Client_Factory::ensure_sdk();
		WCFMmp_Stripe_Webhook_Handler::refresh_platform_cache();

		foreach ( self::connected_vendors() as $vendor_id ) {
			$account_id = get_user_meta( $vendor_id, 'stripe_user_id', true );
			if ( ! $account_id ) {
				continue;
			}
			try {
				$account = WCFMmp_Stripe_Client_Factory::client()->accounts->retrieve( $account_id );
				WCFMmp_Stripe_Webhook_Handler::store_account_meta( (int) $vendor_id, $account );
				$refreshed++;
			} catch ( Exception $e ) {
				$failed++;
				wcfm_stripe_log( 'Stripe diagnostics refresh failed for vendor ' . $vendor_id . ' (' . $account_id . '): ' . $e->getMessage(), 'error' );
			}
		}

		return array( 'refreshed' => $refreshed, 'failed' => $failed );
	}
}

WCFMmp_Stripe_Diagnostics::init();
