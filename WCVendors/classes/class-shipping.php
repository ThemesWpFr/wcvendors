<?php

/**
 * Shipping functions
 *
 * @author  Matt Gates <http://mgates.me>, WC Vendors <http://wcvendors.com>
 * @package ProductVendor
 */


class WCV_Shipping
{
	public static $trs2_shipping_rates;
	public static $trs2_shipping_calc_type;
	public static $pps_shipping_costs = array();


	/**
	 * Constructor
	 */
	function __construct()
	{
		// Table Rate Shipping 2 by WooThemes
		if ( function_exists( 'woocommerce_get_shipping_method_table_rate' ) ) {
			add_action( 'wp', array( $this, 'trs2_clear_transients' ) );
			add_action( 'woocommerce_checkout_update_order_meta', array( 'WCV_Shipping', 'trs2_add_shipping_data' ), 1, 2 );
			add_action( 'wc_trs2_matched_rates', array( 'WCV_Shipping', 'trs2_store_shipping_data' ), 10, 3 );
		}
	}


	/**
	 *
	 *
	 * @param unknown $order_id
	 * @param unknown $product
	 * @param unknown $author
	 *
	 * @return unknown
	 */
	public static function get_shipping_due( $order_id, $product, $author )
	{
		global $woocommerce;

		$shipping_due = 0;
		$method = '';
		$_product     = get_product( $product[ 'product_id' ] );
		$order = wc_get_order( $order_id ); 

		if ( $_product && $_product->needs_shipping() && !$_product->is_downloadable() ) {

			// Get Shipping methods. 
			$shipping_methods = $order->get_shipping_methods();

			// TODO: Currently this only allows one shipping method per order, this definitely needs changing
			foreach ($shipping_methods as $shipping_method) {
					$method = $shipping_method['method_id'];
					break;
			}
						
			// Table Rate Shipping 2
			if ( strstr( $method, 'table_rate' ) !== false ) {
				// $shipping_due = WCV_Shipping::trs2_get_due( $order_id, $product[ 'product_id' ] );

				// Per Product Shipping 2
			} else if ( function_exists( 'woocommerce_per_product_shipping' ) && $method == 'per_product' ) {
				$shipping_due = WCV_Shipping::pps_get_due( $order_id, $product );

				// Local Delivery
			} else if ( $method == 'local_delivery' ) {
				$local_delivery = get_option( 'woocommerce_local_delivery_settings' );

				if ( $local_delivery[ 'type' ] == 'product' ) {
					$shipping_due = $product[ 'qty' ] * $local_delivery[ 'fee' ];
				}

				// International Delivery
			} else if ( $method == 'international_delivery' ) {
				$int_delivery = get_option( 'woocommerce_international_delivery_settings' );

				if ( $int_delivery[ 'type' ] == 'item' ) {
					$WC_Shipping_International_Delivery = new WC_Shipping_International_Delivery();
					$fee                                = $WC_Shipping_International_Delivery->get_fee( $int_delivery[ 'fee' ], $_product->get_price() );
					$shipping_due                       = ( $int_delivery[ 'cost' ] + $fee ) * $product[ 'qty' ];
				}

			}
		}

		$shipping_due = apply_filters( 'wcvendors_shipping_due', $shipping_due, $order_id, $product );

		return $shipping_due;
	}


	/**
	 *
	 *
	 * @param unknown $order_id
	 * @param unknown $product
	 *
	 * @return unknown
	 */
	public function pps_get_due( $order_id, $product )
	{
		global $woocommerce;

		$item_shipping_cost = 0;

		$order = new WC_Order( $order_id );
		$package[ 'destination' ][ 'country' ]  = $order->shipping_country;
		$package[ 'destination' ][ 'state' ]    = $order->shipping_state;
		$package[ 'destination' ][ 'postcode' ] = $order->shipping_postcode;
		$product_id = !empty( $product['variation_id'] ) ? $product['variation_id'] : $product['product_id'];

		if ( !empty( $product['variation_id'] ) ) {
			$rule = woocommerce_per_product_shipping_get_matching_rule( $product['variation_id'], $package );
		}

		if ( empty( $rule ) ) {
			$rule = woocommerce_per_product_shipping_get_matching_rule( $product['product_id'], $package );
		}

		if ( !empty( $rule ) ) {
			$item_shipping_cost += $rule->rule_item_cost * $product[ 'qty' ];

			if ( !empty(self::$pps_shipping_costs[$order_id]) && ! in_array( $rule->rule_id, self::$pps_shipping_costs[$order_id] ) ) {
				$item_shipping_cost += $rule->rule_cost;
			} else if ( empty( self::$pps_shipping_costs[$order_id] ) ) {
				$item_shipping_cost += $rule->rule_cost;
			}

			self::$pps_shipping_costs[$order_id][] = $rule->rule_id;
		}

		return $item_shipping_cost;
	}


