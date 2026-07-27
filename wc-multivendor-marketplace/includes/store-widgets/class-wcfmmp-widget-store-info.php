<?php







class WCFMmp_Store_Info extends WP_Widget {

	




	public function __construct() {
		$widget_ops = array( 'classname' => 'wcfmmp-store-category', 'description' => __( 'Store Info', 'wc-multivendor-marketplace' ) );
		parent::__construct( 'wcfmmp-store-info', __( 'Vendor Store: Info', 'wc-multivendor-marketplace' ), $widget_ops );
	}

	







	function widget( $args, $instance ) {
		global $WCFM, $WCFMmp, $post;

		extract( $args, EXTR_SKIP );
		
		if( !$post ) return;
		if( !wcfm_is_vendor( $post->post_author ) ) return;
		
		$store_user   = wcfmmp_get_store( $post->post_author );
		$store_info   = $store_user->get_shop_info();
		
		$is_store_offline = get_user_meta( $store_user->get_id(), '_wcfm_store_offline', true );
		if ( $is_store_offline ) {
			return;
		}
		
		echo $before_widget;

		do_action( 'wcfmmp_store_before_sidebar_info', $store_user->get_id() );
		
		$vendor_sold_by_template = $WCFMmp->wcfmmp_vendor->get_vendor_sold_by_template();
		
		if( $vendor_sold_by_template == 'advanced' ) {
			$WCFMmp->template->get_template( 'sold-by/wcfmmp-view-sold-by-advanced.php', array( 'vendor_id' => $store_user->get_id() ) );
		} else {
			$WCFMmp->template->get_template( 'sold-by/wcfmmp-view-sold-by-simple.php', array( 'vendor_id' => $store_user->get_id() ) );
		}
		wp_enqueue_style( 'wcfmmp_product_css',  $WCFMmp->library->css_lib_url_min . 'store/wcfmmp-style-product.css', array(), $WCFMmp->version );

		do_action( 'wcfmmp_store_after_sidebar_info', $store_user->get_id() );

		echo $after_widget;
	}

	








	function update( $new_instance, $old_instance ) {

			 
			$updated_instance = $new_instance;
			return $updated_instance;
	}

	






	function form( $instance ) {
		$instance = wp_parse_args( (array) $instance, array(
				'title' => __( 'Store Categories', 'wc-multivendor-marketplace' ),
		) );

		$title = $instance['title'];
	}
}
