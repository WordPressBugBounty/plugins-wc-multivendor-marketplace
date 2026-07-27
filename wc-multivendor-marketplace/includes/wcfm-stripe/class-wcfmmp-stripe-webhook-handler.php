<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}










class WCFMmp_Stripe_Webhook_Handler {

	const PROCESSED_OPTION = 'wcfmmp_stripe_processed_events';
	const LAST_RECEIVED    = 'wcfmmp_stripe_last_webhook';
	const RECONCILE_ACTION = 'wcfmmp_stripe_reconcile_pending';
	const ABANDON_ACTION   = 'wcfmmp_stripe_cleanup_pending_actions';
	const GROUP            = 'wcfmmp-stripe';
	const RING             = 200;

	





	const REGISTRATION_OPTION = 'wcfmmp_stripe_webhook_registration';

	


	public static function events() {
		return array(
			'setup_intent.succeeded',
			'payment_intent.succeeded',
			'payment_intent.payment_failed',
			'charge.refunded',
			'transfer.reversed',
			'account.updated',
			'payout.failed',
		);
	}

	




	public static function init() {
		add_action( 'woocommerce_api_wcfmmp_stripe', array( __CLASS__, 'handle' ) );
		add_action( 'wcfm_settings_update', array( __CLASS__, 'maybe_auto_register' ), 20 );
		add_action( self::RECONCILE_ACTION, array( __CLASS__, 'reconcile_pending' ) );
		add_action( self::ABANDON_ACTION, array( __CLASS__, 'cleanup_pending_actions' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'reverse_cancelled_challenge' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule_cron' ) );
	}

	



	public static function maybe_schedule_cron() {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::RECONCILE_ACTION, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::RECONCILE_ACTION, array(), self::GROUP );
		}
		if ( ! as_has_scheduled_action( self::ABANDON_ACTION, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::ABANDON_ACTION, array(), self::GROUP );
		}
	}

	





	private static function secret() {
		$opt    = get_option( 'wcfm_withdrawal_options', array() );
		$test   = isset( $opt['test_mode'] );
		$stored = get_option( self::REGISTRATION_OPTION, array() );
		$key    = $test ? 'secret_test' : 'secret';
		if ( is_array( $stored ) && ! empty( $stored[ $key ] ) ) {
			return $stored[ $key ];
		}

		$manual = $test ? 'stripe_webhook_secret_test' : 'stripe_webhook_secret';
		return isset( $opt[ $manual ] ) ? $opt[ $manual ] : '';
	}

	


	public static function handle() {
		$payload = @file_get_contents( 'php://input' );
		$sig     = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
		$secret  = self::secret();

		if ( ! $secret ) {
			self::log_missing_secret_once();
			status_header( 400 );
			exit;
		}

		WCFMmp_Stripe_Client_Factory::ensure_sdk();

		try {
			$event = \Stripe\Webhook::constructEvent( $payload, $sig, $secret );
		} catch ( Exception $e ) {
			wcfm_stripe_log( 'Stripe webhook signature verification failed: ' . $e->getMessage(), 'error' );
			status_header( 400 );
			exit;
		}

		if ( self::is_duplicate( $event->id ) ) {
			status_header( 200 );
			exit;
		}

		self::process( $event );

		self::remember( $event->id );
		update_option( self::LAST_RECEIVED, time(), false );

		status_header( 200 );
		exit;
	}

	




	public static function process( $event ) {
		switch ( $event->type ) {
			case 'setup_intent.succeeded':
				self::on_setup_intent_succeeded( $event->data->object );
				break;
			case 'payment_intent.succeeded':
				self::on_intent_succeeded( $event->data->object );
				break;
			case 'payment_intent.payment_failed':
				self::on_intent_failed( $event->data->object );
				break;
			case 'charge.refunded':
				self::on_charge_refunded( $event->data->object );
				break;
			case 'transfer.reversed':
				self::on_transfer_reversed( $event->data->object );
				break;
			case 'account.updated':
				self::on_account_updated( $event->data->object );
				break;
			case 'payout.failed':
				self::on_payout_failed( $event->data->object );
				break;
		}

		




		do_action( 'wcfmmp_stripe_webhook_' . str_replace( '.', '_', $event->type ), $event );
	}

	 

	


