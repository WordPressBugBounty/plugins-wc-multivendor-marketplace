<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}











final class WCFMmp_Stripe_Client_Factory {

	





	const API_VERSION = '2026-05-27.dahlia';

	




	const MIN_SDK_VERSION = '13.0';

	



	const PLATFORM_CACHE_OPTION = 'wcfmmp_stripe_platform_cache';

	




	private static $client = null;

	







	




	public static function ensure_sdk() {
		global $WCFMmp;
		if ( ! class_exists( '\Stripe\StripeClient' ) && $WCFMmp && file_exists( $WCFMmp->plugin_path . 'includes/Stripe/init.php' ) ) {
			require_once $WCFMmp->plugin_path . 'includes/Stripe/init.php';
		}
	}

	public static function client() {
		global $WCFMmp;

		if ( null !== self::$client ) {
			return self::$client;
		}

		self::ensure_sdk();

		$options = ( $WCFMmp && ! empty( $WCFMmp->wcfmmp_withdrawal_options ) ) ? $WCFMmp->wcfmmp_withdrawal_options : get_option( 'wcfm_withdrawal_options', array() );
		$options = is_array( $options ) ? $options : array();

		$secret_key = isset( $options['stripe_secret_key'] ) ? $options['stripe_secret_key'] : '';
		if ( isset( $options['test_mode'] ) ) {
			$secret_key = isset( $options['stripe_test_secret_key'] ) ? $options['stripe_test_secret_key'] : '';
		}

		self::$client = new \Stripe\StripeClient( array(
			'api_key'             => $secret_key,
			'stripe_version'      => self::API_VERSION,
			'max_network_retries' => 2,
		) );

		return self::$client;
	}

	





	public static function is_sdk_supported() {
		return class_exists( '\Stripe\Stripe' ) && class_exists( '\Stripe\StripeClient' ) && version_compare( \Stripe\Stripe::VERSION, self::MIN_SDK_VERSION, '>=' );
	}
}
