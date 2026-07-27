<?php







class WCFMmp_Store_Shipping_Rules extends WP_Widget {

	




	public function __construct() {
		$widget_ops = array( 'classname' => 'wcfmmp-store-hours-widget', 'description' => __( 'Store Shipping Rules', 'wc-multivendor-marketplace' ) );
		parent::__construct( 'wcfmmp-store-shipping-rules-widget', __( 'Vendor Store: Shipping Rules', 'wc-multivendor-marketplace' ), $widget_ops );
	}

	







	function widget( $args, $instance ) {
		global $WCFM, $WCFMmp, $post;
		
		if( !apply_filters( 'wcfm_is_allow_product_shipping_info', true ) ) return;

		if ( ! wcfmmp_is_store_page() && !is_product() && !$post ) {
			return;
		}

		extract( $args, EXTR_SKIP );

		$title        = '';
		if( isset( $instance['title'] ) && !empty( $instance['title'] ) ) {
			$title        = apply_filters( 'widget_title', $instance['title'] );
		}
		
		if (  wcfm_is_store_page() ) {
			$wcfm_store_url = wcfm_get_option( 'wcfm_store_url', 'store' );
			$store_name = apply_filters( 'wcfmmp_store_query_var', get_query_var( $wcfm_store_url ) );
			$store_id  = 0;
			if ( !empty( $store_name ) ) {
				$store_user = get_user_by( 'slug', $store_name );
			}
			$store_id   		= $store_user->ID;
		}
		
		if( is_product() ) {
			$store_id = $post->post_author;
		} elseif( $post ) {
			$store_id = $post->post_author;
			if( !wcfm_is_vendor( $store_id ) ) return;
		}
		
		if( !$store_id ) return;
		
		$is_store_offline = get_user_meta( $store_id, '_wcfm_store_offline', true );
		if ( $is_store_offline ) {
			return;
		}
		
		$is_disable_vendor = get_user_meta( $store_id, '_disable_vendor', true );
		if ( $is_disable_vendor ) return;
		
		if( !$WCFM->wcfm_vendor_support->wcfm_vendor_has_capability( $store_id, 'vshipping' ) ) return;
		
		if( !wcfmmp_is_shipping_enabled($store_id) ) return;
		
		$wcfm_vendor_shipping = get_user_meta( $store_id, '_wcfmmp_shipping', true );
		
		echo $before_widget;

		if ( ! empty( $title ) ) {
			echo $args['before_title'] . '<span class="wcfmfa fa-truck"></span>&nbsp;' . wp_kses_post($title) . $args['after_title'];
		}
		
		do_action( 'wcfmmp_store_before_sidebar_store_shipping_rules', $store_id );
		
		echo '<div class="wcfmmp_store_shipping_rules">';
		
		$WCFMmp->template->get_template( 'store/widgets/wcfmmp-view-store-shipping-rules.php', array( 
			                                             'wcfmmp_shipping' => $wcfm_vendor_shipping, 
			                                             'store_id' => $store_id,
			                                             ) );
		
		echo '</div>';

		do_action( 'wcfmmp_store_after_sidebar_store_shipping_rules', $store_id );

		echo $after_widget;
	}

	








	function update( $new_instance, $old_instance ) {

			 
			$updated_instance = $new_instance;
			return $updated_instance;
	}

	






	function form( $instance ) {
			$instance = wp_parse_args( (array) $instance, array(
					'title' => __( 'Shipping Rules', 'wc-multivendor-marketplace' ),
			) );

			$title = $instance['title'];
			?>
			<p>
				<label for="<?php echo esc_attr($this->get_field_id( 'title' )); ?>"><?php _e( 'Title:', 'wc-multivendor-marketplace' ); ?></label>
				<input class="widefat" id="<?php echo esc_attr($this->get_field_id( 'title' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'title' )); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
			</p>
			<?php
	}
}