	private static function on_intent_succeeded( $intent ) {
		$order = self::order_from_intent( $intent );
		if ( ! $order || 'modern' !== $order->get_meta( '_wcfmmp_stripe_engine' ) ) {
			return;
		}
		 
		 
		 
		if ( $order->get_meta( '_wcfmmp_stripe_setup_intent_id' ) ) {
			return;
		}
		if ( ! in_array( $order->get_status(), array( 'pending', 'failed', 'on-hold' ), true ) ) {
			return;
		}
		$gateway = self::gateway();
		if ( $gateway ) {
			$order->update_status( 'pending' );
			$gateway->verify_intent_after_checkout( $order );
		}
	}

	






	private static function on_setup_intent_succeeded( $setup ) {
		$order = self::order_from_intent( $setup );
		if ( ! $order || 'modern' !== $order->get_meta( '_wcfmmp_stripe_engine' ) ) {
			return;
		}
		if ( 'pending' !== $order->get_status() ) {
			return;
		}
		$gateway = self::gateway();
		if ( $gateway ) {
			$gateway->verify_intent_after_checkout( $order );
		}
	}

	


	private static function on_intent_failed( $intent ) {
		$order = self::order_from_intent( $intent );
		if ( ! $order || 'modern' !== $order->get_meta( '_wcfmmp_stripe_engine' ) ) {
			return;
		}
		 
		 
		if ( $order->get_meta( '_wcfmmp_stripe_setup_intent_id' ) ) {
			return;
		}
		if ( in_array( $order->get_status(), array( 'pending', 'on-hold' ), true ) ) {
			$reason = isset( $intent->last_payment_error->message ) ? $intent->last_payment_error->message : __( 'Stripe reported the payment failed.', 'wc-multivendor-marketplace' );
			$order->update_status( 'failed', $reason );
		}
	}

	


	private static function on_charge_refunded( $charge ) {
		wcfm_stripe_log( 'Stripe webhook charge.refunded ' . $charge->id . ' (amount_refunded=' . $charge->amount_refunded . ')', 'info' );
	}

	


	private static function on_transfer_reversed( $transfer ) {
		global $wpdb;

		$group = isset( $transfer->transfer_group ) ? $transfer->transfer_group : '';
		if ( 0 !== strpos( (string) $group, 'wcfmmp-order-' ) ) {
			return;
		}
		$order_id = (int) substr( $group, strlen( 'wcfmmp-order-' ) );
		$order    = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		 
		$vendor_id = 0;
		foreach ( $order->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			if ( 0 === strpos( $data['key'], 'wcfmmp_stripe_split_pay_transaction_id_' ) && $data['value'] === $transfer->id ) {
				$vendor_id = (int) substr( $data['key'], strlen( 'wcfmmp_stripe_split_pay_transaction_id_' ) );
				break;
			}
		}
		if ( ! $vendor_id ) {
			return;  
		}

		wcfm_stripe_log( 'Stripe webhook transfer.reversed (external) ' . $transfer->id . ' order ' . $order_id . ' vendor ' . $vendor_id, 'error' );

		$commission_ids = implode( ',', array_unique( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->prefix}wcfm_marketplace_orders WHERE order_id = %d AND vendor_id = %d", $order_id, $vendor_id ) ) ) );
		if ( '' !== $commission_ids ) {
			$withdrawal_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->prefix}wcfm_marketplace_withdraw_request WHERE commission_ids = %s AND vendor_id = %d", $commission_ids, $vendor_id ) );
			if ( $withdrawal_id ) {
				global $WCFMmp;
				$WCFMmp->wcfmmp_withdraw->wcfmmp_update_withdrawal_meta( $withdrawal_id, 'held_reason', 'external_reversal' );
			}
		}
	}

	


	private static function on_account_updated( $account ) {
		$users = get_users( array( 'meta_key' => 'stripe_user_id', 'meta_value' => $account->id, 'number' => 1, 'fields' => 'ID' ) );
		if ( empty( $users ) ) {
			return;
		}
		self::store_account_meta( (int) $users[0], $account );
	}

	





