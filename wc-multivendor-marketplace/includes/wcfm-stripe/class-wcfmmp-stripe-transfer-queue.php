<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}











class WCFMmp_Stripe_Transfer_Queue {

	const ACTION = 'wcfmmp_stripe_process_vendor_transfer';
	const GROUP  = 'wcfmmp-stripe';

	



	const BACKOFF = array( 60, 300, 900, 3600, 7200 );

	


	public static function init() {
		add_action( 'wcfmmp_stripe_modern_payment_complete', array( __CLASS__, 'enqueue_transfers' ), 10, 1 );
		add_action( self::ACTION, array( __CLASS__, 'process_vendor_transfer' ), 10, 3 );
	}

	




	public static function enqueue_transfers( $order_id ) {
		global $WCFMmp;

		$order = wc_get_order( $order_id );
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$list         = $WCFMmp->wcfmmp_commission->wcfmmp_split_pay_vendor_list( $order, array(), 'stripe' );
		$distribution = ( isset( $list['distribution_list'] ) && is_array( $list['distribution_list'] ) ) ? $list['distribution_list'] : array();

		$held = $order->get_meta( '_wcfmmp_stripe_held_vendors' );
		$held = is_array( $held ) ? $held : array();

		foreach ( $distribution as $vendor_id => $info ) {
			if ( isset( $held[ (int) $vendor_id ] ) ) {
				continue;  
			}
			as_enqueue_async_action( self::ACTION, array( $order_id, (int) $vendor_id, 0 ), self::GROUP );
		}
	}

	






	public static function process_vendor_transfer( $order_id, $vendor_id, $attempt = 0 ) {
		$order = wc_get_order( $order_id );
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}
		$vendor_id = (int) $vendor_id;

		 
		if ( $order->get_meta( 'wcfmmp_stripe_split_pay_transaction_id_' . $vendor_id ) ) {
			return;
		}

		 
		 
		$held = $order->get_meta( '_wcfmmp_stripe_held_vendors' );
		if ( is_array( $held ) && isset( $held[ $vendor_id ] ) ) {
			return;
		}

		$charge_id = $order->get_meta( 'wcfmmp_stripe_split_pay_charge_id_admin' );
		if ( ! $charge_id ) {
			wcfm_stripe_log( sprintf( 'Stripe Split Pay (modern) transfer skipped for order %d vendor %d: no source charge.', $order_id, $vendor_id ), 'error' );
			return;
		}

		$destination = get_user_meta( $vendor_id, 'stripe_user_id', true );
		if ( ! $destination ) {
			wcfm_stripe_log( sprintf( 'Stripe Split Pay (modern) transfer skipped for order %d vendor %d: vendor not connected.', $order_id, $vendor_id ), 'error' );
			return;
		}

		 
		 
		$currency = $order->get_meta( '_wcfmmp_stripe_charge_currency' );
		if ( ! $currency ) {
			$currency = $order->get_currency();
		}

		 
		global $wpdb;
		$commission = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(total_commission) FROM {$wpdb->prefix}wcfm_marketplace_orders WHERE order_id = %d AND vendor_id = %d", $order_id, $vendor_id ) );
		$amount     = self::to_minor( $commission, $currency );

		$params = array(
			'amount'             => $amount,
			'currency'           => strtolower( $currency ),
			'destination'        => $destination,
			'source_transaction' => $charge_id,
			'transfer_group'     => 'wcfmmp-order-' . $order_id,
			'description'        => sprintf( __( 'Split Pay payout for order #%s', 'wc-multivendor-marketplace' ), $order->get_order_number() ),
		);

		






		$params = apply_filters( 'wcfmmp_stripe_transfer_args', $params, $order_id, $vendor_id );
		$opts   = array( 'idempotency_key' => WCFMmp_Stripe_Payment_Engine::transfer_idempotency_key( $order_id, $vendor_id, $amount, $currency ) );

