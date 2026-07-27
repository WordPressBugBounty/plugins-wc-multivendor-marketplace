<?php







class WCFMmp_Store_Lists_Search extends WP_Widget {

	




	public function __construct() {
		$widget_ops = array( 'classname' => 'wcfmmp-store-lists-search', 'description' => __( 'Store Lists Search', 'wc-multivendor-marketplace' ) );
		parent::__construct( 'wcfmmp-store-lists-search', __( 'Store List: Search', 'wc-multivendor-marketplace' ), $widget_ops );
	}

	







	function widget( $args, $instance ) {
		global $WCFM, $WCFMmp;

		if ( ! wcfmmp_is_stores_list_page() ) {
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
		
		do_action( 'wcfmmp_store_lists_before_sidebar_search' );
		
		$wcfmmp_store_search     = isset( $_REQUEST['wcfmmp_store_search'] ) ? sanitize_text_field( $_REQUEST['wcfmmp_store_search'] ) : '';
		
		?>
		<input type="search" id="search" class="search-field wcfmmp-store-search" placeholder="<?php esc_attr_e( 'Search &hellip;', 'wc-multivendor-marketplace' ); ?>" value="<?php echo esc_html($wcfmmp_store_search); ?>" name="wcfmmp_store_search" title="<?php esc_attr_e( 'Search store &hellip;', 'wc-multivendor-marketplace' ); ?>" />
		<?php
		
		do_action( 'wcfmmp_store_lists_after_sidebar_search' );

		echo $after_widget;
	}

	








	function update( $new_instance, $old_instance ) {

			 
			$updated_instance = $new_instance;
			return $updated_instance;
	}

	






	function form( $instance ) {
			$instance = wp_parse_args( (array) $instance, array(
					'title' => __( 'Search', 'wc-multivendor-marketplace' ),
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
