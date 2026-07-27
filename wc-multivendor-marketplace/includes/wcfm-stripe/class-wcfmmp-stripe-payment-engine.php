<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
























final class WCFMmp_Stripe_Payment_Engine {

	




	private $gateway;

	


	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	










	public static function decide_routing( $vendor_count, $configured_mode ) {
		if ( 0 === $vendor_count ) {
			return array( 'charge_type' => 'transfers_charges', 'reason' => 'no_connected_vendor' );
		}

		$mode = in_array( $configured_mode, array( 'direct_charges', 'destination_charges', 'transfers_charges' ), true ) ? $configured_mode : 'direct_charges';
		return array( 'charge_type' => $mode, 'reason' => 'configured' );
	}

	



















	public static function build_charge_plan( $vendors, $mode, $held_map, $order_total_minor ) {
		$plan         = array();
		$covered      = 0;
		$held_map     = is_array( $held_map ) ? $held_map : array();
		$base_routing = ( 'destination_charges' === $mode ) ? 'destination' : 'direct';

		foreach ( $vendors as $vendor_id => $info ) {
			$vendor_id  = (int) $vendor_id;
			$amount     = isset( $info['amount_minor'] ) ? (int) $info['amount_minor'] : 0;
			$commission = isset( $info['commission_minor'] ) ? (int) $info['commission_minor'] : 0;
			 
			 
			 
			 
			 
			 
			 
			 
			 
			 
			$gateway_fee = ( 'direct' === $base_routing && isset( $info['gateway_fee_minor'] ) ) ? (int) $info['gateway_fee_minor'] : 0;
			$account    = isset( $info['account'] ) ? $info['account'] : '';
			$covered   += $amount;

			if ( isset( $held_map[ $vendor_id ] ) ) {
				$plan[] = array(
					'vendor_id'             => $vendor_id,
					'account'               => '',
					'amount_minor'          => $amount,
					'application_fee_minor' => 0,
					'routing'               => 'held',
					'held_reason'           => $held_map[ $vendor_id ],
				);
				continue;
			}

			$plan[] = array(
				'vendor_id'             => $vendor_id,
				'account'               => $account,
				'amount_minor'          => $amount,
				'application_fee_minor' => max( 0, $amount - $commission - $gateway_fee ),
				'routing'               => $base_routing,
				'held_reason'           => '',
			);
		}

		 
		$remainder = (int) $order_total_minor - $covered;
		if ( $remainder > 0 ) {
			$plan[] = array(
				'vendor_id'             => 0,
				'account'               => '',
				'amount_minor'          => $remainder,
				'application_fee_minor' => 0,
				'routing'               => 'platform',
				'held_reason'           => '',
			);
		}

		return $plan;
	}

	







	public static function intent_idempotency_key( $order_id, $amount, $currency ) {
		return 'wcfmmp-' . $order_id . '-modern-intent-' . md5( $amount . $currency );
	}

	








	public static function transfer_idempotency_key( $order_id, $vendor_id, $amount, $currency ) {
		return 'wcfmmp-' . $order_id . '-v' . $vendor_id . '-transfer-' . md5( $amount . $currency );
	}

	












	public static function vendor_intent_idempotency_key( $order_id, $vendor_id, $amount, $currency, $attempt ) {
		return 'wcfmmp-' . $order_id . '-v' . $vendor_id . '-charge-' . md5( $amount . $currency . $attempt );
	}

	






	public static function era_from_meta( $engine_meta ) {
		return ( 'modern' === $engine_meta ) ? 'modern' : 'legacy';
	}

	





	private function configured_mode() {
		global $WCFMmp;
		$options = ( $WCFMmp && ! empty( $WCFMmp->wcfmmp_withdrawal_options ) ) ? $WCFMmp->wcfmmp_withdrawal_options : get_option( 'wcfm_withdrawal_options', array() );
		$options = is_array( $options ) ? $options : array();
		return isset( $options['stripe_split_pay_mode'] ) ? $options['stripe_split_pay_mode'] : 'direct_charges';
	}

	






