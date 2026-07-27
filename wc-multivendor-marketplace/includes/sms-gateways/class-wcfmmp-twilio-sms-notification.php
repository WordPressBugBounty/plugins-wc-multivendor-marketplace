<?php
/**
 * WooCommerce Twilio SMS Notifications
 *
 * This source file is subject to the GNU General Public License v3.0
 * that is bundled with this package in the file license.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.html
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@skyverge.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade WooCommerce Twilio SMS Notifications to newer
 * versions in the future. If you wish to customize WooCommerce Twilio SMS Notifications for your
 * needs please refer to http://docs.woocommerce.com/document/twilio-sms-notifications/ for more information.
 *
 * @package     WC-Twilio-SMS-Notifications/Notification
 * @author      SkyVerge
 * @copyright   Copyright (c) 2013-2018, SkyVerge, Inc.
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

defined( 'ABSPATH' ) or exit;

use SkyVerge\WooCommerce\PluginFramework\v5_3_1 as Framework;








class WCFMmp_Twilio_SMS_Notification  {

	 
	private $order;


	





	public function __construct( $order_id ) {

		$this->order = wc_get_order( $order_id );

	}


	




	public function send_admin_notification() {

		 
		if ( 'yes' === get_option( 'wc_twilio_sms_enable_admin_sms' ) ) {

			 
			$message = get_option( 'wc_twilio_sms_admin_sms_template', '' );

			 
			$message = $this->replace_message_variables( $message );

			 
			if ( WC_Twilio_SMS_URL_Shortener::using_shortened_urls() ) {
				$message = WC_Twilio_SMS_URL_Shortener::shorten_urls( $message );
			}

			 
			$recipients = explode( ',', trim( get_option( 'wc_twilio_sms_admin_sms_recipients' ) ) );

			 
			if ( ! empty( $recipients ) ) {

				foreach ( $recipients as $recipient ) {

					try {

						wc_twilio_sms()->get_api()->send( $recipient, $message, false );

					} catch ( Exception $e ) {

						wc_twilio_sms()->log( $e->getMessage() );
					}
				}
			}
		}
	}


	




	public function send_automated_customer_notification() {

		 
		$optin = get_option( 'wc_twilio_sms_checkout_optin_checkbox_label', '' );

		 
		if ( ! empty( $optin ) ) {

			 
			$optin = Framework\SV_WC_Order_Compatibility::get_meta( $this->order, '_wc_twilio_sms_optin', true );

			 
			if ( empty( $optin ) ) {
				 
				return;
			}
		}

		 
		if ( in_array( 'wc-' . $this->order->get_status(), get_option( 'wc_twilio_sms_send_sms_order_statuses' ) ) ) {

			 
			$message = get_option( 'wc_twilio_sms_' . $this->order->get_status() . '_sms_template', '' );

			 
			if ( empty( $message ) ) {
				$message = get_option( 'wc_twilio_sms_default_sms_template' );
			}

			 
			$message = apply_filters( 'wc_twilio_sms_customer_sms_before_variable_replace', $message, $this->order );

			 
			$message = $this->replace_message_variables( $message );

			 
			$message = apply_filters( 'wc_twilio_sms_customer_sms_after_variable_replace', $message, $this->order );

			 
			$phone = apply_filters( 'wc_twilio_sms_customer_phone', Framework\SV_WC_Order_Compatibility::get_prop( $this->order, 'billing_phone' ), $this->order );

			 
			if ( WC_Twilio_SMS_URL_Shortener::using_shortened_urls() ) {
				$message = WC_Twilio_SMS_URL_Shortener::shorten_urls( $message );
			}

			 
			$this->send_sms( $phone, $message );
		}
	}


	





	public function send_manual_customer_notification( $message ) {

		 
		if ( WC_Twilio_SMS_URL_Shortener::using_shortened_urls() ) {
			$message = WC_Twilio_SMS_URL_Shortener::shorten_urls( $message );
		}

		 
		$this->send_sms( Framework\SV_WC_Order_Compatibility::get_prop( $this->order, 'billing_phone' ), $message );
	}


	







	public function send_sms( $to, $message, $customer_notification = true, $base_country = '' ) {

		 
		$status = __( 'Sent', 'woocommerce-twilio-sms-notifications' );

		 
		$sent_timestamp =  time();

		 
		$error = false;

		try {

			 
			$response = wc_twilio_sms()->get_api()->send( $to, $message, $base_country );

			 
			$sent_timestamp = ( isset( $response['date_created'] ) ) ? strtotime( $response['date_created'] ) : $sent_timestamp;

			 
			$to = ( isset( $response['to'] ) ) ? $response['to'] : $to;
			
			 

		} catch ( Exception $e ) {

			 
			$status = $e->getMessage();

			 
			$error = true;
			
			 
			wcfm_log( $to . ":error:" . $message );

			 
			wc_twilio_sms()->log( $e->getMessage() );
		}

		 
		if ( $customer_notification ) {
			 
		}
	}


	






	public function replace_message_variables( $message ) {

		$replacements = array(
			'%shop_name%'       => Framework\SV_WC_Helper::get_site_name(),
			'%order_id%'        => $this->order->get_order_number(),
			'%order_count%'     => $this->order->get_item_count(),
			'%order_amount%'    => $this->order->get_total(),
			'%order_status%'    => ucfirst( $this->order->get_status() ),
			'%billing_name%'    => $this->order->get_formatted_billing_full_name(),
			'%shipping_name%'   => $this->order->get_formatted_shipping_full_name(),
			'%shipping_method%' => $this->order->get_shipping_method(),
			'%billing_first%'   => Framework\SV_WC_Order_Compatibility::get_prop( $this->order, 'billing_first_name' ),
			'%billing_last%'    => Framework\SV_WC_Order_Compatibility::get_prop( $this->order, 'billing_last_name' ),
		);

		

















		$replacements = apply_filters( 'wc_twilio_sms_message_replacements', $replacements, $this );

		return str_replace( array_keys( $replacements ), $replacements, $message );
	}


	










	public function format_order_note( $to, $sent_timestamp, $message, $status, $error ) {

		try {

			 
			$datetime = new DateTime( "@{$sent_timestamp}", new DateTimeZone( 'UTC' ) );

			 
			$datetime->setTimezone( new DateTimeZone( wc_timezone_string() ) );

			 
			$formatted_datetime = date_i18n( wc_date_format() . ' ' . wc_time_format(), $sent_timestamp + $datetime->getOffset() );

		} catch ( Exception $e ) {

			 
			wc_twilio_sms()->log( $e->getMessage() );
			$formatted_datetime = __( 'N/A', 'woocommerce-twilio-sms-notifications' );
		}

		ob_start();
		?>
		<p><strong><?php esc_html_e( 'SMS Notification', 'woocommerce-twilio-sms-notifications' ); ?></strong></p>
		<p><strong><?php esc_html_e( 'To', 'woocommerce-twilio-sms-notifications' ); ?>: </strong><?php echo esc_html( $to ); ?></p>
		<p><strong><?php esc_html_e( 'Date Sent', 'woocommerce-twilio-sms-notifications' ); ?>: </strong><?php echo esc_html( $formatted_datetime ); ?></p>
		<p><strong><?php esc_html_e( 'Message', 'woocommerce-twilio-sms-notifications' ); ?>: </strong><?php echo esc_html( $message ); ?></p>
		<p><strong><?php esc_html_e( 'Status', 'woocommerce-twilio-sms-notifications' ); ?>: <span style="<?php echo ( $error ) ? 'color: red;' : 'color: green;'; ?>"><?php echo esc_html( $status ); ?></span></strong></p>
		<?php

		return ob_get_clean();
	}


}