		try {
			$transfer = WCFMmp_Stripe_Client_Factory::client()->transfers->create( $params, $opts );
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			self::handle_failure( $order, $vendor_id, (int) $attempt, $e );
			return;
		}

		$order->update_meta_data( 'wcfmmp_stripe_split_pay_transaction_id_' . $vendor_id, $transfer->id );
		$order->save();

		self::complete_withdrawal( $order_id, $vendor_id, $transfer->id );

		wcfm_stripe_log( sprintf( 'Stripe Split Pay (modern) transfer %s created for order %d vendor %d (%s %s).', $transfer->id, $order_id, $vendor_id, $commission, $currency ), 'info' );
	}

	







	private static function handle_failure( $order, $vendor_id, $attempt, $e ) {
		$order_id = $order->get_id();
		$code     = method_exists( $e, 'getStripeCode' ) && $e->getStripeCode() ? $e->getStripeCode() : get_class( $e );

		wcfm_stripe_log( sprintf( 'Stripe Split Pay (modern) transfer failed for order %d vendor %d (attempt %d): %s [%s] %s', $order_id, $vendor_id, $attempt, get_class( $e ), $code, $e->getMessage() ), 'error' );

		if ( $attempt < count( self::BACKOFF ) ) {
			as_schedule_single_action(
				time() + self::BACKOFF[ $attempt ],
				self::ACTION,
				array( $order_id, (int) $vendor_id, $attempt + 1 ),
				self::GROUP
			);
			return;
		}

		 
		$withdrawal_id = self::find_withdrawal( $order_id, $vendor_id );
		if ( $withdrawal_id ) {
			global $WCFMmp;
			$WCFMmp->wcfmmp_withdraw->wcfmmp_update_withdrawal_meta( $withdrawal_id, 'held_reason', $code );
		}

		







		do_action( 'wcfmmp_stripe_transfer_failed', $order_id, $vendor_id, $code );
	}

	






	private static function complete_withdrawal( $order_id, $vendor_id, $transfer_id ) {
		global $WCFMmp;

		$withdrawal_id = self::find_withdrawal( $order_id, $vendor_id );
		if ( ! $withdrawal_id ) {
			return;
		}

		$WCFMmp->wcfmmp_withdraw->wcfmmp_update_withdrawal_meta( $withdrawal_id, 'transaction_id', $transfer_id );
		$WCFMmp->wcfmmp_withdraw->wcfmmp_update_withdrawal_meta( $withdrawal_id, 'transaction_type', 'transfers_charges' );
		$WCFMmp->wcfmmp_withdraw->wcfmmp_withdraw_status_update_by_withdrawal( $withdrawal_id, 'completed', __( 'Stripe Split Pay', 'wc-multivendor-marketplace' ) );
		do_action( 'wcfmmp_withdrawal_request_approved', $withdrawal_id );
	}

	







	private static function find_withdrawal( $order_id, $vendor_id ) {
		global $wpdb;

		$commission_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->prefix}wcfm_marketplace_orders WHERE order_id = %d AND vendor_id = %d", $order_id, $vendor_id ) );
		$commission_ids = implode( ',', array_unique( $commission_ids ) );
		if ( '' === $commission_ids ) {
			return 0;
		}

		$withdrawal_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->prefix}wcfm_marketplace_withdraw_request WHERE commission_ids = %s AND vendor_id = %d ORDER BY ID DESC LIMIT 1", $commission_ids, $vendor_id ) );

		return $withdrawal_id ? (int) $withdrawal_id : 0;
	}

	







	private static function to_minor( $amount, $currency ) {
		$amount       = round( (float) $amount, 2 );
		$zero_decimal = array( 'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' );

		if ( in_array( strtolower( $currency ), $zero_decimal, true ) ) {
			return absint( $amount );
		}
		return absint( round( $amount * 100 ) );
	}
}

WCFMmp_Stripe_Transfer_Queue::init();
