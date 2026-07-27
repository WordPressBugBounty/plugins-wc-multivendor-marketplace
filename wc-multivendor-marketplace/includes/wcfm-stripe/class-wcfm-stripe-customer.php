<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}






class WC_Stripe_Customer {

	



	private $id = '';

	



	private $user_id = 0;

	



	private $customer_data = array();

	



	public function __construct( $user_id = 0 ) {
		if ( $user_id ) {
			$this->set_user_id( $user_id );
			$this->set_id( get_user_meta( $user_id, '_stripe_customer_id', true ) );
		}
	}

	



	public function get_id() {
		return $this->id;
	}

	



	public function set_id( $id ) {
		 
		if ( is_array( $id ) && isset( $id['customer_id'] ) ) {
			$id = $id['customer_id'];

			update_user_meta( $this->get_user_id(), '_stripe_customer_id', $id );
		}

		$this->id = wc_clean( $id );
	}

	



	public function get_user_id() {
		return absint( $this->user_id );
	}

	



	public function set_user_id( $user_id ) {
		$this->user_id = absint( $user_id );
	}

	



	protected function get_user() {
		return $this->get_user_id() ? get_user_by( 'id', $this->get_user_id() ) : false;
	}

	


	public function set_customer_data( $data ) {
		$this->customer_data = $data;
	}

	




	public function create_customer( $args = array() ) {
		$billing_email = isset( $_POST['billing_email'] ) ? filter_var( $_POST['billing_email'], FILTER_SANITIZE_EMAIL ) : '';
		$user          = $this->get_user();

		if ( $user ) {
			$billing_first_name = get_user_meta( $user->ID, 'billing_first_name', true );
			$billing_last_name  = get_user_meta( $user->ID, 'billing_last_name', true );

			 
			if ( empty( $billing_first_name ) ) {
				$billing_first_name = get_user_meta( $user->ID, 'first_name', true );
			}

			 
			if ( empty( $billing_last_name ) ) {
				$billing_last_name = get_user_meta( $user->ID, 'last_name', true );
			}

			$description = __( 'Name', 'wc-multivendor-marketplace' ) . ': ' . $billing_first_name . ' ' . $billing_last_name . ' ' . __( 'Username', 'wc-multivendor-marketplace' ) . ': ' . $user->user_login;

			$defaults = array(
				'email'       => $user->user_email,
				'description' => $description,
			);
		} else {
			$defaults = array(
				'email'       => $billing_email,
				'description' => '',
			);
		}

		$metadata = array();

		$defaults['metadata'] = apply_filters( 'wc_stripe_customer_metadata', $metadata, $user );

		$args     = wp_parse_args( $args, $defaults );
		$response = WC_Stripe_API::request( apply_filters( 'wc_stripe_create_customer_args', $args ), 'customers' );

		if ( ! empty( $response->error ) ) {
			throw new WCFM_Stripe_Exception( print_r( $response, true ), $response->error->message );
		}

		$this->set_id( $response->id );
		$this->clear_cache();
		$this->set_customer_data( $response );

		if ( $this->get_user_id() ) {
			update_user_meta( $this->get_user_id(), '_stripe_customer_id', $response->id );
		}

		do_action( 'woocommerce_stripe_add_customer', $args, $response );

		return $response->id;
	}

	






	public function is_no_such_customer_error( $error ) {
		return (
			$error &&
			'invalid_request_error' === $error->type &&
			preg_match( '/No such customer/i', $error->message )
		);
	}

	





	public function add_source( $source_id, $retry = true ) {
		if ( ! $this->get_id() ) {
			$this->set_id( $this->create_customer() );
		}

		$response = WC_Stripe_API::request(
			array(
				'source' => $source_id,
			),
			'customers/' . $this->get_id() . '/sources'
		);

		$wc_token = false;

		if ( ! empty( $response->error ) ) {
			 
			 
			 
			if ( $this->is_no_such_customer_error( $response->error ) ) {
				delete_user_meta( $this->get_user_id(), '_stripe_customer_id' );
				$this->create_customer();
				return $this->add_source( $source_id, false );
			} else {
				return $response;
			}
		} elseif ( empty( $response->id ) ) {
			return new WP_Error( 'error', __( 'Unable to add payment source.', 'wc-multivendor-marketplace' ) );
		}

		 
		if ( $this->get_user_id() && class_exists( 'WC_Payment_Token_CC' ) ) {
			if ( ! empty( $response->type ) ) {
				switch ( $response->type ) {
					case 'alipay':
						break;
					case 'sepa_debit':
						$wc_token = new WC_Payment_Token_SEPA();
						$wc_token->set_token( $response->id );
						$wc_token->set_gateway_id( 'stripe_sepa' );
						$wc_token->set_last4( $response->sepa_debit->last4 );
						break;
					default:
						if ( 'source' === $response->object && 'card' === $response->type ) {
							$wc_token = new WC_Payment_Token_CC();
							$wc_token->set_token( $response->id );
							$wc_token->set_gateway_id( 'stripe' );
							$wc_token->set_card_type( strtolower( $response->card->brand ) );
							$wc_token->set_last4( $response->card->last4 );
							$wc_token->set_expiry_month( $response->card->exp_month );
							$wc_token->set_expiry_year( $response->card->exp_year );
						}
						break;
				}
			} else {
				 
				$wc_token = new WC_Payment_Token_CC();
				$wc_token->set_token( $response->id );
				$wc_token->set_gateway_id( 'stripe' );
				$wc_token->set_card_type( strtolower( $response->brand ) );
				$wc_token->set_last4( $response->last4 );
				$wc_token->set_expiry_month( $response->exp_month );
				$wc_token->set_expiry_year( $response->exp_year );
			}

			$wc_token->set_user_id( $this->get_user_id() );
			$wc_token->save();
		}

		$this->clear_cache();

		do_action( 'woocommerce_stripe_add_source', $this->get_id(), $wc_token, $response, $source_id );

		return $response->id;
	}

	





	public function get_sources() {
		if ( ! $this->get_id() ) {
			return array();
		}

		$sources = get_transient( 'stripe_sources_' . $this->get_id() );

		$response = WC_Stripe_API::request(
			array(
				'limit' => 100,
			),
			'customers/' . $this->get_id() . '/sources',
			'GET'
		);

		if ( ! empty( $response->error ) ) {
			return array();
		}

		if ( is_array( $response->data ) ) {
			$sources = $response->data;
		}

		return empty( $sources ) ? array() : $sources;
	}

	



	public function delete_source( $source_id ) {
		if ( ! $this->get_id() ) {
			return false;
		}

		$response = WC_Stripe_API::request( array(), 'customers/' . $this->get_id() . '/sources/' . sanitize_text_field( $source_id ), 'DELETE' );

		$this->clear_cache();

		if ( empty( $response->error ) ) {
			do_action( 'wc_stripe_delete_source', $this->get_id(), $response );

			return true;
		}

		return false;
	}

	



	public function set_default_source( $source_id ) {
		$response = WC_Stripe_API::request(
			array(
				'default_source' => sanitize_text_field( $source_id ),
			),
			'customers/' . $this->get_id(),
			'POST'
		);

		$this->clear_cache();

		if ( empty( $response->error ) ) {
			do_action( 'wc_stripe_set_default_source', $this->get_id(), $response );

			return true;
		}

		return false;
	}

	


	public function clear_cache() {
		delete_transient( 'stripe_sources_' . $this->get_id() );
		delete_transient( 'stripe_customer_' . $this->get_id() );
		$this->customer_data = array();
	}
}
