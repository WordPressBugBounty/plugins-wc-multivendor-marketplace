<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}








class WCFM_Stripe_Exception extends Exception {

	




	protected $localized_message;

	





	public function __construct( $error_message = '', $localized_message = '' ) {
		$this->localized_message = $localized_message;
		parent::__construct( $error_message );
	}

	




	public function getLocalizedMessage() {
		return $this->localized_message;
	}
}
