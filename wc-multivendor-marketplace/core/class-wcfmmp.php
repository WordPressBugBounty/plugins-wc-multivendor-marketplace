<?php











use Automattic\WooCommerce\Utilities\OrderUtil;

class WCFMmp {

	public $plugin_base_name;
	public $plugin_url;
	public $plugin_path;
	public $version;
	public $token;
	public $text_domain;
	public $vendor_id;
	public $library;
	public $template;
	public $shortcode;
	public $admin;
	public $frontend;
	public $ajax;
	private $file;
	public $wcfmmp_fields;
	public $wcfmmp_rewrite;
	public $wcfmmp_settings;
	public $wcfmmp_notification_manager;
	public $wcfmmp_commission;
	public $wcfmmp_withdraw;
	public $wcfmmp_refund;
	public $wcfmmp_reviews;
	public $wcfmmp_store;
	public $wcfmmp_store_seo;
	public $wcfmmp_vendor;
	public $wcfmmp_product;
	public $wcfmmp_emails;
	public $wcfmmp_shipping;
	public $wcfmmp_shipping_gateways;
	public $wcfmmp_shipping_zone;
	public $wcfmmp_gateways;
	public $wcfmmp_abstract_gateway;
	public $wcfmmp_product_multivendor;
	public $wcfmmp_non_ajax;
	public $wcfmmp_media;
	public $wcfmmp_sidebar_widgets;
	public $wcfmmp_shortcodes;
	public $wcfmmp_ledger;
	public $wcfmmp_store_hours;
	public $wcfm_store_url;
	public $wcfmmp_marketplace_options;
	public $wcfmmp_commission_options;
	public $wcfmmp_withdrawal_options;
	public $wcfmmp_review_options;
	public $wcfmmp_refund_options;
	public $wcfmmp_notification_options;
	public $wcfmmp_store_endpoints;
	public $head_titlse_set = false;
	public $wcfm_is_store_close = false;
	public $store_template_loaded = false;
	public $store_query_filtered = false;
	public $refund_processed = false;

	public function __construct($file) {

		$this->file = $file;
		$this->plugin_base_name = plugin_basename($file);
		$this->plugin_url = trailingslashit(plugins_url('', $plugin = $file));
		$this->plugin_path = trailingslashit(dirname($file));
		$this->token = WCFMmp_TOKEN;
		$this->text_domain = WCFMmp_TEXT_DOMAIN;
		$this->version = WCFMmp_VERSION;

		 
		add_action('init', array(&$this, 'init_plugin'), 0);
		
		add_action('init', array(&$this, 'init'), 8);

		 
		add_action('init', array(&$this, 'run_wcfmmp_installer'));

		add_action('wcfm_init', array(&$this, 'init_wcfmmp'), 11);

		add_action('woocommerce_loaded', array($this, 'load_wcfmmp'));

		add_filter('wcfm_modules',  array(&$this, 'get_wcfmmp_modules'));

		 
		add_filter('wcs_renewal_order_created', array(&$this, 'wcfmmp_renewal_order_processed'), 20, 2);

		 
		add_action('wcfmmp_withdrawal_periodic_scheduler', array(&$this, 'wcfmmp_withdrawal_periodic_scheduler_check'));

		 
		add_action('wcfmmp_data_cleanup_periodic_scheduler', array(&$this, 'wcfmmp_data_cleanup_periodic_scheduler_check'));

		add_action( 'plugins_loaded', array($this, 'load_stripe_split_pay_gateway_class') );
	}

	public function init_plugin() {
		 
		$this->load_plugin_textdomain();
	}

	


	public function load_stripe_split_pay_gateway_class() {
		global $WCFMmp;

		$this->setup_properties();

		$wcfm_withdrawal_options = (array) get_option( 'wcfm_withdrawal_options', [] );
		$active_payment_methods = $wcfm_withdrawal_options['payment_methods'] ?? [];

		if (empty($active_payment_methods)) {
			return;
		}

		$payment_method = 'stripe_split';

		if(in_array( $payment_method, $active_payment_methods )) {
			$gateway = 'WCFMmp_Gateway_' . ucfirst($payment_method);

			if( !class_exists( $gateway ) ) {
				$file_name = $WCFMmp->plugin_path . "includes/payment-gateways/class-wcfmmp-gateway-{$payment_method}.php";
				
				if( file_exists( $file_name ) ) {
					require_once ( $file_name );
				}

			 
			 
			 
			 
			$wcfmmp_stripe_lib = $WCFMmp->plugin_path . 'includes/wcfm-stripe/';
			foreach( array( 'class-wcfmmp-stripe-client-factory', 'class-wcfmmp-stripe-payment-engine', 'class-wcfmmp-stripe-transfer-queue', 'class-wcfmmp-stripe-preflight', 'class-wcfmmp-stripe-webhook-handler', 'class-wcfmmp-stripe-diagnostics' ) as $wcfmmp_stripe_class ) {
				if( file_exists( $wcfmmp_stripe_lib . $wcfmmp_stripe_class . '.php' ) ) {
					require_once( $wcfmmp_stripe_lib . $wcfmmp_stripe_class . '.php' );
				}
			}
			if( class_exists( 'WCFMmp_Stripe_Webhook_Handler' ) ) {
				WCFMmp_Stripe_Webhook_Handler::init();
			}
			}
		}
	}

	


