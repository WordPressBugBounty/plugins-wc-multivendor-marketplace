<?php
/**
 * WCFM plugin controllers
 *
 * Plugin WCfM Marketplace Refund Requests Dashboard Controller
 *
 * @author 		WC Lovers
 * @package 	wcfmmp/controllers/refund
 * @version   1.0.0
 */

class WCFMmp_Refund_Requests_Controller {
	
	public function __construct() {
		global $WCFM;
		
		$this->processing();
	}
	
	public function processing() {
		global $WCFM, $wpdb, $_POST, $WCFMmp;
		
		$vendor_id = $WCFMmp->vendor_id;
		
		$length = absint( $_POST['length'] );
		$offset = absint( $_POST['start'] );
		
		$the_orderby = ! empty( $_POST['orderby'] ) ? sanitize_sql_orderby( $_POST['orderby'] ) : 'ID';
		$the_order   = ( ! empty( $_POST['order'] ) && 'asc' === $_POST['order'] ) ? 'ASC' : 'DESC';
		
		$transaction_id = ! empty( $_POST['transaction_id'] ) ? absint( $_POST['transaction_id'] ) : '';
		
		$refund_vendor_filter = '';
		if( wcfm_is_vendor() && $vendor_id ) {
			$refund_vendor = $vendor_id;
			$refund_vendor_filter = " AND commission.`vendor_id` = %d";
		} elseif ( ! empty( $_POST['refund_vendor'] ) ) {
			$refund_vendor = absint( $_POST['refund_vendor'] );
			$refund_vendor_filter = " AND commission.`vendor_id` = %d";
		}
		
		$status_filter = '';
		$status_type = 'requested';
		if( isset($_POST['status_type']) ) {
			$status_type = sanitize_text_field( $_POST['status_type'] );
			if( $status_type != 'requested' ) $the_order = 'DESC';
		}
		if( $status_type ) {
			$status_filter = " AND commission.refund_status = %s";
		}

		$sql = 'SELECT COUNT(commission.ID) FROM ' . $wpdb->prefix . 'wcfm_marketplace_refund_request AS commission';
		$sql .= ' WHERE 1=1';
		if( $status_type && $status_filter ) {
			$sql .= $status_filter;
			$sql = $wpdb->prepare( $sql, $status_type );
		}
		if( $transaction_id ) {
			$sql .= " AND commission.ID = %d";
			$sql = $wpdb->prepare( $sql, $transaction_id );
		}
		if( $refund_vendor_filter ) {
			$sql .= $refund_vendor_filter;
			$sql = $wpdb->prepare( $sql, $refund_vendor );
		}
		
		$filtered_refund_requests_count = $wpdb->get_var( $sql );
		if( !$filtered_refund_requests_count ) $filtered_refund_requests_count = 0;

		$sql = 'SELECT * FROM ' . $wpdb->prefix . 'wcfm_marketplace_refund_request AS commission';
		$sql .= ' WHERE 1=1';
		if( $status_type && $status_filter ) {
			$sql .= $status_filter;
			$sql = $wpdb->prepare( $sql, $status_type );
		}
		if( $transaction_id ) {
			$sql .= " AND commission.ID = %d";
			$sql = $wpdb->prepare( $sql, $transaction_id );
		}
		if( $refund_vendor_filter ) {
			$sql .= $refund_vendor_filter;
			$sql = $wpdb->prepare( $sql, $refund_vendor );
		}

		$sql .= " ORDER BY {$the_orderby} {$the_order} LIMIT %d OFFSET %d";
		$sql = $wpdb->prepare($sql, [
			$length,
			$offset
		]);
		
		$wcfm_refund_requests_array = $wpdb->get_results( $sql );
		
		if ( WC()->payment_gateways() ) {
			$payment_gateways = WC()->payment_gateways->payment_gateways();
		} else {
			$payment_gateways = array();
		}
		
		// Generate Redund Request JSON
        $wcfm_refund_requests_json = [
            'draw'              => absint( $_POST['draw'] ),
            'recordsTotal'      => absint( $filtered_refund_requests_count ),
            'recordsFiltered'   => absint( $filtered_refund_requests_count )
        ];
		
		if(!empty($wcfm_refund_requests_array)) {
			$index = 0;
			$wcfm_refund_requests_json_arr = array();
			foreach( $wcfm_refund_requests_array as $wcfm_refund_request_single ) {
				
				// Status
				if( $wcfm_refund_request_single->refund_status == 'completed' ) {
					$wcfm_refund_requests_json_arr[$index][] =  '<span class="payment-status tips wcicon-status-completed text_tip" data-tip="' . __( 'Refund Completed', 'wc-multivendor-marketplace') . '"></span>';
				} elseif( $wcfm_refund_request_single->refund_status == 'cancelled' ) {
					$wcfm_refund_requests_json_arr[$index][] =  '<span class="payment-status tips wcicon-status-cancelled text_tip" data-tip="' . __( 'Refund Cancelled', 'wc-multivendor-marketplace') . '"></span>';
				} else {
					if( !wcfm_is_vendor() ) {
						$wcfm_refund_requests_json_arr[$index][] =  '<input name="refunds[]" value="' . $wcfm_refund_request_single->ID . '" class="wcfm-checkbox select_refund_requests" type="checkbox" >';
					} else {
						$wcfm_refund_requests_json_arr[$index][] =  '&ndash;';
					}
				}
				
				// Request ID
				$wcfm_refund_requests_json_arr[$index][] = '<span class="wcfm_dashboard_item_title"># ' . $wcfm_refund_request_single->ID . '</span>';
				
				// Order ID
				$wcfm_refund_requests_json_arr[$index][] =  '<a target="_blank" href="' . get_wcfm_view_order_url( $wcfm_refund_request_single->order_id ) . '" class="wcfm_dashboard_item_title transaction_order_id">#'.  $wcfm_refund_request_single->order_id . '</a>';
				
				// Store
				if( $wcfm_refund_request_single->vendor_id ) {
					$wcfm_refund_requests_json_arr[$index][] = $WCFM->wcfm_vendor_support->wcfm_get_vendor_store_by_vendor( absint($wcfm_refund_request_single->vendor_id) );
				} else {
					$wcfm_refund_requests_json_arr[$index][] = '&ndash;';
				}
				
				// Amount
				$refunded_amount    = $wcfm_refund_request_single->refunded_amount;
				if( ( $wcfm_refund_request_single->refund_status != 'completed' ) ) {
					if( !$wcfm_refund_request_single->is_partially_refunded ) {
						// Item Shipping Refund Amount
						$line_item       = new WC_Order_Item_Product( $wcfm_refund_request_single->item_id );
						$product         = $line_item->get_product();
						$vendor_id = absint($wcfm_refund_request_single->vendor_id);
						$vendor_shipping = $WCFMmp->wcfmmp_shipping->get_order_vendor_shipping( $wcfm_refund_request_single->order_id );
							
						$shipping_cost = $shipping_tax = 0;
						if ( !empty($vendor_shipping) && isset($vendor_shipping[$vendor_id]) && $vendor_shipping[$vendor_id]['shipping_item_id'] && ( $product && $product->needs_shipping() ) ) {
							$shipping_item_id = $vendor_shipping[$vendor_id]['shipping_item_id'];
							$package_qty      = absint( $vendor_shipping[$vendor_id]['package_qty'] );
							if( !$package_qty ) $package_qty = $line_item->get_quantity();
							$shipping_item    = new WC_Order_Item_Shipping( $shipping_item_id );
							$refund_shipping_tax = $shipping_item->get_taxes();
							$shipping_tax_refund = array();
							if( !empty( $refund_shipping_tax ) && is_array( $refund_shipping_tax ) ) {
								if( isset( $refund_shipping_tax['total'] ) ) {
									$refund_shipping_tax = $refund_shipping_tax['total'];
								}
								if( !empty( $refund_shipping_tax ) && is_array( $refund_shipping_tax ) ) {
									foreach( $refund_shipping_tax as $refund_shipping_tax_id => $refund_shipping_tax_price ) {
										$shipping_tax_refund = round( ((float) $refund_shipping_tax_price/$package_qty) * $line_item->get_quantity(), 2);
										$refunded_amount += $shipping_tax_refund;
									}
								}
							}
							
							$shipping_cost = (float) apply_filters( 'wcfmmmp_gross_sales_shipping_cost', $WCFMmp->wcfmmp_commission->wcfmmp_get_commission_meta( $wcfm_refund_request_single->commission_id, 'gross_shipping_cost' ), $wcfm_refund_request_single->vendor_id );

							$refunded_amount += $shipping_cost;
						}
					}
					
					$refunded_tax       = $WCFMmp->wcfmmp_refund->wcfmmp_get_refund_meta( $wcfm_refund_request_single->ID, 'refunded_tax' );
					if( !$refunded_tax ) $refund_tax = array();
					else $refunded_tax = unserialize( $refunded_tax );
					if( $refunded_tax && is_array( $refunded_tax ) && !empty( $refunded_tax ) ) {
						foreach( $refunded_tax as $tax_item_id => $tax_item_cost ) {
							$refunded_amount += (float)$tax_item_cost;
						}
					}
				}
				$wcfm_refund_requests_json_arr[$index][] = wc_price( $refunded_amount );
				
				// Mode
				if( $wcfm_refund_request_single->is_partially_refunded ) {
					$wcfm_refund_requests_json_arr[$index][] = __( 'Partial Refund', 'wc-multivendor-marketplace' );
				} else {
					$wcfm_refund_requests_json_arr[$index][] = __( 'Full Refund', 'wc-multivendor-marketplace' );
				}
				
				// Reason
				$wcfm_refund_requests_json_arr[$index][] = $wcfm_refund_request_single->refund_reason;
				
				// Date
				$wcfm_refund_requests_json_arr[$index][] = date_i18n( wc_date_format() . ' ' . wc_time_format(), strtotime( $wcfm_refund_request_single->created ) );
				
				// Additional Info
				$wcfm_refund_requests_json_arr[$index][] = apply_filters( 'wcfm_refund_request_additonal_data', '&ndash;', $wcfm_refund_request_single->ID );
				
				$index++;
			}												
		}

		if (! empty($wcfm_refund_requests_json_arr)) {
            $wcfm_refund_requests_json['data'] = $wcfm_refund_requests_json_arr;
        } else {
            $wcfm_refund_requests_json['data'] = [];
        }

        wp_send_json( $wcfm_refund_requests_json );
	}
}