	public static function store_account_meta( $vendor_id, $account ) {
		 
		 
		 
		 
		$capabilities = isset( $account->capabilities ) ? json_decode( wp_json_encode( $account->capabilities ) ) : null;
		update_user_meta( $vendor_id, 'stripe_account_capabilities', $capabilities );
		update_user_meta( $vendor_id, 'stripe_payouts_enabled', ! empty( $account->payouts_enabled ) ? 'yes' : 'no' );
		update_user_meta( $vendor_id, 'stripe_charges_enabled', ! empty( $account->charges_enabled ) ? 'yes' : 'no' );
		update_user_meta( $vendor_id, 'stripe_account_country', isset( $account->country ) ? $account->country : '' );
		$agreement = isset( $account->tos_acceptance->service_agreement ) ? $account->tos_acceptance->service_agreement : 'full';
		update_user_meta( $vendor_id, 'stripe_service_agreement', $agreement );
		update_user_meta( $vendor_id, 'stripe_account_updated', time() );
	}

	


	private static function on_payout_failed( $payout ) {
		$reason = isset( $payout->failure_message ) ? $payout->failure_message : ( isset( $payout->failure_code ) ? $payout->failure_code : 'unknown' );
		wcfm_stripe_log( 'Stripe webhook payout.failed ' . $payout->id . ': ' . $reason, 'error' );

		




		do_action( 'wcfmmp_stripe_payout_failed', $payout );
	}

	 

	




	public static function maybe_auto_register() {
		$opt = get_option( 'wcfm_withdrawal_options', array() );
		$methods = isset( $opt['payment_methods'] ) ? (array) $opt['payment_methods'] : array();
		if ( ! in_array( 'stripe_split', $methods, true ) ) {
			return;
		}
		if ( ! class_exists( 'WCFMmp_Stripe_Client_Factory' ) ) {
			return;
		}

		 
		self::refresh_platform_cache();

		$stored     = get_option( self::REGISTRATION_OPTION, array() );
		$stored     = is_array( $stored ) ? $stored : array();
		$test       = isset( $opt['test_mode'] );
		$id_key     = $test ? 'id_test' : 'id';
		$secret_key = $test ? 'secret_test' : 'secret';
		if ( ! empty( $stored[ $id_key ] ) ) {
			 
			 
			try {
				WCFMmp_Stripe_Client_Factory::client()->webhookEndpoints->update( $stored[ $id_key ], array( 'enabled_events' => self::events() ) );
			} catch ( Exception $e ) {
				wcfm_stripe_log( 'Stripe webhook event-sync failed: ' . $e->getMessage(), 'warning' );
			}
			return;
		}

		try {
			$endpoint = WCFMmp_Stripe_Client_Factory::client()->webhookEndpoints->create( array(
				'url'            => home_url( '?wc-api=wcfmmp_stripe' ),
				'enabled_events' => self::events(),
				'api_version'    => WCFMmp_Stripe_Client_Factory::API_VERSION,
			) );

			$stored[ $id_key ] = $endpoint->id;
			if ( isset( $endpoint->secret ) ) {
				$stored[ $secret_key ] = $endpoint->secret;
			}
			update_option( self::REGISTRATION_OPTION, $stored, false );
			wcfm_stripe_log( 'Stripe webhook endpoint registered: ' . $endpoint->id, 'info' );
		} catch ( Exception $e ) {
			wcfm_stripe_log( 'Stripe webhook auto-registration failed: ' . $e->getMessage(), 'error' );
		}
	}

	





	public static function refresh_platform_cache() {
		try {
			WCFMmp_Stripe_Client_Factory::ensure_sdk();
			$account = WCFMmp_Stripe_Client_Factory::client()->accounts->retrieve( null );
			$cache   = array(
				'country'  => isset( $account->country ) ? $account->country : '',
				'currency' => isset( $account->default_currency ) ? strtoupper( $account->default_currency ) : '',
				'updated'  => time(),
			);
			update_option( WCFMmp_Stripe_Client_Factory::PLATFORM_CACHE_OPTION, $cache, false );
			return $cache;
		} catch ( Exception $e ) {
			wcfm_stripe_log( 'Stripe platform account cache refresh failed: ' . $e->getMessage(), 'error' );
			return false;
		}
	}

	



