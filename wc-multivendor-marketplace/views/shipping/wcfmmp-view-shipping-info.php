<?php

global $WCFM, $WCFMmp, $wpdb;

$wcfmmp_shipping          = get_user_meta( $vendor_id, '_wcfmmp_shipping', true );
$processing_times         = wcfmmp_get_shipping_processing_times();
$processing_time          = isset($wcfmmp_shipping['_wcfmmp_pt']) ? $wcfmmp_shipping['_wcfmmp_pt'] : '';
$processing_time          = get_post_meta( $product_id, '_wcfmmp_processing_time', true ) ? get_post_meta( $product_id, '_wcfmmp_processing_time', true ) : $processing_time;

if( isset( $wcfmmp_shipping['_wcfmmp_user_shipping_enable'] ) && $processing_time && isset( $processing_times[$processing_time] ) ) {
	echo '<div class="wcfm_clearfix"></div><div class="wcfmmp_shipment_processing_display">'. __( 'Item will be shipped in', 'wc-multivendor-marketplace' ) . ' ' . $processing_times[$processing_time] .'</div><div class="wcfm_clearfix"></div>';
}

if( !apply_filters( 'wcfm_is_allow_product_free_shipping_info', true ) ) return;














?>