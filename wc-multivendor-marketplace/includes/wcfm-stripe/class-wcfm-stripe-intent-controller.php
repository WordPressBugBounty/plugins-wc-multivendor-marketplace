<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}






class WC_Stripe_Intent_Controller {
	





	protected $gateway;

	




	public function __construct() {
		add_action( 'wc_ajax_wc_stripe_verify_intent', array( $this, 'verify_intent' ) );
	}

	





	protected function get_gateway() {
		if ( ! isset( $this->gateway ) ) {
			if ( class_exists( 'WC_Subscriptions_Order' ) && function_exists( 'wcs_create_renewal_order' ) ) {
				$class_name = 'WC_Stripe_Subs_Compat';
			} else {
				$class_name = 'WC_Gateway_Stripe';
			}

			$this->gateway = new $class_name();
		}

		return $this->gateway;
	}

	






	protected function get_order_from_request() {
		if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['nonce'] ), 'wc_stripe_confirm_pi' ) ) {
			throw new WCFM_Stripe_Exception( 'missing-nonce', __( 'CSRF verification failed.', 'wc-multivendor-marketplace' ) );
		}

		 
		$order_id = null;
		if ( isset( $_GET['order'] ) && absint( $_GET['order'] ) ) {
			$order_id = absint( $_GET['order'] );
		}

		 
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			throw new WCFM_Stripe_Exception( 'missing-order', __( 'Missing order ID for payment confirmation', 'wc-multivendor-marketplace' ) );
		}

		return $order;
	}

	




	public function verify_intent() {
		global $woocommerce;

		$gateway = $this->get_gateway();

		try {
			$order = $this->get_order_from_request();
		} catch ( WCFM_Stripe_Exception $e ) {
			 
			$message = sprintf( __( 'Payment verification error: %s', 'wc-multivendor-marketplace' ), $e->getLocalizedMessage() );
			wc_add_notice( esc_html( $message ), 'error' );

			$redirect_url = $woocommerce->cart->is_empty()
				? get_permalink( wc_get_page_id( 'shop' ) )
				: wc_get_checkout_url();

			$this->handle_error( $e, $redirect_url );
		}

		try {
			$gateway->verify_intent_after_checkout( $order );

			if ( ! isset( $_GET['is_ajax'] ) ) {
				$redirect_url = isset( $_GET['redirect_to'] )  
					? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) )  
					: $gateway->get_return_url( $order );

				wp_safe_redirect( $redirect_url );
			}

			exit;
		} catch ( WCFM_Stripe_Exception $e ) {
			$this->handle_error( $e, $gateway->get_return_url( $order ) );
		}
	}

	






	protected function handle_error( $e, $redirect_url ) {
		 
		$message = sprintf( 'PaymentIntent verification exception: %s', $e->getLocalizedMessage() );
		WC_Stripe_Logger::log( $message );

		 
		if ( isset( $_GET['is_ajax'] ) ) {
			exit;
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}
}

new WC_Stripe_Intent_Controller();