	public function setup_properties() {
		$this->vendor_id = apply_filters('wcfm_current_vendor_id', get_current_user_id());

		if (function_exists('wcfm_get_option')) {
			$this->wcfm_store_url               = wcfm_get_option('wcfm_store_url', 'store');
			$this->wcfmmp_marketplace_options   = wcfm_get_option('wcfm_marketplace_options', array());
			$this->wcfmmp_store_endpoints       = wcfm_get_option('wcfm_store_endpoints', array());
		} else {
			$this->wcfm_store_url               = get_option('wcfm_store_url', 'store');
			$this->wcfmmp_marketplace_options   = get_option('wcfm_marketplace_options', array());
			$this->wcfmmp_store_endpoints       = get_option('wcfm_store_endpoints', array());
		}
		$this->wcfmmp_commission_options    = get_option('wcfm_commission_options', array());
		$this->wcfmmp_withdrawal_options    = get_option('wcfm_withdrawal_options', array());
		$this->wcfmmp_review_options        = get_option('wcfm_review_options', array());
		$this->wcfmmp_refund_options        = get_option('wcfm_refund_options', array());
		$this->wcfmmp_notification_options  = get_option('wcfmmp_notification_options', array());
	}

	


	function init() {
		global $WCFM, $WCFMmp;

		$this->setup_properties();

		 
		 
		if (is_admin()) {
			$current_page = filter_input(INPUT_GET, 'page');
			if ($current_page && $current_page == 'wcfmmp-setup') {
				require_once $this->plugin_path . 'helpers/class-wcfmmp-setup.php';
			}
		}

		if (function_exists('wcfm_is_vendor') && wcfm_is_vendor()) {
			$current_page = filter_input(INPUT_GET, 'store-setup');
			if ($current_page) {
				require_once $this->plugin_path . 'helpers/class-wcfmmp-store-setup.php';
			}
		}

		 
		if (is_admin()) {
			$this->load_class('admin');
			$this->admin = new WCFMmp_Admin();
		}

		 
		if (!class_exists('WCFMmp_Rewrites')) {
			$this->load_class('rewrite');
			$this->wcfmmp_rewrite = new WCFMmp_Rewrites();
		}

		 
		$this->load_class('abstract-gateway');
	}

	


	function load_wcfmmp() {

		if (WCFMmp_Dependencies::woocommerce_plugin_active_check() && WCFMmp_Dependencies::wcfm_plugin_active_check()) {
			 
			$this->load_class('sidebar-widgets');
			$this->wcfmmp_sidebar_widgets = new WCFMmp_Sidebar_Widgets();

			 
			$this->load_class('shipping');
			$this->wcfmmp_shipping = new WCFMmp_Shipping();

			 
			$this->load_class('shipping-gateway');
			$this->wcfmmp_shipping_gateways = new WCFMmp_Shipping_Gateway();

			 
			$this->load_class('shipping-zone');
			$this->wcfmmp_shipping_zone = new WCFMmp_Shipping_Zone();

			 
			$this->load_class('emails');
			$this->wcfmmp_emails = new WCFMmp_Emails();

			 
			$this->load_class('store-seo');
			$this->wcfmmp_store_seo = new WCFMmp_Store_SEO();

			


			add_filter( 'woocommerce_payment_gateways', array( $this, 'add_stripe_split_pay_gateway' ) );
		}

		do_action('wcfmmp_loaded');
	}

	





	public function add_stripe_split_pay_gateway($gateways) {
		$gateways[] = 'WCFMmp_Gateway_Stripe_Split';
		return $gateways;
	}

	