	/**
	 *
	 */
	public function trs2_clear_transients()
	{
		global $woocommerce;

		if ( is_checkout() ) {
			wc_delete_product_transients();
		}
	}


	/**
	 *
	 *
	 * @param unknown $order_id
	 * @param unknown $product_id
	 *
	 * @return unknown
	 */
	public function trs2_get_due( $order_id, $product_id )
	{
		if ( !function_exists( 'woocommerce_get_shipping_method_table_rate' ) ) return;

		$shipping_due = 0;

		WCV_Shipping::trs2_retrieve_shipping_data( $order_id );
		if ( !empty( WCV_Shipping::$trs2_shipping_calc_type ) ) {

			$ship_id = ( WCV_Shipping::$trs2_shipping_calc_type == 'class' ) ? get_product( $product_id )->get_shipping_class_id() : $product_id;

			if ( !empty( WCV_Shipping::$trs2_shipping_rates[ $ship_id ] ) ) {
				$shipping_due = WCV_Shipping::$trs2_shipping_rates[ $ship_id ];
				unset( WCV_Shipping::$trs2_shipping_rates[ $ship_id ] );
			}
		}

		return $shipping_due;
	}


	/**
	 *
	 *
	 * @param unknown $order_id
	 */
	public function trs2_retrieve_shipping_data( $order_id )
	{
		global $woocommerce;

		if ( !empty( WCV_Shipping::$trs2_shipping_rates ) ) return;

		WCV_Shipping::$trs2_shipping_rates     = array_filter( (array) get_post_meta( $order_id, '_wcvendors_trs2_shipping_rates', true ) );
		WCV_Shipping::$trs2_shipping_calc_type = get_post_meta( $order_id, '_wcvendors_trs2_shipping_calc_type', true );
	}


	/**
	 *
	 *
	 * @param unknown $type
	 * @param unknown $rates
	 * @param unknown $per_item
	 */
	public function trs2_store_shipping_data( $type, $rates, $per_item )
	{
		global $woocommerce;

		$types                                          = (array) $woocommerce->session->trs2_shipping_class_type;
		$types[ ]                                       = $type;
		$woocommerce->session->trs2_shipping_class_type = $types;

		$items                                     = (array) $woocommerce->session->trs2_shipping_rates;
		$items[ ]                                  = $per_item;
		$woocommerce->session->trs2_shipping_rates = $items;
	}


	/**
	 *
	 *
	 * @param unknown $order_id
	 * @param unknown $post
	 *
	 * @return unknown
	 */
	public function trs2_add_shipping_data( $order_id, $post )
	{
		global $woocommerce;

		if ( empty( $woocommerce->session->trs2_shipping_rates ) ) {
			return false;
		}

		$order = new WC_Order( $order_id );

		foreach ( $woocommerce->session->trs2_shipping_rates as $key => $shipping_rates ) {

			if ( is_array( $shipping_rates ) && array_sum( $shipping_rates ) == $order->order_shipping ) {
				$shipping_calc_type = $woocommerce->session->trs2_shipping_class_type[ $key ];
				update_post_meta( $order_id, '_wcvendors_trs2_shipping_rates', $shipping_rates );
				update_post_meta( $order_id, '_wcvendors_trs2_shipping_calc_type', $shipping_calc_type );

				break;
			}
		}

		unset( $woocommerce->session->trs2_shipping_rates, $woocommerce->session->trs2_shipping_class_type );
	}
}