	public static function reconcile_pending() {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, order_ids, vendor_id FROM {$wpdb->prefix}wcfm_marketplace_withdraw_request
			 WHERE withdraw_status = %s AND withdraw_mode = %s AND created < ( NOW() - INTERVAL 24 HOUR )",
			'pending', 'by_split_pay'
		) );
		if ( empty( $rows ) || ! class_exists( 'WCFMmp_Stripe_Transfer_Queue' ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$order_id = (int) $row->order_ids;
			$order    = wc_get_order( $order_id );
			if ( ! $order || 'modern' !== $order->get_meta( '_wcfmmp_stripe_engine' ) ) {
				continue;
			}
			if ( ! $order->get_meta( 'wcfmmp_stripe_split_pay_charge_id_admin' ) ) {
				continue;
			}
			WCFMmp_Stripe_Transfer_Queue::process_vendor_transfer( $order_id, (int) $row->vendor_id, 0 );
		}
	}

	






	public static function cleanup_pending_actions() {
		if ( ! class_exists( 'WCFMmp_Stripe_Payment_Engine' ) ) {
			return;
		}

		$ttl = (int) apply_filters( 'wcfmmp_stripe_pending_action_ttl', HOUR_IN_SECONDS );

		 
		 
		 
		 
		$order_ids = wc_get_orders( array(
			'limit'      => 50,
			'status'     => array( 'pending', 'failed', 'cancelled' ),
			'return'     => 'ids',
			'meta_query' => array(
				array(
					'key'     => '_wcfmmp_stripe_pending_vendor_action',
					'compare' => 'EXISTS',
				),
			),
		) );
		if ( empty( $order_ids ) ) {
			return;
		}

		$gateway = self::gateway();
		if ( ! $gateway ) {
			return;
		}
		$engine = new WCFMmp_Stripe_Payment_Engine( $gateway );

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}
			$pending = $order->get_meta( '_wcfmmp_stripe_pending_vendor_action' );
			if ( ! is_array( $pending ) ) {
				continue;
			}
			$age = time() - ( isset( $pending['time'] ) ? (int) $pending['time'] : 0 );
			if ( $age < $ttl ) {
				continue;
			}
			$engine->abandon_stale_pending_action( $order );
			wcfm_stripe_log( 'Stripe Split Pay (modern) abandoned SCA challenge cleaned up for order ' . $order_id, 'info' );
		}
	}

	










	public static function reverse_cancelled_challenge( $order_id ) {
		if ( ! class_exists( 'WCFMmp_Stripe_Payment_Engine' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! is_array( $order->get_meta( '_wcfmmp_stripe_pending_vendor_action' ) ) ) {
			return;
		}
		$gateway = self::gateway();
		if ( ! $gateway ) {
			return;
		}
		$engine = new WCFMmp_Stripe_Payment_Engine( $gateway );
		$engine->abandon_stale_pending_action( $order );
		wcfm_stripe_log( 'Stripe Split Pay (modern) cancelled order ' . $order_id . ': reversed captured vendor charges from the abandoned SCA challenge.', 'info' );
	}

	 

	private static function order_from_intent( $intent ) {
		$order_id = isset( $intent->metadata->order_id ) ? (int) $intent->metadata->order_id : 0;
		if ( ! $order_id ) {
			return false;
		}
		$order = wc_get_order( $order_id );
		return $order ? $order : false;
	}

	private static function gateway() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways['stripe_split'] ) ? $gateways['stripe_split'] : null;
	}

	private static function is_duplicate( $event_id ) {
		$processed = get_option( self::PROCESSED_OPTION, array() );
		return is_array( $processed ) && in_array( $event_id, $processed, true );
	}

	private static function remember( $event_id ) {
		$processed = get_option( self::PROCESSED_OPTION, array() );
		if ( ! is_array( $processed ) ) {
			$processed = array();
		}
		$processed[] = $event_id;
		if ( count( $processed ) > self::RING ) {
			$processed = array_slice( $processed, -self::RING );
		}
		update_option( self::PROCESSED_OPTION, $processed, false );
	}

	private static function log_missing_secret_once() {
		$flag = 'wcfmmp_stripe_webhook_nosecret_logged';
		if ( get_transient( $flag ) ) {
			return;
		}
		wcfm_stripe_log( 'Stripe webhook received but no signing secret is configured; rejecting.', 'error' );
		set_transient( $flag, 1, DAY_IN_SECONDS );
	}
}
