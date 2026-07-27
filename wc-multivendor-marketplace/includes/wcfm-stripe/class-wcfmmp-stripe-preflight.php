<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

















final class WCFMmp_Stripe_Preflight {

	




	public static function corridor_countries() {
		return apply_filters( 'wcfmmp_stripe_transfer_corridor_countries', array(
			'US', 'CA', 'GB', 'CH',
			 
			'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU',
			'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES',
			'SE', 'IS', 'LI', 'NO',
		) );
	}

	






	public static function cross_border_payout_countries() {
		$codes = array(
			'AL', 'DZ', 'AO', 'AG', 'AR', 'AM', 'AU', 'AT', 'AZ', 'BS', 'BH', 'BD', 'BE',
			'BJ', 'BT', 'BO', 'BA', 'BW', 'BN', 'BG', 'KH', 'CA', 'CL', 'CO', 'CR', 'CI',
			'HR', 'CY', 'CZ', 'DK', 'DO', 'EC', 'EG', 'SV', 'EE', 'ET', 'FI', 'FR', 'GA',
			'GM', 'DE', 'GH', 'GR', 'GT', 'GY', 'HK', 'HU', 'IS', 'IN', 'ID', 'IE', 'IL',
			'IT', 'JM', 'JP', 'JO', 'KZ', 'KE', 'KW', 'LA', 'LV', 'LI', 'LT', 'LU', 'MO',
			'MG', 'MY', 'MT', 'MU', 'MX', 'MD', 'MC', 'MN', 'MA', 'MZ', 'NA', 'NL', 'NZ',
			'NE', 'NG', 'MK', 'NO', 'OM', 'PK', 'PA', 'PY', 'PE', 'PH', 'PL', 'PT', 'QA',
			'RO', 'RW', 'SM', 'SA', 'SN', 'RS', 'SG', 'SK', 'SI', 'ZA', 'KR', 'ES', 'LK',
			'LC', 'SE', 'CH', 'TW', 'TZ', 'TH', 'TT', 'TN', 'TR', 'AE', 'GB', 'UY', 'UZ',
			'VN',
		);
		 
		 
		$map = apply_filters( 'wcfm_stripe_cross_border_supported_countries', array_combine( $codes, $codes ) );
		return array_keys( (array) $map );
	}

	






	public static function direct_charge_countries() {
		$codes = array(
			'AU', 'AT', 'BE', 'BG', 'CA', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE',
			'GI', 'GR', 'HK', 'HU', 'IE', 'IT', 'JP', 'LV', 'LI', 'LT', 'LU', 'MT', 'MX',
			'NL', 'NZ', 'NO', 'PL', 'PT', 'RO', 'SG', 'SK', 'SI', 'ES', 'SE', 'CH', 'TH',
			'AE', 'GB', 'US',
		);
		$map = apply_filters( 'wcfm_stripe_supported_direct_charge_countries', array_combine( $codes, $codes ) );
		return array_keys( (array) $map );
	}

	



	public static function platform_cache() {
		$cache = get_option( WCFMmp_Stripe_Client_Factory::PLATFORM_CACHE_OPTION, array() );
		return is_array( $cache ) ? $cache : array();
	}

	public static function platform_country() {
		$cache = self::platform_cache();
		return isset( $cache['country'] ) ? $cache['country'] : '';
	}

	public static function platform_currency() {
		$cache = self::platform_cache();
		return isset( $cache['currency'] ) ? $cache['currency'] : '';
	}

	







