<?php









if ( ! defined( 'ABSPATH' ) ) exit;  

global $WCFM, $WCFMmp;

$wcfm_shop_description = apply_filters( 'wcfmmp_store_about', apply_filters( 'woocommerce_short_description', $store_user->get_shop_description() ), $store_user->get_shop_description() );

?>

<div class="_area" id="wcfmmp_store_about">
	<div class="wcfmmp-store-description">
	 
	  <?php do_action( 'wcfmmp_store_before_about', $store_user->get_id() ); ?>
	
		<?php if( $wcfm_shop_description ) { ?>
			<div class="wcfm-store-about">
				<div class="wcfm_store_description" ><?php echo wp_kses_post($wcfm_shop_description); ?></div>
			</div>
		<?php } ?>
		
		<?php do_action( 'wcfmmp_store_after_about', $store_user->get_id() ); ?>
		
	</div>
</div>