<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}












class WCFMmp_Stripe_Refund_Handler {

	


	private $gateway;

	


	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	






	public function process_refund( $refund_id, $order_id, $vendor_id ) {
		global $WCFMmp, $wpdb;

		$order = wc_get_order( $order_id );
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		 
		if ( $order->get_meta( 'wcfmmp_stripe_split_pay_refund_id_' . $refund_id ) ) {
			return;
		}
		if ( $order->get_meta( 'wcfmmp_stripe_split_pay_refund_processed_' . $refund_id ) ) {
			return;
		}
		$WCFMmp->refund_processed = true;

		$charge_type = $order->get_meta( 'wcfmmp_stripe_split_pay_charge_type' );
		$currency    = $order->get_meta( '_wcfmmp_stripe_charge_currency' );
		if ( ! $currency ) {
			$currency = $order->get_currency();
		}

		$refund_infos = $wpdb->get_results( $wpdb->prepare( "SELECT ID, item_id, commission_id, vendor_id, order_id, is_partially_refunded, refunded_amount, refund_reason FROM {$wpdb->prefix}wcfm_marketplace_refund_request WHERE ID = %d", $refund_id ) );
		if ( empty( $refund_infos ) ) {
			return;
		}

		foreach ( $refund_infos as $refund_info ) {
			$refunded_amount       = (float) $refund_info->refunded_amount;
			$is_partially_refunded = $refund_info->is_partially_refunded;

			$request = $this->build_refund_request( $order, $charge_type, $vendor_id, $refunded_amount, $currency, $is_partially_refunded );
			if ( ! $request['charge'] ) {
				wcfm_stripe_log( sprintf( 'Stripe Split Pay (modern) refund #%s skipped for order %d: no source charge.', $refund_id, $order_id ), 'error' );
				continue;
			}

			$request['params'] = apply_filters( 'wcfmmp_stripe_modern_refund_args', $request['params'], $order, $charge_type, $refund_id );

			






			if ( apply_filters( 'wcfmmp_stripe_refund_dry_run', false, $refund_id, $order_id, $vendor_id ) ) {
				




				do_action( 'wcfmmp_stripe_refund_dry_run_result', $request );
				continue;
			}

			$stripe_refund_id = '';
			try {
				$refund = WCFMmp_Stripe_Client_Factory::client()->refunds->create( $request['params'], $request['options'] );
				if ( $refund->id ) {
					$stripe_refund_id = $refund->id;
				}
			} catch ( \Stripe\Exception\ApiErrorException $e ) {
				wcfm_stripe_log( 'Stripe Split Pay (modern) refund error #' . $refund_id . ': ' . get_class( $e ) . ' [' . $e->getStripeCode() . '] ' . $e->getMessage(), 'error' );
			}

			if ( ! $stripe_refund_id ) {
				wcfm_stripe_log( 'Stripe Split Pay (modern) refund failed #' . $refund_id, 'error' );
				continue;
			}

			 
			if ( 'transfers_charges' === $charge_type && $vendor_id ) {
				$this->reverse_transfer( $order, $order_id, $vendor_id, $refund_id, $stripe_refund_id );
			}

			$order->update_meta_data( 'wcfmmp_stripe_split_pay_refund_id_' . $refund_id, $stripe_refund_id );
			$order->update_meta_data( 'wcfmmp_stripe_split_pay_refund_processed_' . $refund_id, 'yes' );
			$order->add_order_note( sprintf( __( 'Refund Processed Via Stripe ( Refund ID: #%s )', 'wc-multivendor-marketplace' ), $refund_id ) );
			$order->save();

			wcfm_stripe_log( 'Stripe Split Pay (modern) refund successful for #' . $refund_id . '. Stripe refund ID => ' . $stripe_refund_id );
		}
	}

	