	function init_wcfmmp() {
		global $WCFM, $WCFMmp;

		if (!WCFMmp_Dependencies::woocommerce_plugin_active_check()) {
			add_action('admin_notices', 'wcfmmp_woocommerce_inactive_notice');
			return;
		}

		if (!WCFMmp_Dependencies::wcfm_plugin_active_check()) {
			add_action('admin_notices', 'wcfmmp_wcfm_inactive_notice');
			return;
		}

		 
		$this->load_class('library');
		$this->library = new WCFMmp_Library();

		 
		if (defined('DOING_AJAX') || defined('WCFM_REST_API_CALL')) {
			$this->load_class('ajax');
			$this->ajax = new WCFMmp_Ajax();
		}

		 
		if (!is_admin() || defined('DOING_AJAX')) {
			$this->load_class('settings');
			$this->wcfmmp_settings = new WCFMmp_Settings();
		}

		if (apply_filters('wcfm_is_pref_notification', true)) {
			$this->load_class('notification-manager');
			$this->wcfmmp_notification_manager = new WCFMmp_Notification_Manager();
		}

		 
		$this->load_class('commission');
		$this->wcfmmp_commission = new WCFMmp_Commission();


		 
		$this->load_class('withdraw');
		$this->wcfmmp_withdraw = new WCFMmp_Withdraw();

		 
		if (apply_filters('wcfm_is_pref_refund', true)) {
			$this->load_class('refund');
			$this->wcfmmp_refund = new WCFMmp_Refund();
		}

		 
		if (apply_filters('wcfm_is_pref_vendor_reviews', true)) {
			$this->load_class('reviews');
			$this->wcfmmp_reviews = new WCFMmp_Reviews();
		}

		 
		$this->load_class('vendor');
		$this->wcfmmp_vendor = new WCFMmp_Vendor();

		 
		$this->load_class('store');
		$this->wcfmmp_store = new WCFMmp_Store();

		 
		 
		 

		 
		$this->load_class('product');
		$this->wcfmmp_product = new WCFMmp_Product();

		 
		if (apply_filters('wcfm_is_pref_product_multivendor', true)) {
			$this->load_class('product-multivendor');
			$this->wcfmmp_product_multivendor = new WCFMmp_Product_Multivendor();
		}

		 
		$this->load_class('ledger');
		$this->wcfmmp_ledger = new WCFMmp_Ledger();

		 
		if (apply_filters('wcfm_is_pref_store_hours', true)) {
			if (!is_admin() || defined('DOING_AJAX')) {
				$this->load_class('store-hours');
				$this->wcfmmp_store_hours = new WCFMmp_Store_Hours();
			}
		}

		 
		if (!is_admin() || defined('DOING_AJAX')) {
			$this->load_class('frontend');
			$this->frontend = new WCFMmp_Frontend();
		}

		 
		if (!defined('DOING_AJAX')) {
			$this->load_class('non-ajax');
			$this->wcfmmp_non_ajax = new WCFMmp_Non_Ajax();
		}


		 
		if (apply_filters('wcfm_is_pref_media_manager', true)) {
			if (!is_admin() || defined('DOING_AJAX')) {
				$this->load_class('media');
				$this->wcfmmp_media = new WCFMmp_Media();
			}
		}

		 
		$this->load_class('template');
		$this->template = new WCFMmp_Template();

		 
		 
		$this->load_class('shortcode');
		$this->wcfmmp_shortcodes = new WCFMmp_Shortcode();
		 

		 
		$this->load_class('gateways');
		$this->wcfmmp_gateways = new WCFMmp_Gateways();

		 
	}

	







	public function load_plugin_textdomain() {
		$locale = function_exists('get_user_locale') ? get_user_locale() : get_locale();
		$locale = apply_filters('plugin_locale', $locale, 'wc-multivendor-marketplace');

		 
		load_textdomain('wc-multivendor-marketplace', $this->plugin_path . "lang/wc-multivendor-marketplace-$locale.mo");
		load_textdomain('wc-multivendor-marketplace', WP_LANG_DIR . "/plugins/wc-multivendor-marketplace-$locale.mo");
	}

	


	function get_wcfmmp_modules($wcfm_modules) {

		$wcfmmp_module_index = array_search('refund', array_keys($wcfm_modules));
		if (!$wcfmmp_module_index) {
			$wcfmmp_module_index = 4;
		} else {
			$wcfmmp_module_index += 1;
		}

		$wcfmmp_modules = array(
			'reviews'             	=> array('label' => __('Reviews', 'wc-multivendor-marketplace')),
			'store_hours'           => array('label' => __('Store Hours', 'wc-multivendor-marketplace')),
			'media'             	  => array('label' => __('Media', 'wc-multivendor-marketplace')),
			'ledger_book'           => array('label' => __('Vendor Ledger', 'wc-multivendor-marketplace')),
			'product_mulivendor'    => array('label' => __('Product Multivendor', 'wc-multivendor-marketplace'), 'hints' => __("Keep this enable to allow vendors to sell other vendors' products, single product multiple seller.", 'wc-multivendor-marketplace')),
			'sell_items_catalog'    => array('label' => __('Add to My Store Catalog', 'wc-multivendor-marketplace'), 'hints' => __("Other vendors' products catalog, vendors will able to add those directly to their store.", 'wc-multivendor-marketplace')),
		);

		$wcfm_modules = array_slice($wcfm_modules, 0, $wcfmmp_module_index, true) +
			$wcfmmp_modules +
			array_slice($wcfm_modules, $wcfmmp_module_index, count($wcfm_modules) - 1, true);

		return $wcfm_modules;
	}

	


