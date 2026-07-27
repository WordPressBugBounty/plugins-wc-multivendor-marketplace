<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __FILE__ ) . '/class-wcfm-stripe-exception.php';






class WCFM_Stripe_API {

	


	const ENDPOINT           = 'https://api.stripe.com/v1/';
	const STRIPE_API_VERSION = '2019-02-19';

	



	private static $secret_key = '';

	



	public static function set_secret_key( $secret_key ) {
		self::$secret_key = $secret_key;
	}

	



	public static function get_secret_key() {
		global $WCFMmp;
		if ( ! self::$secret_key ) {
			$options = $WCFMmp->wcfmmp_withdrawal_options;

			if ( isset( $options['testmode'], $options['secret_key'], $options['test_secret_key'] ) ) {
				self::set_secret_key( 'yes' === $options['testmode'] ? $options['test_secret_key'] : $options['secret_key'] );
			}
		}
		return self::$secret_key;
	}

	






	public static function get_user_agent() {
		$app_info = array(
			'name'    => 'Marketplace Stripe Split Pay',
			'version' => WCFMmp_VERSION,
			'url'     => 'https://wclovers.com',
		);

		return array(
			'lang'         => 'php',
			'lang_version' => phpversion(),
			'publisher'    => 'WC Lovers',
			'uname'        => php_uname(),
			'application'  => $app_info,
		);
	}

	





	public static function get_headers() {
		$user_agent = self::get_user_agent();
		$app_info   = $user_agent['application'];

		return apply_filters(
			'woocommerce_stripe_request_headers',
			array(
				'Authorization'              => 'Basic ' . base64_encode( self::get_secret_key() . ':' ),
				'Stripe-Version'             => self::STRIPE_API_VERSION,
				'User-Agent'                 => $app_info['name'] . '/' . $app_info['version'] . ' (' . $app_info['url'] . ')',
				'X-Stripe-Client-User-Agent' => json_encode( $user_agent ),
			)
		);
	}

	









	public static function request( $request, $api = 'charges', $method = 'POST', $with_headers = false ) {
		 

		$headers         = self::get_headers();
		$idempotency_key = '';

		if ( 'charges' === $api && 'POST' === $method ) {
			$customer        = ! empty( $request['customer'] ) ? $request['customer'] : '';
			$source          = ! empty( $request['source'] ) ? $request['source'] : $customer;
			$idempotency_key = apply_filters( 'wc_stripe_idempotency_key', $request['metadata']['order_id'] . '-' . $source, $request );

			$headers['Idempotency-Key'] = $idempotency_key;
		}

		$response = wp_safe_remote_post(
			self::ENDPOINT . $api,
			array(
				'method'  => $method,
				'headers' => $headers,
				'body'    => apply_filters( 'woocommerce_stripe_request_body', $request, $api ),
				'timeout' => 70,
			)
		);

		if ( is_wp_error( $response ) || empty( $response['body'] ) ) {
			










			throw new WCFM_Stripe_Exception( print_r( $response, true ), __( 'There was a problem connecting to the Stripe API endpoint.', 'wc-multivendor-marketplace' ) );
		}

		if ( $with_headers ) {
			return array(
				'headers' => wp_remote_retrieve_headers( $response ),
				'body'    => json_decode( $response['body'] ),
			);
		}

		return json_decode( $response['body'] );
	}

	






	public static function retrieve( $api ) {
		 

		$response = wp_safe_remote_get(
			self::ENDPOINT . $api,
			array(
				'method'  => 'GET',
				'headers' => self::get_headers(),
				'timeout' => 70,
			)
		);

		if ( is_wp_error( $response ) || empty( $response['body'] ) ) {
			 
			return new WP_Error( 'stripe_error', __( 'There was a problem connecting to the Stripe API endpoint.', 'wc-multivendor-marketplace' ) );
		}

		return json_decode( $response['body'] );
	}
}
