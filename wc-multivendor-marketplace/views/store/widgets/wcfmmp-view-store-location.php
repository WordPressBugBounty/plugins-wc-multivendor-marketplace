<?php









if ( ! defined( 'ABSPATH' ) ) exit;  

global $WCFM, $WCFMmp;

?>

<div id="<?php echo esc_attr($map_id); ?>" class="wcfmmp-store-map"></div>
<?php
	$WCFM->wcfm_fields->wcfm_generate_form_field( array(
		                                                  "store_address" => array( 'type' => 'hidden', 'class' => 'wcfm_store_address', 'value' => rawurlencode( $address ) ),
																											"store_lat"     => array( 'type' => 'hidden', 'class' => 'wcfm_store_lat', 'value' => $store_lat ),
																											"store_lng"     => array( 'type' => 'hidden', 'class' => 'wcfm_store_lng', 'value' => $store_lng ),
																											) );
?>