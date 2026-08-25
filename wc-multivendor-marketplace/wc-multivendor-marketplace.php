<?php
/**
 * Plugin Name: WCFM - WooCommerce Multivendor Marketplace
 * Plugin URI: https://wclovers.com/knowledgebase_category/wcfm-marketplace/
 * Description: Most featured and flexible marketplace solution for your e-commerce store. Simply and Smoothly.
 * Author: WC Lovers
 * Version: 3.8.2
 * Author URI: https://wclovers.com
 *
 * Text Domain: wc-multivendor-marketplace
 * Domain Path: /lang/
 *
 * WC requires at least: 7.0.0
 * WC tested up to: 11.0
 *
 * Requires Plugins: woocommerce, wc-frontend-manager
 */

if(!defined('ABSPATH')) exit;  

if ( ! class_exists( 'WCFMmp_Dependencies' ) )
	require_once 'helpers/class-wcfmmp-dependencies.php';

require_once 'helpers/wcfmmp-core-functions.php';
require_once 'wc-multivendor-marketplace-config.php';

if(!defined('WCFMmp_TOKEN')) exit;
if(!defined('WCFMmp_TEXT_DOMAIN')) exit;


if(!class_exists('WCFMmp')) {
	include_once( 'core/class-wcfmmp.php' );
	global $WCFMmp;
	$WCFMmp = new WCFMmp( __FILE__ );
	$GLOBALS['WCFMmp'] = $WCFMmp;
	
	 
	register_activation_hook( __FILE__, array('wcfmmp', 'activate_wcfmmp') );
	register_activation_hook( __FILE__, 'flush_rewrite_rules' );
	
	 
	register_deactivation_hook( __FILE__, array('wcfmmp', 'deactivate_wcfmmp') );
	
	


	add_action( 'before_woocommerce_init', function() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	} );
}
?>