	function wcfmmp_renewal_order_processed($renewal_order, $subscription) {
		global $WCFM, $WCFMmp, $wpdb;
		wcfm_log("RENEWAL ORDER CORE ::" . $renewal_order->get_id());
		if ($renewal_order) {
			$order_id = $renewal_order->get_id();
			$order = wc_get_order($order_id);
			$order_posted = (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && OrderUtil::custom_orders_table_usage_is_enabled()) ? $order : get_post($order_id);
			$order->delete_meta_data('_wcfmmp_order_processed');
			$order->save();
			$WCFMmp->wcfmmp_commission->wcfmmp_checkout_order_processed($order_id, $order_posted, $renewal_order);
			$WCFM->wcfm_notification->wcfm_message_on_new_order($order_id, true);
		}

		return $renewal_order;
	}

	


	function wcfmmp_withdrawal_periodic_scheduler_check() {
		global $WCFM, $WCFMmp, $wpdb;

		$wcfm_withdrawal_options = get_option('wcfm_withdrawal_options', array());

		$withdrawal_mode         = isset($wcfm_withdrawal_options['withdrawal_mode']) ? $wcfm_withdrawal_options['withdrawal_mode'] : '';
		$withdrawal_schedule     = isset($wcfm_withdrawal_options['withdrawal_schedule']) ? $wcfm_withdrawal_options['withdrawal_schedule'] : 'week';

		$withdrawal_limit        = isset($wcfm_withdrawal_options['withdrawal_limit']) ? $wcfm_withdrawal_options['withdrawal_limit'] : '';
		$withdrawal_thresold     = isset($wcfm_withdrawal_options['withdrawal_thresold']) ? $wcfm_withdrawal_options['withdrawal_thresold'] : '';


		if ($withdrawal_mode && ($withdrawal_mode == 'by_schedule')) {
			wcfm_withdrawal_log("PERIODIC WITHDRAWAL SCHEDULER START :: " . date_i18n(wc_date_format() . ' ' . wc_time_format(), current_time('timestamp', 0)));  

			$args = array(
				'role__in'     => array('wcfm_vendor'),
				'fields'       => array('ID', 'display_name')

			);
			$vendors = get_users($args);
			if (!empty($vendors)) {
				foreach ($vendors as $vendor) {
					$disable_vendor = get_user_meta($vendor->ID, '_disable_vendor', true);
					if (!$disable_vendor) {
						$shop_name = $WCFM->wcfm_vendor_support->wcfm_get_vendor_store_name_by_vendor(absint($vendor->ID));

						wcfm_withdrawal_log("Periodic withdrawal start. Vendor :: " . $vendor->ID . ' Store :: ' . $shop_name);

						$payment_method = $WCFMmp->wcfmmp_vendor->get_vendor_payment_method($vendor->ID);
						if ($payment_method) {
							if (array_key_exists($payment_method, $WCFMmp->wcfmmp_gateways->payment_gateways)) {
								$withdrawal_thresold = $WCFMmp->wcfmmp_withdraw->get_withdrawal_thresold($vendor->ID);
								$withdrawal_limit    = $WCFMmp->wcfmmp_withdraw->get_withdrawal_limit($vendor->ID);

								$sql  = "SELECT * FROM {$wpdb->prefix}wcfm_marketplace_orders AS commission";
								$sql .= " WHERE 1=1";
								$sql .= " AND `vendor_id` = %d";
								$sql .= apply_filters('wcfm_order_status_condition', '', 'commission');
								$sql .= " AND commission.withdraw_status IN ('pending', 'cancelled')";
								$sql .= " AND commission.refund_status != 'requested'";
								$sql .= ' AND `is_withdrawable` = 1 AND `is_auto_withdrawal` = 0 AND `is_refunded` = 0 AND `is_trashed` = 0';
								if ($withdrawal_thresold) $sql .= $wpdb->prepare(" AND commission.created <= NOW() - INTERVAL %s DAY", $withdrawal_thresold);

								$wcfm_commissions = $wpdb->get_results($wpdb->prepare($sql, $vendor->ID));

								if (!empty($wcfm_commissions)) {
									$order_ids = '';
									$commission_ids = '';
									$total_commission = 0;
									$no_of_commission = count($wcfm_commissions);

									foreach ($wcfm_commissions as $wcfm_commission) {
										$order = wc_get_order($wcfm_commission->order_id);
										if (!is_a($order, 'WC_Order')) continue;

										try {
											$line_item = new WC_Order_Item_Product(absint($wcfm_commission->item_id));

											 
											if ($refunded_qty = $order->get_qty_refunded_for_item(absint($wcfm_commission->item_id))) {
												$refunded_qty = $refunded_qty * -1;
												if ($line_item->get_quantity() == $refunded_qty) {
													continue;
												}
											}
										} catch (Exception $e) {
											continue;
										}

										if ($order_ids) $order_ids .= ',';
										$order_ids .= $wcfm_commission->order_id;

										if ($commission_ids) $commission_ids .= ',';
										$commission_ids .= $wcfm_commission->ID;

										$total_commission += wc_format_decimal($wcfm_commission->total_commission);
									}

									if ($total_commission && ((float) $total_commission >= (float) $withdrawal_limit)) {

										 
										$withdraw_charges = $WCFMmp->wcfmmp_withdraw->calculate_withdrawal_charges($total_commission, $vendor->ID);
										if ($withdraw_charges) {
											$withdraw_charge_per_commission = (float)$withdraw_charges / $no_of_commission;
											foreach ($wcfm_commissions as $commission_info) {
												$wpdb->update("{$wpdb->prefix}wcfm_marketplace_orders", array('withdraw_charges' => wc_format_decimal($withdraw_charge_per_commission)), array('ID' => $commission_info->ID), array('%s'), array('%d'));
											}
										}

										 
										$withdraw_request_id = $WCFMmp->wcfmmp_withdraw->wcfmmp_withdrawal_processed($vendor->ID, $order_ids, $commission_ids, $payment_method, 0, $total_commission, $withdraw_charges, 'requested', 'by_schedule');

										if ($withdraw_request_id && !is_wp_error($withdraw_request_id)) {

											 
											foreach ($wcfm_commissions as $commission_info) {
												$wpdb->update("{$wpdb->prefix}wcfm_marketplace_orders", array('withdraw_status' => 'requested'), array('ID' => $commission_info->ID), array('%s'), array('%d'));
											}

											 
											$is_auto_approve = $WCFMmp->wcfmmp_withdraw->is_withdrawal_auto_approve($vendor->ID);
											if ($is_auto_approve) {
												$payment_processesing_status = $WCFMmp->wcfmmp_withdraw->wcfmmp_withdrawal_payment_processesing($withdraw_request_id, $vendor->ID, $payment_method, $total_commission, $withdraw_charges);
												if ($payment_processesing_status) {
													wcfm_withdrawal_log('Periodic withdrawal request successfully processed. Withdrawal ID :: ' . sprintf('%06u', $withdraw_request_id) . ' Vendor :: ' . $vendor->ID . ' Store :: ' . $shop_name);
												} else {
													wcfm_withdrawal_log('Periodic withdrawal request processing failed. Withdrawal ID :: ' . sprintf('%06u', $withdraw_request_id) . ' Vendor :: ' . $vendor->ID . ' Store :: ' . $shop_name);
												}
											} else {
												 
												$store_name = $WCFM->wcfm_vendor_support->wcfm_get_vendor_store_by_vendor(absint($vendor->ID));
												$wcfm_messages = sprintf(__('Vendor <b>%s</b> has placed a Withdrawal Request #%s.', 'wc-frontend-manager'), $store_name, '<a target="_blank" class="wcfm_dashboard_item_title" href="' . add_query_arg('transaction_id', $withdraw_request_id, wcfm_withdrawal_requests_url()) . '">' . sprintf('%06u', $withdraw_request_id) . '</a>');

												$raw_message = [
													'l10n'	=> [
														'text' 		=> 'Vendor <b>%s</b> has placed a Withdrawal Request #%s.',
														'domain'    => 'wc-frontend-manager',
														'wrapper'	=> [
															'function' 	=> 'sprintf',
															'args' 		=> [
																$store_name,
																'<a target="_blank" class="wcfm_dashboard_item_title" href="' . add_query_arg('transaction_id', $withdraw_request_id, wcfm_withdrawal_requests_url()) . '">' . sprintf('%06u', $withdraw_request_id) . '</a>'
															]
														]
													]
												];

												$WCFM->wcfm_notification->wcfm_send_direct_message($vendor->ID, 0, 0, 1, $wcfm_messages, 'withdraw-request', true, $raw_message);
												wcfm_withdrawal_log('Periodic withdrawal request successfully sent. Withdrawal ID :: ' . sprintf('%06u', $withdraw_request_id) . ' Vendor :: ' . $vendor->ID . ' Store :: ' . $shop_name);
											}

											do_action('wcfmmp_withdrawal_request_submited', $withdraw_request_id, $vendor->ID);
										} else {
											wcfm_withdrawal_log('Periodic withdrawal request failed. Vendor :: ' . $vendor->ID . ' Store :: ' . $shop_name);
										}
									} else {
										wcfm_withdrawal_log("Periodic withdrawal commission less than withdrawal limit. Vendor :: " . $vendor->ID . ' Store :: ' . $shop_name);
									}
								} else {
									wcfm_withdrawal_log("Periodic withdrawal no pending commission. Vendor :: " . $vendor->ID . ' Store :: ' . $shop_name);
								}
							} else {
								wcfm_withdrawal_log("Periodic withdrawal payment method missing. Vendor :: " . $vendor->ID . ' Store :: ' . $shop_name);
							}
						} else {
							wcfm_withdrawal_log("Periodic withdrawal payment method missing. Vendor :: " . $vendor->ID . ' Store :: ' . $shop_name);
						}
						wcfm_withdrawal_log("Periodic withdrawal end. Vendor :: " . $vendor->ID . ' Store :: ' . $shop_name);
					}
				}
			}


			wcfm_withdrawal_log("PERIODIC WITHDRAWAL SCHEDULER END :: " . date_i18n(wc_date_format() . ' ' . wc_time_format(), current_time('timestamp', 0)));  
		}
	}

	