	public function build_refund_request( $order, $charge_type, $vendor_id, $refunded_amount, $currency, $is_partially_refunded ) {
		$amount  = $this->gateway->get_stripe_amount( round( $refunded_amount, 2 ), $currency );
		$options = array();
		$charge  = '';
		$refund_application_fee = ! $is_partially_refunded;

		 
		 
		 
		$vendor_charge_type = $vendor_id ? $order->get_meta( 'wcfmmp_stripe_split_pay_charge_type_' . $vendor_id ) : '';

		if ( 'held' === $vendor_charge_type ) {
			$charge                 = $order->get_meta( 'wcfmmp_stripe_split_pay_charge_id_' . $vendor_id );
			$refund_application_fee = false;
			$reverse_transfer       = false;
		} elseif ( 'transfers_charges' === $charge_type ) {
			$charge                 = $order->get_meta( 'wcfmmp_stripe_split_pay_charge_id_admin' );
			$refund_application_fee = false;
			$reverse_transfer       = false;
		} elseif ( 'direct_charges' === $charge_type && $vendor_id ) {
			$charge           = $order->get_meta( 'wcfmmp_stripe_split_pay_charge_id_' . $vendor_id );
			$reverse_transfer = false;
			$vendor_account   = get_user_meta( $vendor_id, 'stripe_user_id', true );
			if ( $vendor_account ) {
				$options['stripe_account'] = $vendor_account;
			}
		} else {
			 
			$charge           = $vendor_id ? $order->get_meta( 'wcfmmp_stripe_split_pay_charge_id_' . $vendor_id ) : $order->get_meta( 'wcfmmp_stripe_split_pay_charge_id_admin' );
			$reverse_transfer = (bool) $vendor_id;
		}

		$refund_application_fee = apply_filters( 'wcfm_is_allow_stripe_refund_application_fee', $refund_application_fee, 0, $order->get_id(), $vendor_id );

		$params = array(
			'charge'                 => $charge,
			'amount'                 => $amount,
			'reason'                 => 'requested_by_customer',
			'refund_application_fee' => $refund_application_fee,
			'reverse_transfer'       => $reverse_transfer,
		);

		$refund_id_for_key    = (int) $order->get_id();
		$options['idempotency_key'] = 'wcfmmp-refund-' . $refund_id_for_key . '-' . md5( $charge . $amount );

		return array(
			'routing' => $charge_type,
			'charge'  => $charge,
			'params'  => $params,
			'options' => $options,
		);
	}

	



	private function reverse_transfer( $order, $order_id, $vendor_id, $refund_id, $stripe_refund_id ) {
		global $WCFMmp, $wpdb;

		$vendor_connected = ( get_user_meta( $vendor_id, 'vendor_connected', true ) == 1 );
		if ( ! $vendor_connected ) {
			return;
		}
		$transfer_id = $order->get_meta( 'wcfmmp_stripe_split_pay_transaction_id_' . $vendor_id );
		if ( ! $transfer_id ) {
			return;
		}

		try {
			WCFMmp_Stripe_Client_Factory::client()->transfers->createReversal( $transfer_id );

			$commission_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->prefix}wcfm_marketplace_orders WHERE order_id = %d AND vendor_id = %d", $order_id, $vendor_id ) );
			$commission_ids = implode( ',', array_unique( $commission_ids ) );
			if ( '' !== $commission_ids ) {
				$withdrawals = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->prefix}wcfm_marketplace_withdraw_request WHERE commission_ids = %s AND vendor_id = %d", $commission_ids, $vendor_id ) );
				foreach ( $withdrawals as $withdrawal_id ) {
					$WCFMmp->wcfmmp_withdraw->wcfmmp_withdraw_status_update_by_withdrawal( $withdrawal_id, 'cancelled', __( 'Split Pay Reversal', 'wc-multivendor-marketplace' ) );
				}
			}

			$order->delete_meta_data( 'wcfmmp_stripe_split_pay_transaction_id_' . $vendor_id );
			$order->save();

			wcfm_stripe_log( sprintf( 'Stripe Split Pay (modern) transfer reversal for #%s vendor %d transfer %s refund %s', $refund_id, $vendor_id, $transfer_id, $stripe_refund_id ) );
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			$order->delete_meta_data( 'wcfmmp_stripe_split_pay_transaction_id_' . $vendor_id );
			$order->save();
			wcfm_stripe_log( 'Stripe Split Pay (modern) transfer reversal error #' . $refund_id . ': ' . get_class( $e ) . ' [' . $e->getStripeCode() . '] ' . $e->getMessage(), 'error' );
		}
	}
}
