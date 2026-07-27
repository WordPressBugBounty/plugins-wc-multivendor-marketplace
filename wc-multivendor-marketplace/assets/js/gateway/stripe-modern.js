/* global wcfmmp_stripe_split_pay_params, Stripe */
/**
 * Stripe Split Pay — modern checkout (PaymentIntents + Payment Element).
 *
 * Enqueued only when wcfmmp_stripe_split_engine() === 'modern'. Deferred-intent
 * flow: the Payment Element is mounted up front; the server creates the intent
 * during the checkout POST (WCFMmp_Stripe_Payment_Engine::process_payment) and
 * returns its client secret, which the gateway hands back as a hash redirect
 * (mirroring the legacy 3DS convention so WooCommerce does not navigate away).
 * This script then confirms the intent client-side and pings the verify
 * endpoint, which completes the order server-side.
 *
 * Mirrors assets/js/gateway/stripe.js for its WooCommerce integration points
 * (form id, checkout events, notice container, button handling); the Stripe
 * calls are the modern equivalents. ES5, no build step.
 */
jQuery( function( $ ) {

	var params = window.wcfmmp_stripe_split_pay_params || {};

	var wcfmmp_stripe_modern = {

		stripe: null,
		stripeAccounts: {},
		elements: null,
		paymentElement: null,
		submitting: false,

		form: function() {
			return $( 'form.checkout, form#order_review, form#order_review_payment' );
		},

		/**
		 * Stripe instance scoped to a connected account, for authenticating a
		 * direct-charge PaymentIntent that lives on that account. Cached per
		 * account across a multi-vendor sequential challenge.
		 */
		stripeFor: function( account ) {
			if ( ! account ) {
				return this.stripe;
			}
			if ( ! this.stripeAccounts[ account ] ) {
				this.stripeAccounts[ account ] = Stripe( params.key, { stripeAccount: account } );
			}
			return this.stripeAccounts[ account ];
		},

		errorContainer: function() {
			return $( '.wcfmmp-stripe-split-pay-source-errors' );
		},

		init: function() {
			if ( ! params.key || 'undefined' === typeof Stripe ) {
				return;
			}
			this.stripe = Stripe( params.key );

			$( document.body ).on( 'updated_checkout', this.onUpdatedCheckout.bind( this ) );
			$( document.body ).on( 'payment_method_selected', this.mount.bind( this ) );

			// Gate the checkout submit: validate the Payment Element first.
			this.form().on( 'checkout_place_order_stripe_split', this.onPlaceOrder.bind( this ) );

			// The gateway returns the intent as a hash; confirm on hashchange.
			window.addEventListener( 'hashchange', this.onHashChange.bind( this ) );

			// A reload can land with a stale intent hash still in the URL; clear
			// it so a retried checkout always fires a fresh hashchange.
			if ( /^#?wcfmmp-stripe-modern:/.test( window.location.hash ) ) {
				window.location.hash = '';
			}

			// Order-pay: the pay page's full POST cannot carry Payment Element
			// data, so the server prepares the intent at render and we confirm
			// it in place when the pay form submits.
			if ( 'yes' === params.is_pay_for_order_page && params.order_pay_payload ) {
				$( 'form#order_review' ).on( 'submit', this.onPayForOrder.bind( this ) );
			}

			this.mount();
		},

		isSelected: function() {
			var chosen = $( '#payment_method_stripe_split' );
			return chosen.length ? chosen.is( ':checked' ) : true;
		},

		mount: function() {
			if ( ! this.isSelected() ) {
				return;
			}
			var target = document.getElementById( 'wcfmmp-stripe-split-pay-card-element' );
			if ( ! target ) {
				return;
			}

			// updated_checkout re-renders the payment fragment, destroying
			// whatever was mounted inside it; (re)mount whenever the
			// placeholder is empty again.
			if ( target.childElementCount > 0 ) {
				return;
			}

			if ( ! this.elements ) {
				// Flow A (SCT) collects a payment; Flow B (direct/destination)
				// collects a SetupIntent (card saved once, reused/cloned per vendor
				// server-side), so the Element is mounted in 'setup' mode with no
				// amount. params.flow is fixed by the configured mode at enqueue.
				var options = {
					currency: ( params.currency || 'usd' ),
					paymentMethodTypes: [ 'card' ]
				};
				if ( 'setup' === params.flow ) {
					options.mode = 'setup';
				} else {
					options.mode = 'payment';
					var amount = parseInt( params.amount, 10 );
					options.amount = ( isNaN( amount ) || amount <= 0 ) ? 100 : amount;
				}

				this.elements = this.stripe.elements( options );

				this.paymentElement = this.elements.create( 'payment', {
					fields: { billingDetails: 'auto' }
				} );
				this.paymentElement.on( 'change', this.clearError.bind( this ) );
			} else {
				// The previous container is gone; detach before re-mounting.
				this.paymentElement.unmount();
			}

			this.paymentElement.mount( target );

			// The Payment Element is unified; hide the legacy split expiry/CVC rows.
			$( '#wcfmmp-stripe-split-pay-exp-element' ).closest( '.form-row' ).hide();
			$( '#wcfmmp-stripe-split-pay-cvc-element' ).closest( '.form-row' ).hide();
		},

		/**
		 * Fragment refresh: re-mount if the payment box was re-rendered and
		 * keep the deferred elements amount in sync with the refreshed order
		 * total (confirmPayment rejects if it drifts from the intent amount).
		 */
		onUpdatedCheckout: function( event, data ) {
			this.mount();

			// Only the payment-mode Element (Flow A) carries an amount to keep in sync.
			if ( 'setup' !== params.flow && this.elements && data && data.fragments && 'undefined' !== typeof data.fragments.wcfmmp_stripe_amount ) {
				var amount = parseInt( data.fragments.wcfmmp_stripe_amount, 10 );
				if ( ! isNaN( amount ) && amount > 0 ) {
					this.elements.update( { amount: amount } );
				}
			}
		},

		/**
		 * checkout_place_order handler — validate the element, then allow the
		 * normal checkout POST to proceed (the server creates the intent).
		 */
		onPlaceOrder: function() {
			if ( ! this.isSelected() || ! this.elements ) {
				return true;
			}

			// Second pass: the element was just validated below — consume the
			// flag and let the WooCommerce checkout POST proceed.
			if ( this.submitting ) {
				this.submitting = false;
				return true;
			}

			var self = this;
			this.submitting = true;
			this.block();

			this.elements.submit().then( function( result ) {
				if ( result.error ) {
					self.submitting = false;
					self.unblock();
					self.showError( result.error.message );
					return;
				}
				// Valid: re-trigger the submit; the gate above passes it through
				// while this.submitting is still set. The intent is created
				// server-side and returned as a hash (onHashChange).
				self.form().trigger( 'submit' );
			} );

			// Block the immediate submit; we re-trigger it after validation.
			return false;
		},

		/**
		 * Pay-for-order submit — validate the element, then confirm the
		 * server-prepared intent in place; confirm() drives the redirect
		 * (thank-you on success, back to the pay page on failure). Other
		 * gateways fall through to the normal POST.
		 */
		onPayForOrder: function() {
			if ( ! this.isSelected() || ! this.elements ) {
				return true;
			}

			var self = this;
			this.block();

			this.elements.submit().then( function( result ) {
				if ( result.error ) {
					self.unblock();
					self.showError( result.error.message );
					return;
				}
				self.confirm( params.order_pay_payload );
			} );

			return false;
		},

		/**
		 * The gateway encodes the modern intent as
		 * #wcfmmp-stripe-modern:<rawurlencoded-json>. Parse, confirm, verify.
		 */
		onHashChange: function() {
			var match = window.location.hash.match( /^#?wcfmmp-stripe-modern:(.+)$/ );
			if ( ! match ) {
				return;
			}

			var payload;
			try {
				payload = JSON.parse( decodeURIComponent( match[1] ) );
			} catch ( e ) {
				return;
			}

			window.location.hash = '';
			this.confirm( payload );
		},

		confirm: function( payload ) {
			var self = this;
			if ( ! payload.secret ) {
				return;
			}

			this.block();

			// Resume the server-side completion. For Flow B (setup) the server
			// creates the per-vendor charges; if a vendor's card needs SCA it pauses
			// and returns { requires_action, client_secret, account }, which we
			// authenticate (on the connected account for direct charges) before
			// calling verify again. A cart with several 3DS vendors resolves each in
			// turn, so this recurses until verify returns a terminal redirect.
			var resume = function() {
				$.get( payload.verify + '&is_ajax=1' ).done( handle ).fail( function() {
					window.location = payload.redirect;
				} );
			};

			var handle = function( resp ) {
				if ( resp && resp.requires_action && resp.client_secret ) {
					self.stripeFor( resp.account ).handleNextAction( { clientSecret: resp.client_secret } ).then( function( result ) {
						if ( result.error ) {
							self.unblock();
							self.showError( result.error.message );
							return;
						}
						resume();
					} );
					return;
				}
				window.location = ( resp && resp.redirect ) ? resp.redirect : payload.redirect;
			};

			var finish = function( result ) {
				if ( result.error ) {
					self.unblock();
					self.showError( result.error.message );
					return;
				}
				resume();
			};

			if ( 'setup' === payload.flow ) {
				// Direct/destination: confirm the SetupIntent once (initial card SCA
				// happens here); it always lives on the platform, no account switch.
				this.stripe.confirmSetup( {
					elements: self.elements,
					clientSecret: payload.secret,
					confirmParams: { return_url: payload.redirect },
					redirect: 'if_required'
				} ).then( finish );
				return;
			}

			// Flow A (SCT): confirm the platform PaymentIntent.
			this.stripe.confirmPayment( {
				elements: self.elements,
				clientSecret: payload.secret,
				confirmParams: { return_url: payload.redirect },
				redirect: 'if_required'
			} ).then( finish );
		},

		block: function() {
			var form = this.form();
			if ( form.length && $.blockUI ) {
				form.block( { message: null, overlayCSS: { background: '#fff', opacity: 0.6 } } );
			}
		},

		unblock: function() {
			var form = this.form();
			if ( form.length && form.unblock ) {
				form.unblock();
			}
			// Re-enable the place-order button on failure.
			$( '#place_order' ).prop( 'disabled', false ).removeClass( 'disabled' );
		},

		clearError: function( event ) {
			if ( event && event.complete ) {
				this.errorContainer().empty();
			}
		},

		showError: function( message ) {
			$( '.woocommerce-NoticeGroup-checkout' ).remove();
			this.errorContainer().html( '<ul class="woocommerce_error woocommerce-error wc-stripe-error"><li>' + ( message || params.invalid_request_error || '' ) + '</li></ul>' );

			if ( $( '.wc-stripe-error' ).length ) {
				$( 'html, body' ).animate( { scrollTop: ( $( '.wc-stripe-error' ).offset().top - 200 ) }, 200 );
			}
			this.unblock();
		}
	};

	wcfmmp_stripe_modern.init();
} );