	function wcfmmp_data_cleanup_periodic_scheduler_check() {
		global $WCFM, $WCFMmp, $wpdb;

		$wcfm_data_cleanup_options        = get_option('wcfm_data_cleanup_options', array());

		$enable_data_cleanup              = isset($wcfm_data_cleanup_options['enable_data_cleanup']) ? $wcfm_data_cleanup_options['enable_data_cleanup'] : 'no';

		$enable_data_cleanup_messages     = isset($wcfm_data_cleanup_options['enable_data_cleanup_messages']) ? $wcfm_data_cleanup_options['enable_data_cleanup_messages'] : 'no';
		$messages_data_cleanup_more_than  = isset($wcfm_data_cleanup_options['messages_data_cleanup_more_than']) ? $wcfm_data_cleanup_options['messages_data_cleanup_more_than'] : '90';

		$enable_data_cleanup_inquiry      = isset($wcfm_data_cleanup_options['enable_data_cleanup_inquiry']) ? $wcfm_data_cleanup_options['enable_data_cleanup_inquiry'] : 'no';
		$inquiry_data_cleanup_more_than   = isset($wcfm_data_cleanup_options['inquiry_data_cleanup_more_than']) ? $wcfm_data_cleanup_options['inquiry_data_cleanup_more_than'] : '90';

		$enable_data_cleanup_analytics    = isset($wcfm_data_cleanup_options['enable_data_cleanup_analytics']) ? $wcfm_data_cleanup_options['enable_data_cleanup_analytics'] : 'no';
		$analytics_data_cleanup_more_than = isset($wcfm_data_cleanup_options['analytics_data_cleanup_more_than']) ? $wcfm_data_cleanup_options['analytics_data_cleanup_more_than'] : '90';

		if ($enable_data_cleanup == 'yes') {
			wcfm_cleanup_log("PERIODIC DATA CLEANUP SCHEDULER START :: " . date_i18n(wc_date_format() . ' ' . wc_time_format(), current_time('timestamp', 0)));  

			 
			if ($enable_data_cleanup_messages == 'yes') {
				wcfm_cleanup_log("PERIODIC NOTIFICATION DATA CLEANUP SCHEDULER START. Older than :: " . $messages_data_cleanup_more_than);

				 
				$messages = $wpdb->get_results($wpdb->prepare("SELECT ID, created FROM {$wpdb->prefix}wcfm_messages WHERE `created` <= DATE_SUB(SYSDATE(), INTERVAL %s DAY)", $messages_data_cleanup_more_than));
				if (!empty($messages)) {
					foreach ($messages as $message) {
						$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wcfm_messages WHERE ID = %d", $message->ID));

						 
						$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wcfm_messages_modifier WHERE `message` = %d", $message->ID));

						wcfm_cleanup_log("Notification data cleanup processed. ID :: " . $message->ID . " Created :: " . date_i18n(wc_date_format() . ' ' . wc_time_format(), strtotime($message->created)));
					}
				}

				wcfm_cleanup_log("PERIODIC NOTIFICATION DATA CLEANUP SCHEDULER END. Older than :: " . $messages_data_cleanup_more_than);
			} else {
				wcfm_cleanup_log("Notification data cleanup disabled.");
			}

			 
			if ($enable_data_cleanup_inquiry == 'yes') {
				wcfm_cleanup_log("PERIODIC INQUIRY DATA CLEANUP SCHEDULER START. Older than :: " . $inquiry_data_cleanup_more_than);

				 
				$inquiries = $wpdb->get_results($wpdb->prepare("SELECT ID, posted FROM {$wpdb->prefix}wcfm_enquiries WHERE `posted` <= DATE_SUB(SYSDATE(), INTERVAL %s DAY)", $inquiry_data_cleanup_more_than));
				if (!empty($inquiries)) {
					foreach ($inquiries as $inquiry) {
						$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wcfm_enquiries WHERE ID = %d", $inquiry->ID));

						 
						$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wcfm_enquiries_meta WHERE `enquiry_id` = %d", $inquiry->ID));

						 
						$inquiry_replies = $wpdb->get_results($wpdb->prepare("SELECT ID FROM {$wpdb->prefix}wcfm_enquiries_response WHERE `enquiry_id` = %d", $inquiry->ID));
						if (!empty($inquiry_replies)) {
							foreach ($inquiry_replies as $inquiry_reply) {
								$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wcfm_enquiries_response WHERE `ID` = %d", $inquiry_reply->ID));

								 
								$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wcfm_enquiries_response_meta WHERE `enquiry_response_id` = %d", $inquiry_reply->ID));
							}
						}

						wcfm_cleanup_log("Inquiry data cleanup processed. ID :: " . $inquiry->ID . " Created :: " . date_i18n(wc_date_format() . ' ' . wc_time_format(), strtotime($inquiry->posted)));
					}
				}

				wcfm_cleanup_log("PERIODIC INQUIRY DATA CLEANUP SCHEDULER END. Older than :: " . $inquiry_data_cleanup_more_than);
			} else {
				wcfm_cleanup_log("Inquiry data cleanup disabled.");
			}

			 
			 
			if ($enable_data_cleanup_analytics == 'yes') {
				wcfm_cleanup_log("PERIODIC ANALYTICS DATA CLEANUP SCHEDULER START. Older than :: " . $analytics_data_cleanup_more_than);

				 
				$analytics = $wpdb->get_results($wpdb->prepare("SELECT ID, visited FROM {$wpdb->prefix}wcfm_daily_analysis WHERE `visited` <= DATE_SUB(SYSDATE(), INTERVAL %s DAY)",$analytics_data_cleanup_more_than));
				if (!empty($analytics)) {
					foreach ($analytics as $analytic) {
						$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wcfm_daily_analysis WHERE ID = %d", $analytic->ID));
						wcfm_cleanup_log("Daily Analytics data cleanup processed. ID :: " . $analytic->ID . " Created :: " . date_i18n(wc_date_format() . ' ' . wc_time_format(), strtotime($analytic->visited)));
					}
				}

				 
				$analytics = $wpdb->get_results($wpdb->prepare("SELECT ID, visited FROM {$wpdb->prefix}wcfm_detailed_analysis WHERE `visited` <= DATE_SUB(SYSDATE(), INTERVAL %s DAY)",$analytics_data_cleanup_more_than));
				if (!empty($analytics)) {
					foreach ($analytics as $analytic) {
						$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wcfm_detailed_analysis WHERE ID = %d", $analytic->ID));
						wcfm_cleanup_log("Detailed Analytics data cleanup processed. ID :: " . $analytic->ID . " Created :: " . date_i18n(wc_date_format() . ' ' . wc_time_format(), strtotime($analytic->visited)));
					}
				}

				wcfm_cleanup_log("PERIODIC ANALYTICS DATA CLEANUP SCHEDULER END. Older than :: " . $analytics_data_cleanup_more_than);
			} else {
				wcfm_cleanup_log("Analytics data cleanup disabled.");
			}
			 

			wcfm_cleanup_log("PERIODIC DATA CLEANUP SCHEDULER END :: " . date_i18n(wc_date_format() . ' ' . wc_time_format(), current_time('timestamp', 0)));  
		}
	}

