<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}










class WCFMmp_Stripe_Environment {

	








	public static function forced_legacy() {
		$forced = ! WCFMmp_Stripe_Client_Factory::is_sdk_supported();

		if ( defined( 'WCFMMP_STRIPE_FORCE_LEGACY' ) && WCFMMP_STRIPE_FORCE_LEGACY ) {
			$forced = true;
		}

		




		return (bool) apply_filters( 'wcfmmp_stripe_force_legacy_engine', $forced );
	}

	




	public static function forced_legacy_source() {
		if ( ! WCFMmp_Stripe_Client_Factory::is_sdk_supported() ) {
			return 'sdk_floor';
		}
		if ( defined( 'WCFMMP_STRIPE_FORCE_LEGACY' ) && WCFMMP_STRIPE_FORCE_LEGACY ) {
			return 'constant';
		}
		if ( apply_filters( 'wcfmmp_stripe_force_legacy_engine', false ) ) {
			return 'filter';
		}
		return '';
	}

	






	public static function maybe_flag_environment() {
		if ( WCFMmp_Stripe_Client_Factory::is_sdk_supported() ) {
			return;
		}
		if ( ! self::is_gateway_enabled() ) {
			return;
		}
		add_action( 'admin_notices', array( __CLASS__, 'render_sdk_notice' ) );
	}

	




	public static function is_gateway_enabled() {
		$settings = get_option( 'woocommerce_stripe_split_settings', array() );
		return isset( $settings['enabled'] ) && 'yes' === $settings['enabled'];
	}

	


	public static function render_sdk_notice() {
		if ( ! class_exists( '\Stripe\Stripe' ) ) {
			return;
		}

		$reflection = new ReflectionClass( '\Stripe\Stripe' );
		$sdk_path   = $reflection->getFileName();
		$sdk_ver    = \Stripe\Stripe::VERSION;

		echo '<div class="notice notice-warning is-dismissible"><p><strong>' . esc_html__( 'WCFM Marketplace - Stripe Split Pay', 'wc-multivendor-marketplace' ) . ':</strong> ';
		 
		echo sprintf( esc_html__( 'another plugin loaded stripe-php %1$s from %2$s, which is older than the version the modern payment engine requires. Split Pay will run in legacy mode until that plugin is updated or deactivated.', 'wc-multivendor-marketplace' ), esc_html( $sdk_ver ), '<code>' . esc_html( $sdk_path ) . '</code>' );
		echo '</p></div>';
	}
}

add_action( 'admin_init', array( 'WCFMmp_Stripe_Environment', 'maybe_flag_environment' ) );