	public static function validate_vendor( $vendor_id, $order_currency, $routing ) {
		$vendor_id = (int) $vendor_id;
		$result    = array( 'pass' => true, 'reason' => 'ok' );

		$account   = get_user_meta( $vendor_id, 'stripe_user_id', true );
		$connected = get_user_meta( $vendor_id, 'vendor_connected', true );
		if ( ! $account || ! $connected ) {
			$result = array( 'pass' => false, 'reason' => 'not_connected' );
			return apply_filters( 'wcfmmp_stripe_preflight_verdict', $result, $vendor_id, $order_currency, $routing );
		}

		if ( 'no' === get_user_meta( $vendor_id, 'stripe_payouts_enabled', true ) ) {
			$result = array( 'pass' => false, 'reason' => 'payouts_disabled' );
			return apply_filters( 'wcfmmp_stripe_preflight_verdict', $result, $vendor_id, $order_currency, $routing );
		}

		 
		$capabilities = get_user_meta( $vendor_id, 'stripe_account_capabilities', true );
		if ( is_object( $capabilities ) ) {
			if ( isset( $capabilities->transfers ) && 'active' !== $capabilities->transfers ) {
				$result = array( 'pass' => false, 'reason' => 'transfers_inactive' );
				return apply_filters( 'wcfmmp_stripe_preflight_verdict', $result, $vendor_id, $order_currency, $routing );
			}
			if ( 'direct_charges' === $routing && isset( $capabilities->card_payments ) && 'active' !== $capabilities->card_payments ) {
				$result = array( 'pass' => false, 'reason' => 'card_payments_inactive' );
				return apply_filters( 'wcfmmp_stripe_preflight_verdict', $result, $vendor_id, $order_currency, $routing );
			}
		}

		 
		 
		$agreement = get_user_meta( $vendor_id, 'stripe_service_agreement', true );
		if ( 'recipient' === $agreement && 'transfers_charges' !== $routing ) {
			$result = array( 'pass' => false, 'reason' => 'recipient_sa' );
			return apply_filters( 'wcfmmp_stripe_preflight_verdict', $result, $vendor_id, $order_currency, $routing );
		}

		 
		 
		$platform_country = self::platform_country();
		if ( ! $platform_country ) {
			self::log_once( 'unknown_platform_country', 'Stripe pre-flight: platform account country unknown (cache not populated); corridor check skipped.' );
			$result['reason'] = 'unknown_platform_country';
		} else {
			$vendor_country = get_user_meta( $vendor_id, 'stripe_account_country', true );
			if ( $vendor_country ) {
				$corridor  = self::corridor_countries();
				$same      = ( $vendor_country === $platform_country );
				$in_set    = in_array( $vendor_country, $corridor, true ) && in_array( $platform_country, $corridor, true );
				$recipient = ( 'recipient' === $agreement && 'US' === $platform_country && in_array( $vendor_country, self::cross_border_payout_countries(), true ) );
				if ( ! $same && ! $in_set && ! $recipient ) {
					$result = array( 'pass' => false, 'reason' => 'corridor_blocked' );
					return apply_filters( 'wcfmmp_stripe_preflight_verdict', $result, $vendor_id, $order_currency, $routing );
				}
			}
		}

		 
		 
		if ( 'transfers_charges' === $routing ) {
			$platform_currency = self::platform_currency();
			if ( $platform_currency && strtoupper( $order_currency ) !== strtoupper( $platform_currency ) ) {
				$result = array( 'pass' => false, 'reason' => 'currency_mismatch' );
				return apply_filters( 'wcfmmp_stripe_preflight_verdict', $result, $vendor_id, $order_currency, $routing );
			}
			if ( ! $platform_currency ) {
				self::log_once( 'unknown_platform_currency', 'Stripe pre-flight: platform settlement currency unknown (cache not populated); currency check skipped.' );
			}
		}

		return apply_filters( 'wcfmmp_stripe_preflight_verdict', $result, $vendor_id, $order_currency, $routing );
	}

	









	public static function assess_order( $distribution, $order_currency, $charge_type ) {
		$demoted = false;
		$held    = array();

		if ( 'transfers_charges' !== $charge_type ) {
			foreach ( $distribution as $vendor_id => $info ) {
				$verdict = self::validate_vendor( (int) $vendor_id, $order_currency, $charge_type );
				if ( ! $verdict['pass'] ) {
					$charge_type = 'transfers_charges';
					$demoted     = true;
					break;
				}
			}
		}

		if ( 'transfers_charges' === $charge_type ) {
			foreach ( $distribution as $vendor_id => $info ) {
				$verdict = self::validate_vendor( (int) $vendor_id, $order_currency, 'transfers_charges' );
				if ( ! $verdict['pass'] ) {
					$held[ (int) $vendor_id ] = $verdict['reason'];
				}
			}
		}

		return array( 'charge_type' => $charge_type, 'demoted' => $demoted, 'held' => $held );
	}

	








	public static function notify_held( $order, $vendor_id, $reason ) {
		global $WCFM;

		$order_id = $order->get_id();

		






		do_action( 'wcfmmp_stripe_vendor_held', $order_id, $vendor_id, $reason );

		if ( ! $WCFM || ! isset( $WCFM->wcfm_notification ) ) {
			return;
		}

		$order_link = '<a target="_blank" class="wcfm_dashboard_item_title" href="' . get_wcfm_view_order_url( $order_id ) . '">#' . $order->get_order_number() . '</a>';

		$vendor_message = sprintf( __( 'Your payout for order %s is on hold on the marketplace (reason: %s). The site admin has been notified.', 'wc-multivendor-marketplace' ), $order_link, $reason );
		$raw_vendor     = array(
			'l10n' => array(
				'text'    => 'Your payout for order %s is on hold on the marketplace (reason: %s). The site admin has been notified.',
				'domain'  => 'wc-multivendor-marketplace',
				'wrapper' => array( 'function' => 'sprintf', 'args' => array( $order_link, $reason ) ),
			),
		);
		$WCFM->wcfm_notification->wcfm_send_direct_message( -1, $vendor_id, 1, 0, $vendor_message, 'withdraw-request', true, $raw_vendor );

		$store_name    = function_exists( 'wcfm_get_vendor_store_name' ) ? wcfm_get_vendor_store_name( $vendor_id ) : ( '#' . $vendor_id );
		$admin_message = sprintf( __( 'Stripe Split Pay held the payout for %s on order %s (reason: %s). Review it under withdrawal requests.', 'wc-multivendor-marketplace' ), $store_name, $order_link, $reason );
		$raw_admin     = array(
			'l10n' => array(
				'text'    => 'Stripe Split Pay held the payout for %s on order %s (reason: %s). Review it under withdrawal requests.',
				'domain'  => 'wc-multivendor-marketplace',
				'wrapper' => array( 'function' => 'sprintf', 'args' => array( $store_name, $order_link, $reason ) ),
			),
		);
		$WCFM->wcfm_notification->wcfm_send_direct_message( -1, 0, 1, 0, $admin_message, 'withdraw-request', true, $raw_admin );
	}

	


	private static function log_once( $flag, $message ) {
		$transient = 'wcfmmp_stripe_preflight_' . $flag;
		if ( get_transient( $transient ) ) {
			return;
		}
		wcfm_stripe_log( $message, 'error' );
		set_transient( $transient, 1, DAY_IN_SECONDS );
	}
}