	public function load_class($class_name = '') {
		if ('' != $class_name && '' != $this->token) {
			require_once('class-' . esc_attr($this->token) . '-' . esc_attr($class_name) . '.php');
		}  
	}

	 

	





	static function activate_wcfmmp() {
		global $WCFM, $WCFMmp, $wp_roles;

		 
		$WCFMmp->load_class('rewrite');
		$WCFMmp->wcfmmp_rewrite = new WCFMmp_Rewrites();

		require_once($WCFMmp->plugin_path . 'helpers/class-wcfmmp-install.php');
		$WCFMmp_Install = new WCFMmp_Install();

		update_option('wcfmmp_updated_3_3_10', 1);
		update_option('wcfmmp_installed', 1);
	}

	





	function run_wcfmmp_installer() {
		global $WCFM, $WCFMmp, $wpdb;

		$wcfm_marketplace_tables = $wpdb->query("SHOW tables like '{$wpdb->prefix}wcfm_marketplace_reverse_withdrawal_meta'");
		if (!$wcfm_marketplace_tables) {
			delete_option('wcfmmp_updated_3_3_10');
			delete_option('wcfmmp_table_install');
		}

		if (!get_option('wcfmmp_updated_3_3_10')) {
			delete_option('wcfmmp_table_install');
			require_once($WCFMmp->plugin_path . 'helpers/class-wcfmmp-install.php');
			$WCFMmp_Install = new WCFMmp_Install();
			update_option('wcfmmp_updated_3_3_10', 1);
		}

		if (!get_option("wcfmmp_page_install") || !get_option("wcfmmp_table_install")) {
			require_once($WCFMmp->plugin_path . 'helpers/class-wcfmmp-install.php');
			$WCFMmp_Install = new WCFMmp_Install();

			update_option('wcfmmp_installed', 1);
		}

		 
		if (class_exists('WooCommerce')) {
			$next = WC()->queue()->get_next('wcfmmp_periodic_withdrawal_scheduler');
			if ($next) {
				WC()->queue()->cancel_all('wcfmmp_periodic_withdrawal_scheduler');
			}

			 
			$wcfm_withdrawal_options = get_option('wcfm_withdrawal_options', array());

			$withdrawal_mode         = isset($wcfm_withdrawal_options['withdrawal_mode']) ? $wcfm_withdrawal_options['withdrawal_mode'] : '';
			$withdrawal_schedule     = isset($wcfm_withdrawal_options['withdrawal_schedule']) ? $wcfm_withdrawal_options['withdrawal_schedule'] : 'week';
			if ($withdrawal_mode && ($withdrawal_mode == 'by_schedule')) {
				$withdrawal_schedule_timestamps = wcfmmp_generate_timestamp_for_period($withdrawal_schedule);
				$period_interval    = apply_filters('wcfm_schedule_period_interval', $withdrawal_schedule_timestamps['days_in_period'], $withdrawal_schedule);
				$period_starts_from = apply_filters('wcfm_schedule_period_starts_from', $withdrawal_schedule_timestamps['starting_timestamp'], $withdrawal_schedule);

				$next = WC()->queue()->get_next('wcfmmp_withdrawal_periodic_scheduler');
				if (!$next) {
					WC()->queue()->schedule_recurring($period_starts_from, ($period_interval * DAY_IN_SECONDS), 'wcfmmp_withdrawal_periodic_scheduler', array(), 'WCFM');
				}
			} else {
				$next = WC()->queue()->get_next('wcfmmp_withdrawal_periodic_scheduler');
				if ($next) {
					WC()->queue()->cancel_all('wcfmmp_withdrawal_periodic_scheduler');
				}
			}

			 
			$wcfm_data_cleanup_options = get_option('wcfm_data_cleanup_options', array());

			$enable_data_cleanup = isset($wcfm_data_cleanup_options['enable_data_cleanup']) ? $wcfm_data_cleanup_options['enable_data_cleanup'] : 'no';
			if ($enable_data_cleanup == 'yes') {
				$next = WC()->queue()->get_next('wcfmmp_data_cleanup_periodic_scheduler');
				if (!$next) {
					WC()->queue()->cancel_all('wcfmmp_data_cleanup_periodic_scheduler');
					WC()->queue()->schedule_recurring(time(), DAY_IN_SECONDS, 'wcfmmp_data_cleanup_periodic_scheduler', array(), 'WCFM');
				}
			} else {
				$next = WC()->queue()->get_next('wcfmmp_data_cleanup_periodic_scheduler');
				if ($next) {
					WC()->queue()->cancel_all('wcfmmp_data_cleanup_periodic_scheduler');
				}
			}
		}
	}

	





	static function deactivate_wcfmmp() {
		global $WCFM, $WCFMmp;

		 
		if (class_exists('WooCommerce')) {
			$next = WC()->queue()->get_next('wcfmmp_withdrawal_periodic_scheduler');
			if ($next) {
				WC()->queue()->cancel_all('wcfmmp_withdrawal_periodic_scheduler');
			}

			$next = WC()->queue()->get_next('wcfmmp_data_cleanup_periodic_scheduler');
			if ($next) {
				WC()->queue()->cancel_all('wcfmmp_data_cleanup_periodic_scheduler');
			}
		}

		$wcfm_marketplace_options = get_option('wcfm_marketplace_options', array());
		$delete_data_on_uninstall = isset($wcfm_marketplace_options['delete_data_on_uninstall']) ? $wcfm_marketplace_options['delete_data_on_uninstall'] : 'no';

		if ($delete_data_on_uninstall == 'yes') {
			require_once($WCFMmp->plugin_path . 'helpers/class-wcfmmp-uninstall.php');
			$WCFMmp_Uninstall = new WCFMmp_Uninstall();
		}

		delete_option('wcfmmp_installed');
	}
}
