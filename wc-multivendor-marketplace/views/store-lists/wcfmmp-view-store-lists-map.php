<?php
/**
 * The Template for displaying store list.
 *
 * @package WCfM Markeplace Views Store Lists
 *
 * For edit coping this to yourtheme/wcfm/store/store-lists
 *
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

global $WCFM, $WCFMmp, $post;

$api_key = isset( $WCFMmp->wcfmmp_marketplace_options['wcfm_google_map_api'] ) ? $WCFMmp->wcfmmp_marketplace_options['wcfm_google_map_api'] : '';
$wcfm_map_lib = isset( $WCFMmp->wcfmmp_marketplace_options['wcfm_map_lib'] ) ? $WCFMmp->wcfmmp_marketplace_options['wcfm_map_lib'] : '';
if( !$wcfm_map_lib && $api_key ) { $wcfm_map_lib = 'google'; } elseif( !$wcfm_map_lib && !$api_key ) { $wcfm_map_lib = 'leaftlet'; }
if ( ($wcfm_map_lib == 'google') && !$api_key ) return;
if( !apply_filters( 'wcfmmp_is_allow_store_list_map', true ) ) return;

?>

<div id="wcfmmp-store-list-map" class="wcfmmp-store-list-map"></div>

<script>
	$map_zoom    = <?php echo absint($map_zoom); ?>;
	$auto_zoom   = '<?php echo esc_attr($auto_zoom); ?>';
	$per_row     = '<?php echo esc_attr($per_row); ?>';
	$per_page    = '<?php echo esc_attr($limit); ?>';
	$includes    = '<?php echo esc_attr(implode(",", $includes )); ?>';
	$excludes    = '<?php echo esc_attr($excludes); ?>';
	$has_product = '<?php echo esc_attr($has_product); ?>';
	$sidebar     = '<?php echo esc_attr($sidebar); ?>';
	$has_orderby = '<?php echo esc_attr($has_orderby); ?>';
	$theme       = '<?php echo esc_attr($theme); ?>';
</script>