	private function distribution( $order, $post ) {
		global $WCFMmp;
		$list = $WCFMmp->wcfmmp_commission->wcfmmp_split_pay_vendor_list( $order, $post, 'stripe' );
		return ( isset( $list['distribution_list'] ) && is_array( $list['distribution_list'] ) ) ? $list['distribution_list'] : array();
	}

	





	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return array( 'result' => 'fail' );
		}

		$distribution = $this->distribution( $order, $_POST );
		$mode         = $this->configured_mode();

		 
		 
		 
		if ( in_array( $mode, array( 'direct_charges', 'destination_charges' ), true ) ) {
			return $this->start_setup_flow( $order, $distribution, $mode );
		}

		 
		$routing      = self::decide_routing( count( $distribution ), $mode );
		$charge_type  = $routing['charge_type'];
		$currency     = $order->get_currency();

		 
		 
		$held = array();
		if ( class_exists( 'WCFMmp_Stripe_Preflight' ) ) {
			$assessment = WCFMmp_Stripe_Preflight::assess_order( $distribution, $currency, $charge_type );
			if ( $assessment['demoted'] ) {
				$charge_type       = 'transfers_charges';
				$routing['reason'] = 'preflight_demoted';
			}
			$held = $assessment['held'];
		}

		 
		 
		$this->purge_stale_flow_state( $order, 'payment' );

		 
		$order->update_meta_data( 'wcfmmp_stripe_split_pay_charge_type', $charge_type );
		$order->update_meta_data( '_wcfmmp_stripe_engine', 'modern' );
		$order->update_meta_data( '_wcfmmp_stripe_routing_reason', $routing['reason'] );

		if ( $held ) {
			$order->update_meta_data( '_wcfmmp_stripe_held_vendors', $held );
			$note = __( 'Stripe Split Pay pre-flight: payouts held on the platform for', 'wc-multivendor-marketplace' );
			foreach ( $held as $held_vendor => $held_reason ) {
				$note .= ' #' . $held_vendor . ' (' . $held_reason . ')';
			}
			$order->add_order_note( $note );
		}
		 
		 
		$order->update_meta_data( '_wcfmmp_stripe_charge_currency', $currency );

		$amount      = $this->gateway->get_stripe_amount( $order->get_total(), $currency );
		$idempotency = self::intent_idempotency_key( $order_id, $amount, $currency );

		$params = array(
			'amount'               => $amount,
			'currency'             => strtolower( $currency ),
			'payment_method_types' => array( 'card' ),
			'confirm'              => false,
			'metadata'             => array(
				'order_id'       => $order_id,
				'customer_email' => $order->get_billing_email(),
			),
		);
		$opts             = array( 'idempotency_key' => $idempotency );
		$connect_account  = '';

		if ( 'transfers_charges' === $charge_type ) {
			$params['transfer_group'] = 'wcfmmp-order-' . $order_id;
		} else {
			 
			$vendor_id = (int) key( $distribution );
			$info      = $distribution[ $vendor_id ];
			 
			 
			 
			$application_fee              = max( 0, $amount - $this->gateway->get_stripe_amount( $info['commission'], $currency ) );
			$params['application_fee_amount'] = $application_fee;

			if ( 'destination_charges' === $charge_type ) {
				$params['transfer_data'] = array( 'destination' => $info['destination'] );
			} else {
				 
				 
				$connect_account          = $info['destination'];
				$opts['stripe_account']   = $connect_account;
				$order->update_meta_data( '_wcfmmp_stripe_intent_account', $connect_account );
			}
		}

		






		$params = apply_filters( 'wcfmmp_stripe_modern_intent_args', $params, $order, $charge_type );

		try {
			$intent = WCFMmp_Stripe_Client_Factory::client()->paymentIntents->create( $params, $opts );
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			wcfm_stripe_log( 'Stripe Split Pay (modern) intent creation failed for order ' . $order_id . ': ' . get_class( $e ) . ' [' . $e->getStripeCode() . '] ' . $e->getMessage(), 'error' );
			wc_add_notice( __( 'Payment could not be started at this time. Please try again or contact the site administrator.', 'wc-multivendor-marketplace' ), 'error' );
			return array( 'result' => 'fail' );
		}

		$order->update_meta_data( '_wcfmmp_stripe_intent_id', $intent->id );
		$order->save();

		return array(
			'result'                      => 'success',
			'redirect'                    => $this->gateway->get_return_url( $order ),
			'wcfmmp_flow'                 => 'payment',
			'wcfmmp_intent_client_secret' => $intent->client_secret,
			'wcfmmp_intent_account'       => $connect_account,
		);
	}

	










	private function start_setup_flow( $order, $distribution, $mode ) {
		$order_id = $order->get_id();
		$currency = $order->get_currency();

		 
		 
		 
		$held = array();
		if ( class_exists( 'WCFMmp_Stripe_Preflight' ) ) {
			$assessment = WCFMmp_Stripe_Preflight::assess_order( $distribution, $currency, $mode );
			$held       = ( isset( $assessment['held'] ) && is_array( $assessment['held'] ) ) ? $assessment['held'] : array();
		}

		 
		 
		$this->purge_stale_flow_state( $order, 'setup' );

		 
		 
		 
		 
		 
		$this->reset_prior_setup_attempt( $order );

		$order->update_meta_data( 'wcfmmp_stripe_split_pay_charge_type', $mode );
		$order->update_meta_data( '_wcfmmp_stripe_engine', 'modern' );
		$order->update_meta_data( '_wcfmmp_stripe_routing_reason', 'configured' );
		$order->update_meta_data( '_wcfmmp_stripe_charge_currency', $currency );

		if ( $held ) {
			$order->update_meta_data( '_wcfmmp_stripe_held_vendors', $held );
			$note = __( 'Stripe Split Pay pre-flight: payouts held on the platform for', 'wc-multivendor-marketplace' );
			foreach ( $held as $held_vendor => $held_reason ) {
				$note .= ' #' . $held_vendor . ' (' . $held_reason . ')';
			}
			$order->add_order_note( $note );
		}

		try {
			$customer_id = $this->ensure_platform_customer( $order );
			$setup       = WCFMmp_Stripe_Client_Factory::client()->setupIntents->create( array(
				'customer'             => $customer_id,
				'usage'                => 'off_session',
				'payment_method_types' => array( 'card' ),
				'metadata'             => array(
					'order_id'       => $order_id,
					'customer_email' => $order->get_billing_email(),
				),
			) );
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			wcfm_stripe_log( 'Stripe Split Pay (modern) setup intent creation failed for order ' . $order_id . ': ' . get_class( $e ) . ' [' . $e->getStripeCode() . '] ' . $e->getMessage(), 'error' );
			wc_add_notice( __( 'Payment could not be started at this time. Please try again or contact the site administrator.', 'wc-multivendor-marketplace' ), 'error' );
			return array( 'result' => 'fail' );
		}

		$order->update_meta_data( '_wcfmmp_stripe_customer_id', $customer_id );
		$order->update_meta_data( '_wcfmmp_stripe_setup_intent_id', $setup->id );
		$order->save();

		return array(
			'result'                      => 'success',
			'redirect'                    => $this->gateway->get_return_url( $order ),
			'wcfmmp_flow'                 => 'setup',
			'wcfmmp_intent_client_secret' => $setup->client_secret,
			'wcfmmp_intent_account'       => '',
		);
	}

	











	public function prepare_order_pay_payload( $order ) {
		if ( ! $order->needs_payment() ) {
			return null;
		}

		 
		 
		 
		if ( ! in_array( $order->get_payment_method(), array( '', 'stripe_split' ), true ) ) {
			return null;
		}

		 
		 
		 
		if ( 'stripe_split' !== $order->get_payment_method() ) {
			$order->set_payment_method( 'stripe_split' );
			$order->save();
		}

		$mode   = $this->configured_mode();
		$flow   = in_array( $mode, array( 'direct_charges', 'destination_charges' ), true ) ? 'setup' : 'payment';
		$secret = $this->reusable_order_pay_secret( $order, $flow );

		if ( '' === $secret ) {
			$result = $this->process_payment( $order->get_id() );
			if ( empty( $result['wcfmmp_intent_client_secret'] ) ) {
				return null;
			}
			$secret = $result['wcfmmp_intent_client_secret'];
		}

		$verification_url = add_query_arg(
			array(
				'order'            => $order->get_id(),
				'nonce'            => wp_create_nonce( 'wc_stripe_confirm_pi' ),
				'redirect_to'      => rawurlencode( $this->gateway->get_return_url( $order ) ),
				'is_pay_for_order' => true,
			),
			WC_AJAX::get_endpoint( 'wcfmmp_stripe_verify_intent' )
		);

		return array(
			'flow'     => $flow,
			'secret'   => $secret,
			'account'  => '',
			'verify'   => $verification_url,
			'redirect' => $this->gateway->get_return_url( $order ),
		);
	}

	









	private function reusable_order_pay_secret( $order, $flow ) {
		$meta_key = ( 'setup' === $flow ) ? '_wcfmmp_stripe_setup_intent_id' : '_wcfmmp_stripe_intent_id';
		$id       = $order->get_meta( $meta_key );
		if ( ! $id ) {
			return '';
		}

		 
		 
		$stored_mode = $order->get_meta( 'wcfmmp_stripe_split_pay_charge_type' );
		$stored_is_b = in_array( $stored_mode, array( 'direct_charges', 'destination_charges' ), true );
		if ( ( 'setup' === $flow ) !== $stored_is_b ) {
			return '';
		}

		try {
			$client = WCFMmp_Stripe_Client_Factory::client();
			$intent = ( 'setup' === $flow ) ? $client->setupIntents->retrieve( $id ) : $client->paymentIntents->retrieve( $id );
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			return '';
		}

		if ( ! in_array( $intent->status, array( 'requires_payment_method', 'requires_confirmation', 'requires_action' ), true ) ) {
			return '';
		}

		 
		if ( 'payment' === $flow ) {
			$currency = $order->get_currency();
			if ( strtolower( $currency ) !== $intent->currency || (int) $intent->amount !== (int) $this->gateway->get_stripe_amount( $order->get_total(), $currency ) ) {
				return '';
			}
		}

		return (string) $intent->client_secret;
	}

	





	private function ensure_platform_customer( $order ) {
		$client  = WCFMmp_Stripe_Client_Factory::client();
		$user_id = $order->get_user_id();

		$existing = $user_id ? get_user_meta( $user_id, 'wcfmmp_stripe_split_pay_customer_id', true ) : '';
		if ( $existing ) {
			try {
				$customer = $client->customers->retrieve( $existing );
				if ( empty( $customer->deleted ) ) {
					return $existing;
				}
			} catch ( \Stripe\Exception\ApiErrorException $e ) {
				 
				wcfm_stripe_log( 'Stripe Split Pay (modern) saved customer ' . $existing . ' unusable, creating a new one: ' . $e->getMessage(), 'warning' );
			}
		}

		$customer = $client->customers->create( array(
			'email'    => $order->get_billing_email(),
			'name'     => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'metadata' => array(
				'user_id'  => (string) $user_id,
				'order_id' => (string) $order->get_id(),
			),
		) );

		if ( $user_id ) {
			update_user_meta( $user_id, 'wcfmmp_stripe_split_pay_customer_id', $customer->id );
		}

		return $customer->id;
	}

	








	public function complete_verification( $order ) {
		 
		 
		 
		 
		 
		$charge_type = $order->get_meta( 'wcfmmp_stripe_split_pay_charge_type' );
		$is_flow_b   = in_array( $charge_type, array( 'direct_charges', 'destination_charges' ), true );

		if ( $is_flow_b && $order->get_meta( '_wcfmmp_stripe_setup_intent_id' ) ) {
			$this->complete_setup_flow( $order );
			return;
		}

		$intent_id = $order->get_meta( '_wcfmmp_stripe_intent_id' );
		if ( ! $intent_id ) {
			return;
		}

		$account = $order->get_meta( '_wcfmmp_stripe_intent_account' );
		$opts    = $account ? array( 'stripe_account' => $account ) : array();

		 
		clean_post_cache( $order->get_id() );
		$order = wc_get_order( $order->get_id() );

		if ( 'pending' !== $order->get_status() && 'failed' !== $order->get_status() ) {
			return;
		}

		try {
			$intent = WCFMmp_Stripe_Client_Factory::client()->paymentIntents->retrieve( $intent_id, array(), $opts );
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			wcfm_stripe_log( 'Stripe Split Pay (modern) intent retrieve failed for order ' . $order->get_id() . ': ' . $e->getMessage(), 'error' );
			return;
		}

		if ( $this->gateway->lock_order_payment( $order, $intent ) ) {
			return;
		}

		if ( 'succeeded' === $intent->status || 'requires_capture' === $intent->status ) {
			$this->handle_success( $order, $intent );
		} elseif ( 'processing' === $intent->status ) {
			 
			 
			if ( 'on-hold' !== $order->get_status() ) {
				$order->update_status( 'on-hold', __( 'Awaiting Stripe payment confirmation.', 'wc-multivendor-marketplace' ) );
			}
		} elseif ( 'requires_payment_method' === $intent->status ) {
			$this->gateway->failed_sca_auth( $order, $intent );
		}

		$this->gateway->unlock_order_payment( $order );
	}

	







	private function handle_success( $order, $intent ) {
		$order_id    = $order->get_id();
		$charge_type = $order->get_meta( 'wcfmmp_stripe_split_pay_charge_type' );
		$charge_id   = is_string( $intent->latest_charge ) ? $intent->latest_charge : ( isset( $intent->latest_charge->id ) ? $intent->latest_charge->id : '' );

		$distribution = $this->distribution( $order, array() );

		if ( 'transfers_charges' === $charge_type ) {
			$order->update_meta_data( 'wcfmmp_stripe_split_pay_charge_id_admin', $charge_id );
			$order->update_meta_data( 'wcfmmp_stripe_split_pay_charge_type_admin', $charge_type );

			$held = $order->get_meta( '_wcfmmp_stripe_held_vendors' );
			$held = is_array( $held ) ? $held : array();

			 
			 
			 
			 
			foreach ( $distribution as $vendor_id => $info ) {
				$withdrawal_id = $this->create_withdrawal_row( $order, (int) $vendor_id, $info, 'pending', $charge_type, '' );
				if ( isset( $held[ (int) $vendor_id ] ) ) {
					global $WCFMmp;
					$WCFMmp->wcfmmp_withdraw->wcfmmp_update_withdrawal_meta( $withdrawal_id, 'held_reason', $held[ (int) $vendor_id ] );
					if ( class_exists( 'WCFMmp_Stripe_Preflight' ) ) {
						WCFMmp_Stripe_Preflight::notify_held( $order, (int) $vendor_id, $held[ (int) $vendor_id ] );
					}
				}
			}
		} else {
			 
			 
			$vendor_id = (int) key( $distribution );
			if ( $vendor_id ) {
				$order->update_meta_data( 'wcfmmp_stripe_split_pay_charge_id_' . $vendor_id, $charge_id );
				$order->update_meta_data( 'wcfmmp_stripe_split_pay_charge_type_' . $vendor_id, $charge_type );
				$this->create_withdrawal_row( $order, $vendor_id, $distribution[ $vendor_id ], 'completed', $charge_type, $charge_id );
			} else {
				$order->update_meta_data( 'wcfmmp_stripe_split_pay_charge_id_admin', $charge_id );
			}
		}

		$order->payment_complete( $charge_id );
		$order->save();

		if ( 'transfers_charges' === $charge_type ) {
			





			do_action( 'wcfmmp_stripe_modern_payment_complete', $order_id );
		}
	}

	










	private function complete_setup_flow( $order ) {
		$order_id = $order->get_id();
		$setup_id = $order->get_meta( '_wcfmmp_stripe_setup_intent_id' );
		$client   = WCFMmp_Stripe_Client_Factory::client();

		 
		clean_post_cache( $order_id );
		$order = wc_get_order( $order_id );
		if ( ! in_array( $order->get_status(), array( 'pending', 'failed' ), true ) ) {
			return;
		}

		try {
			$setup = $client->setupIntents->retrieve( $setup_id );
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			wcfm_stripe_log( 'Stripe Split Pay (modern) setup intent retrieve failed for order ' . $order_id . ': ' . $e->getMessage(), 'error' );
			return;
		}

		if ( 'succeeded' !== $setup->status || empty( $setup->payment_method ) ) {
			 
			return;
		}

		if ( $this->gateway->lock_order_payment( $order, $setup ) ) {
			return;
		}

		$platform_pm = is_string( $setup->payment_method ) ? $setup->payment_method : $setup->payment_method->id;
		$customer_id = $order->get_meta( '_wcfmmp_stripe_customer_id' );
		$mode        = $order->get_meta( 'wcfmmp_stripe_split_pay_charge_type' );
		$currency    = $order->get_meta( '_wcfmmp_stripe_charge_currency' );
		if ( ! $currency ) {
			$currency = $order->get_currency();
		}

		$distribution = $this->distribution( $order, array() );
		$held         = $order->get_meta( '_wcfmmp_stripe_held_vendors' );
		$held         = is_array( $held ) ? $held : array();

		$hand_back = apply_filters( 'wcfmmp_prevent_stripe_direct_charge_deduct_transaction_fee', true );

		$vendors = array();
		foreach ( $distribution as $vendor_id => $info ) {
			 
			 
			 
			 
			 
			$commission = $this->vendor_booked_commission( $order_id, (int) $vendor_id );
			$vendors[ (int) $vendor_id ] = array(
				'account'           => $info['destination'],
				'amount_minor'      => $this->gateway->get_stripe_amount( $info['gross_sales'], $currency ),
				'commission_minor'  => $this->gateway->get_stripe_amount( $commission, $currency ),
				'gateway_fee_minor' => $hand_back ? $this->gateway->get_stripe_amount( $this->gateway->wcfmmp_stripe_split_total_gateway_fee( $order_id, (int) $vendor_id ), $currency ) : 0,
			);
		}
		$order_total_minor = $this->gateway->get_stripe_amount( $order->get_total(), $currency );
		$plan              = self::build_charge_plan( $vendors, $mode, $held, $order_total_minor );

		$ledger  = $order->get_meta( '_wcfmmp_stripe_vendor_intents' );
		$ledger  = is_array( $ledger ) ? $ledger : array();
		$failure = '';
		$pending = array();

		foreach ( $plan as $spec ) {
			$vendor_id = (int) $spec['vendor_id'];

			 
			$done_key = $vendor_id ? 'wcfmmp_stripe_split_pay_charge_id_' . $vendor_id : 'wcfmmp_stripe_split_pay_charge_id_admin';
			if ( $order->get_meta( $done_key ) ) {
				continue;
			}

			$existing             = isset( $ledger[ $vendor_id ] ) ? $ledger[ $vendor_id ] : array();
			$result               = $this->charge_vendor_spec( $order, $spec, $platform_pm, $customer_id, $currency, $existing );
			$ledger[ $vendor_id ] = $result['ledger'];

			if ( 'requires_action' === $result['status'] ) {
				 
				 
				 
				 
				$pending = array(
					'vendor_id'     => $vendor_id,
					'routing'       => $result['ledger']['routing'],
					'account'       => $result['ledger']['account'],
					'client_secret' => $result['client_secret'],
					'time'          => time(),
				);
				break;
			}

			if ( 'succeeded' !== $result['status'] ) {
				$failure = $result['error'];
				break;
			}
		}

		$order->update_meta_data( '_wcfmmp_stripe_vendor_intents', $ledger );

		if ( $pending ) {
			$order->update_meta_data( '_wcfmmp_stripe_pending_vendor_action', $pending );
			$order->save();
			$this->gateway->unlock_order_payment( $order );
			return;
		}

		 
		$order->delete_meta_data( '_wcfmmp_stripe_pending_vendor_action' );
		$order->save();

		if ( $failure ) {
			 
			 
			$this->rollback_setup_charges( $this->ledger_captures( $ledger ) );
			$order->update_status( 'failed', $failure );
			wc_add_notice( __( 'Stripe Split Pay Error: ', 'wc-multivendor-marketplace' ) . $failure, 'error' );
			$this->gateway->unlock_order_payment( $order );
			return;
		}

		$this->handle_success_setup( $order, $plan, $ledger );
		$this->gateway->unlock_order_payment( $order );
	}

	








	private function ledger_captures( $ledger ) {
		$created = array();
		foreach ( $ledger as $entry ) {
			if ( empty( $entry['intent'] ) ) {
				continue;
			}
			$created[] = array(
				'intent'  => $entry['intent'],
				'account' => isset( $entry['account'] ) ? $entry['account'] : '',
				'routing' => isset( $entry['routing'] ) ? $entry['routing'] : '',
				'status'  => isset( $entry['status'] ) ? $entry['status'] : '',
			);
		}
		return $created;
	}

	







	public function abandon_stale_pending_action( $order ) {
		$pending = $order->get_meta( '_wcfmmp_stripe_pending_vendor_action' );
		if ( ! is_array( $pending ) ) {
			return;
		}

		$ledger = $order->get_meta( '_wcfmmp_stripe_vendor_intents' );
		if ( is_array( $ledger ) && $ledger ) {
			$this->rollback_setup_charges( $this->ledger_captures( $ledger ) );
		}

		$order->delete_meta_data( '_wcfmmp_stripe_pending_vendor_action' );
		$order->save();

		if ( in_array( $order->get_status(), array( 'pending', 'failed' ), true ) ) {
			$order->update_status( 'failed', __( 'Stripe Split Pay: additional authentication was not completed in time; captured vendor charges were reversed.', 'wc-multivendor-marketplace' ) );
		}
	}

	








	private function purge_stale_flow_state( $order, $active_flow ) {
		try {
			$client = WCFMmp_Stripe_Client_Factory::client();
		} catch ( Exception $e ) {
			return;
		}

		if ( 'setup' === $active_flow ) {
			 
			$stale = $order->get_meta( '_wcfmmp_stripe_intent_id' );
			if ( ! $stale ) {
				return;
			}
			$account = $order->get_meta( '_wcfmmp_stripe_intent_account' );
			$opts    = $account ? array( 'stripe_account' => $account ) : array();
			try {
				$client->paymentIntents->cancel( $stale, array(), $opts );
			} catch ( \Stripe\Exception\ApiErrorException $e ) {
				 
				wcfm_stripe_log( 'Stripe Split Pay (modern) stale Flow A intent teardown skipped for order ' . $order->get_id() . ': ' . $e->getMessage(), 'info' );
			}
			$order->delete_meta_data( '_wcfmmp_stripe_intent_id' );
			$order->delete_meta_data( '_wcfmmp_stripe_intent_account' );
			return;
		}

		 
		$stale = $order->get_meta( '_wcfmmp_stripe_setup_intent_id' );
		if ( ! $stale ) {
			return;
		}
		$ledger = $order->get_meta( '_wcfmmp_stripe_vendor_intents' );
		if ( is_array( $ledger ) && $ledger ) {
			$this->rollback_setup_charges( $this->ledger_captures( $ledger ) );
		}
		try {
			$client->setupIntents->cancel( $stale );
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			 
			 
			wcfm_stripe_log( 'Stripe Split Pay (modern) stale setup intent teardown skipped for order ' . $order->get_id() . ': ' . $e->getMessage(), 'info' );
		}
		$order->delete_meta_data( '_wcfmmp_stripe_setup_intent_id' );
		$order->delete_meta_data( '_wcfmmp_stripe_vendor_intents' );
		$order->delete_meta_data( '_wcfmmp_stripe_pending_vendor_action' );
	}

	









	private function reset_prior_setup_attempt( $order ) {
		$ledger = $order->get_meta( '_wcfmmp_stripe_vendor_intents' );
		if ( is_array( $ledger ) && $ledger ) {
			$this->rollback_setup_charges( $this->ledger_captures( $ledger ) );
		}
		$order->delete_meta_data( '_wcfmmp_stripe_vendor_intents' );
		$order->delete_meta_data( '_wcfmmp_stripe_pending_vendor_action' );
	}

	


























	private function charge_vendor_spec( $order, $spec, $platform_pm, $customer_id, $currency, $existing = array() ) {
		$order_id     = $order->get_id();
		$client       = WCFMmp_Stripe_Client_Factory::client();
		$vendor_id    = (int) $spec['vendor_id'];
		$routing      = $spec['routing'];
		$account      = ( 'direct' === $routing ) ? $spec['account'] : '';
		$account_opts = ( '' !== $account ) ? array( 'stripe_account' => $account ) : array();

		$ledger = array(
			'intent'      => '',
			'account'     => $account,
			'routing'     => $routing,
			'status'      => '',
			'charge'      => '',
			'held_reason' => $spec['held_reason'],
		);

		 
		 
		if ( ! empty( $existing['intent'] ) ) {
			try {
				$intent = $client->paymentIntents->retrieve( $existing['intent'], array(), $account_opts );
			} catch ( \Stripe\Exception\ApiErrorException $e ) {
				wcfm_stripe_log( 'Stripe Split Pay (modern) vendor ' . $vendor_id . ' intent retrieve failed for order ' . $order_id . ': ' . $e->getMessage(), 'error' );
				return $this->vendor_charge_failed( $ledger, __( 'Payment could not be processed at this time. Please try again or use a different card.', 'wc-multivendor-marketplace' ) );
			}
			return $this->settle_vendor_intent( $intent, $ledger );
		}

		 
		try {
			$intent = $this->create_vendor_intent( $order, $spec, $platform_pm, $customer_id, $currency, false );
		} catch ( \Stripe\Exception\CardException $e ) {
			if ( 'authentication_required' === $e->getStripeCode() ) {
				 
				 
				return $this->escalate_vendor_intent( $order, $spec, $platform_pm, $customer_id, $currency, $e, $ledger );
			}
			wcfm_stripe_log( 'Stripe Split Pay (modern) vendor ' . $vendor_id . ' charge declined for order ' . $order_id . ': [' . $e->getStripeCode() . '] ' . $e->getMessage(), 'error' );
			return $this->vendor_charge_failed( $ledger, $e->getMessage() );
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			wcfm_stripe_log( 'Stripe Split Pay (modern) vendor ' . $vendor_id . ' charge failed for order ' . $order_id . ': ' . get_class( $e ) . ' [' . $e->getStripeCode() . '] ' . $e->getMessage(), 'error' );
			return $this->vendor_charge_failed( $ledger, __( 'Payment could not be processed at this time. Please try again or use a different card.', 'wc-multivendor-marketplace' ) );
		}

		return $this->settle_vendor_intent( $intent, $ledger );
	}

	





















	private function create_vendor_intent( $order, $spec, $platform_pm, $customer_id, $currency, $on_session ) {
		$order_id  = $order->get_id();
		$client    = WCFMmp_Stripe_Client_Factory::client();
		$vendor_id = (int) $spec['vendor_id'];
		$amount    = (int) $spec['amount_minor'];
		$routing   = $spec['routing'];
		$attempt   = $on_session ? '-sca' : '';

		$params = array(
			'amount'               => $amount,
			'currency'             => strtolower( $currency ),
			'confirm'              => true,
			'off_session'          => ! $on_session,
			'payment_method_types' => array( 'card' ),
			'metadata'             => array(
				'order_id'  => $order_id,
				'vendor_id' => $vendor_id,
			),
		);
		if ( $on_session ) {
			 
			 
			$params['use_stripe_sdk'] = true;
		}

		$create_opts                    = ( 'direct' === $routing ) ? array( 'stripe_account' => $spec['account'] ) : array();
		$create_opts['idempotency_key'] = self::vendor_intent_idempotency_key( $order_id, $vendor_id, $amount, $currency, $platform_pm ) . $attempt;

		if ( 'direct' === $routing ) {
			 
			 
			$clone = $client->paymentMethods->create(
				array(
					'customer'       => $customer_id,
					'payment_method' => $platform_pm,
				),
				 
				 
				 
				 
				 
				 
				array(
					'stripe_account'  => $spec['account'],
					'idempotency_key' => 'wcfmmp-' . $order_id . '-v' . $vendor_id . '-clone-' . md5( $platform_pm ) . $attempt,
				)
			);
			$params['payment_method']         = $clone->id;
			$params['application_fee_amount'] = (int) $spec['application_fee_minor'];
		} elseif ( 'destination' === $routing ) {
			$params['customer']               = $customer_id;
			$params['payment_method']         = $platform_pm;
			$params['application_fee_amount'] = (int) $spec['application_fee_minor'];
			$params['transfer_data']          = array( 'destination' => $spec['account'] );
		} else {
			 
			$params['customer']       = $customer_id;
			$params['payment_method'] = $platform_pm;
		}

		






		$params = apply_filters( 'wcfmmp_stripe_modern_vendor_intent_args', $params, $order, $spec );

		return $client->paymentIntents->create( $params, $create_opts );
	}

	















	private function escalate_vendor_intent( $order, $spec, $platform_pm, $customer_id, $currency, $e, $ledger ) {
		$vendor_id = (int) $spec['vendor_id'];

		$err = $e->getError();
		if ( $err && ! empty( $err->payment_intent ) && 'requires_action' === $err->payment_intent->status && ! empty( $err->payment_intent->client_secret ) ) {
			return $this->settle_vendor_intent( $err->payment_intent, $ledger );
		}

		try {
			$intent = $this->create_vendor_intent( $order, $spec, $platform_pm, $customer_id, $currency, true );
		} catch ( \Stripe\Exception\ApiErrorException $sca ) {
			wcfm_stripe_log( 'Stripe Split Pay (modern) vendor ' . $vendor_id . ' on-session escalation failed for order ' . $order->get_id() . ': [' . $sca->getStripeCode() . '] ' . $sca->getMessage(), 'error' );
			return $this->vendor_charge_failed( $ledger, $sca->getMessage() );
		}

		return $this->settle_vendor_intent( $intent, $ledger );
	}

	








	private function settle_vendor_intent( $intent, $ledger ) {
		$ledger['intent'] = $intent->id;
		$ledger['status'] = $intent->status;
		$ledger['charge'] = is_string( $intent->latest_charge ) ? $intent->latest_charge : ( isset( $intent->latest_charge->id ) ? $intent->latest_charge->id : '' );

		if ( in_array( $intent->status, array( 'succeeded', 'requires_capture' ), true ) ) {
			return array( 'status' => 'succeeded', 'error' => '', 'client_secret' => '', 'ledger' => $ledger );
		}

		if ( 'requires_action' === $intent->status ) {
			return array(
				'status'        => 'requires_action',
				'error'         => '',
				'client_secret' => $intent->client_secret,
				'ledger'        => $ledger,
			);
		}

		 
		 
		return $this->vendor_charge_failed( $ledger, __( 'The payment could not be authenticated. Please try again or use a different card.', 'wc-multivendor-marketplace' ) );
	}

	






	private function vendor_charge_failed( $ledger, $error ) {
		$ledger['status'] = 'failed';
		return array(
			'status'        => 'failed',
			'error'         => $error,
			'client_secret' => '',
			'ledger'        => $ledger,
		);
	}

	






	private function rollback_setup_charges( $created ) {
		$client = WCFMmp_Stripe_Client_Factory::client();
		foreach ( $created as $c ) {
			$opts = ( 'direct' === $c['routing'] && ! empty( $c['account'] ) ) ? array( 'stripe_account' => $c['account'] ) : array();
			try {
				if ( in_array( $c['status'], array( 'succeeded', 'requires_capture' ), true ) ) {
					$refund = array( 'payment_intent' => $c['intent'] );
					if ( 'destination' === $c['routing'] ) {
						$refund['reverse_transfer'] = true;
					}
					$client->refunds->create( $refund, $opts );
				} else {
					$client->paymentIntents->cancel( $c['intent'], array(), $opts );
				}
			} catch ( \Stripe\Exception\ApiErrorException $e ) {
				wcfm_stripe_log( 'Stripe Split Pay (modern) rollback error for intent ' . $c['intent'] . ': ' . $e->getMessage(), 'error' );
			}
		}
	}

	








	private function handle_success_setup( $order, $plan, $ledger ) {
		global $WCFMmp;
		$distribution   = $this->distribution( $order, array() );
		$primary_charge = '';

		foreach ( $plan as $spec ) {
			$vendor_id = (int) $spec['vendor_id'];
			$routing   = $spec['routing'];
			$entry     = isset( $ledger[ $vendor_id ] ) ? $ledger[ $vendor_id ] : array();
			$charge_id = isset( $entry['charge'] ) ? $entry['charge'] : '';
			if ( ! $primary_charge && $charge_id ) {
				$primary_charge = $charge_id;
			}

			if ( 'platform' === $routing ) {
				$order->update_meta_data( 'wcfmmp_stripe_split_pay_charge_id_admin', $charge_id );
				$order->update_meta_data( 'wcfmmp_stripe_split_pay_charge_type_admin', 'platform' );
				continue;
			}

			$order->update_meta_data( 'wcfmmp_stripe_split_pay_charge_id_' . $vendor_id, $charge_id );
			$order->update_meta_data( 'wcfmmp_stripe_split_pay_charge_type_' . $vendor_id, $routing );

			$info = isset( $distribution[ $vendor_id ] ) ? $distribution[ $vendor_id ] : array();

			if ( 'held' === $routing ) {
				$withdrawal_id = $this->create_withdrawal_row( $order, $vendor_id, $info, 'pending', $routing, '' );
				$WCFMmp->wcfmmp_withdraw->wcfmmp_update_withdrawal_meta( $withdrawal_id, 'held_reason', $spec['held_reason'] );
				if ( class_exists( 'WCFMmp_Stripe_Preflight' ) ) {
					WCFMmp_Stripe_Preflight::notify_held( $order, $vendor_id, $spec['held_reason'] );
				}
			} else {
				 
				$this->create_withdrawal_row( $order, $vendor_id, $info, 'completed', $routing, $charge_id );
			}
		}

		$order->payment_complete( $primary_charge );
		$order->save();
	}

	








	private function vendor_booked_commission( $order_id, $vendor_id ) {
		global $wpdb;

		return (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(total_commission) FROM {$wpdb->prefix}wcfm_marketplace_orders WHERE order_id = %d AND vendor_id = %d", $order_id, $vendor_id ) );
	}

	












	private function create_withdrawal_row( $order, $vendor_id, $info, $status, $charge_type, $transaction_id ) {
		global $WCFMmp, $wpdb;
		$order_id = $order->get_id();

		$commission_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->prefix}wcfm_marketplace_orders WHERE order_id = %d AND vendor_id = %d", $order_id, $vendor_id ) );
		$re_commission  = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(total_commission) FROM {$wpdb->prefix}wcfm_marketplace_orders WHERE order_id = %d AND vendor_id = %d", $order_id, $vendor_id ) );
		$gross_sales    = isset( $info['gross_sales'] ) ? $info['gross_sales'] : 0;

		$withdrawal_id = $WCFMmp->wcfmmp_withdraw->wcfmmp_withdrawal_processed( $vendor_id, $order_id, implode( ',', $commission_ids ), 'stripe_split', $gross_sales, $re_commission, 0, 'pending', 'by_split_pay', 0 );

		$WCFMmp->wcfmmp_withdraw->wcfmmp_update_withdrawal_meta( $withdrawal_id, 'withdraw_amount', $re_commission );
		$WCFMmp->wcfmmp_withdraw->wcfmmp_update_withdrawal_meta( $withdrawal_id, 'currency', $order->get_currency() );
		$WCFMmp->wcfmmp_withdraw->wcfmmp_update_withdrawal_meta( $withdrawal_id, 'transaction_type', $charge_type );

		if ( 'completed' === $status ) {
			if ( $transaction_id ) {
				$order->update_meta_data( 'wcfmmp_stripe_split_pay_transaction_id_' . $vendor_id, $transaction_id );
				$WCFMmp->wcfmmp_withdraw->wcfmmp_update_withdrawal_meta( $withdrawal_id, 'transaction_id', $transaction_id );
			}
			$WCFMmp->wcfmmp_withdraw->wcfmmp_withdraw_status_update_by_withdrawal( $withdrawal_id, 'completed', __( 'Stripe Split Pay', 'wc-multivendor-marketplace' ) );
			do_action( 'wcfmmp_withdrawal_request_approved', $withdrawal_id );
		}

		return $withdrawal_id;
	}
}
