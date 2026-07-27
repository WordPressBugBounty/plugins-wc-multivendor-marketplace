<?php







class WCFMmp_Products_Search_by_Vendors extends WP_Widget {

	




	public function __construct() {
		$widget_ops = array( 'classname' => 'wcfmmp-products-search-by-vendors', 'description' => __( 'Filter Products by Store', 'wc-multivendor-marketplace' ) );
		parent::__construct( 'wcfmmp-products-search-by-vendors', __( 'Filter Products by Store', 'wc-multivendor-marketplace' ), $widget_ops );
	}

	







	function widget( $args, $instance ) {
		global $WCFM, $WCFMmp, $wp;

		if ( ! is_shop() && ! is_product_taxonomy() ) {
				return;
		}

		extract( $args, EXTR_SKIP );

		$title        = '';
		if( isset( $instance['title'] ) && !empty( $instance['title'] ) ) {
			$title        = apply_filters( 'widget_title', $instance['title'] );
		}
		
		echo $before_widget;

		if ( ! empty( $title ) ) {
			echo $args['before_title'] . wp_kses_post($title) . $args['after_title'];
		}
		
		$WCFMmp->template->get_template( 'product-geolocate/wcfmmp-view-product-lists-vendor-filter.php', $args );
		
		echo $after_widget;
	}

	








	function update( $new_instance, $old_instance ) {

			 
			$updated_instance = $new_instance;
			return $updated_instance;
	}

	






	function form( $instance ) {
			$instance = wp_parse_args( (array) $instance, array(
					'title' => __( 'Search by Vendors', 'wc-multivendor-marketplace' ),
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
