<?php









 
class WCFMmp_Non_Ajax {

	public function __construct() {
		global $WCFM, $WCFMmp;
		
		 
		add_filter( 'plugin_action_links_' . $WCFMmp->plugin_base_name, array( &$this, 'wcfmmp_plugin_action_links' ) );
		add_filter( 'plugin_row_meta', array( &$this, 'wcfmmp_plugin_row_meta' ), 10, 2 );
	}
	
	





	public function wcfmmp_plugin_action_links( $links ) {
		global $WCFMmp;
		$action_links = array(
			'settings' => '<a target="_blank" href="' . get_wcfm_settings_url() . '#wcfm_settings_form_marketplace_head' . '" aria-label="' . esc_attr__( 'View WCFM Marketplace settings', 'wc-frontend-manager' ) . '">' . esc_html__( 'Settings', 'wc-frontend-manager' ) . '</a>',
		);

		return array_merge( $action_links, $links );
	}
	
	






	public function wcfmmp_plugin_row_meta( $links, $file ) {
		global $WCFMmp;
		if ( $WCFMmp->plugin_base_name == $file ) {
			$row_meta = array(
				'docs'				=> '<a target="_blank" href="' . esc_url( apply_filters( 'wcfm_docs_url', 'https://wclovers.com/knowledgebase_category/wcfm-marketplace/' ) ) . '" aria-label="' . esc_attr__( 'View WCFM documentation', 'wc-frontend-manager' ) . '">' . esc_html__( 'Documentation', 'wc-frontend-manager' ) . '</a>',
				'go-premium'   		=> '<a target="_blank" href="' . esc_url( apply_filters( 'wcfm_go_premium_url', 'https://wclovers.com/addons/' ) ) . '" aria-label="' . esc_attr__( 'Go Premium', WCFMmp_TEXT_DOMAIN ) . '">' . esc_html__( 'Go Premium', WCFMmp_TEXT_DOMAIN ) . '</a>',
				'customizationa' 	=> '<a target="_blank" href="' . esc_url( apply_filters( 'wcfm_customization_url', 'https://wclovers.com/woocommerce-multivendor-customization/' ) ) . '" aria-label="' . esc_attr__( 'Any WC help feel free to contact us', 'wc-frontend-manager' ) . '">' . esc_html__( 'Customization Help', 'wc-frontend-manager' ) . '</a>'
			);
			return array_merge( $links, $row_meta );
		}

		return (array) $links;
	}
}