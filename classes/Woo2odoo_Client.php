<?php
/**
 * Woo2Odoo_Client Class File
 *
 * This file contains the OdooClient class, which is a wrapper for the Odoo JSON-RPC client.
 * It includes methods for handling exceptions and logging to WooCommerce logs.
 *
 * @package Woo2Odoo
 */
namespace Woo2Odoo;

/**
 * Class Woo2odoo_Client
 */
class Woo2odoo_Client {

	// @var bool Indicates whether the client is authenticated
	private $is_authenticated = false;

	/**
	 * @var string The session ID returned by the Odoo server
	 */
	private $session_id;

	/**
	 * @var array an array that stores the default mapping for fields between Odoo and WooCommerce
	 */
	public $default_mapping = array(
		'processing' => 'in_payment',
		'completed'  => 'paid',
		'pending'    => 'quote_order',
		'failed'     => 'cancelled',
		'on-hold'    => 'quote_only',
		'cancelled'  => 'cancelled',
		'refunded'   => 'refunded',
	);

	/**
	 * @var \winternet\odoo\JsonRpcClient
	 */
	private $client;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->client = Woo2Odoo_ClientFactory::instance()->get_client();
	}

	/**
	 * Get the Odoo client
	 *
	 * @return \winternet\odoo\JsonRpcClient
	 */
	private function get_client() {
		if ( !$this->client ) {
			$this->client = OdooClientFactory::instance()->get_client();
		}
		return $this->client;
	}

	/**
	 * @param array|integer $IDs : array of record IDs to read or single ID (integer) to read
	 * @param array $fields : if set, the result will only include these fields
	 * @param array $options : Available options:
	 *   - `indexBy` : field name to index the returned array by
	 *   - `single` : set true to return a single record, or null if nothing found. Or set string 'require' to throw Exception if nothing found
	 *   - `expandFields` : Expand a field with an array of record IDs into a new property called `_expanded`. Eg. `['invoice_line_ids' => ['model' => 'account.move.line']]�
	 */
	public function read( $model, $ids = array(), $fields = array(), $options = array() ) {
		if ( !$this->authenticate() ) {
			return false;
		}

		try {
			return $this->get_client()->read( $model, $ids, $fields, $options );
		} catch (Exception $e) {
			$this->log_exception( 'Odoo read failed', $e );
			return false;
		}
	}

	/**
	 * Call to function search_read in Odoo
	 *
	 * @param array $args : Available keys:
	 *  - `where` : Filter/conditions (called "domain" in Odoo terms). Eg.: `[
	 *      [
	 *          'move_type',
	 *          '=',    // docs: https://stackoverflow.com/questions/29442993/which-are-the-available-domain-operators-in-openerp-odoo
	 *          'out_invoice',
	 *      ],
	 *  ]`
	 *  - `fields` : array of fields to return, eg. `['date', 'sequence_number']`
	 *  - `offset` : numeric, eg. `0`
	 *  - `limit` : numeric, eg. `20`
	 *  - `order` : Eg. `'name'` or `'name DESC'`
	 *
	 * @param array $options : Available options:
	 *   - `indexBy` : field name to index the returned array by
	 *   - `single` : set true to return a single record, or null if nothing found. Or set string 'require' to throw Exception if nothing found
	 *   - `expandFields` : Expand a field with an array of record IDs into a new property called `_expanded`. Eg. `['invoice_line_ids' => ['model' => 'account.move.line']]�
	 */
	public function search_read( $model, $where = array(), $fields = array(), $offset = null, $limit = null, $order = null, $options = array() ) {

		if ( !$this->authenticate() ) {
			return false;
		}
		// Generate a unique cache key
		$cache_key = 'odoo_search_read_' . md5( wp_json_encode( func_get_args() ) );

		// Check if the cache exists
		$cached_result = wp_cache_get( $cache_key, 'woo2odoo' );
		if ( false !== $cached_result ) {
			return $cached_result;
		}

		try {
			$args = array(
				'where'  => $where,
				'fields' => $fields,
				'offset' => $offset,
				'limit'  => $limit,
				'order'  => $order,
			);

			// Perform the search_read operation
			$result = $this->get_client()->searchRead( $model, $args, $options );

			// Store the result in a transient
			wp_cache_set( $cache_key, $result, 'woo2odoo', HOUR_IN_SECONDS );

			return $result;
		} catch (Exception $e) {
			$this->log_exception( 'Odoo search_read failed', $e );
			return false;
		}
	}

	public function authenticate() {
		try {
			if ( !$this->is_authenticated ) {
				$this->session_id       = $this->get_client()->authenticate();
				$this->is_authenticated = (bool) $this->session_id;
				if ( !$this->is_authenticated ) {
					$this->log_warning( 'Odoo authentication failed', array( 'session_id' => $this->session_id ) );
				}
			}
			return $this->is_authenticated;
		} catch (Exception $e) {
			$this->log_exception( 'Odoo authentication failed', $e );
			return false;
		}
	}

	public function create( $model, $data ) {
		if ( !$this->authenticate() ) {
			return false;
		}
		try {
			return $this->get_client()->create( $model, $data );
		} catch (Exception $e) {
			$this->log_exception( 'Odoo create failed', $e );
			return false;
		}
	}


	public function order_sync( $order_id ) {
		if ( !$this->authenticate() ) {
			return false;
		}

		try {
			$order = wc_get_order( $order_id );
			if ( !$order ) {
				$this->log_warning( 'Odoo order_sync failed: Order not found', array( 'order_id' => $order_id ) );
				return false;
			}

			// Search if the order exists in Odoo
			$odoo_order = $this->search_read(
				'sale.order',
				array(
					array(
						'origin',
						'=',
						(int) $order_id,
					),
				),
				array( 'id', 'amount_total', 'state', 'invoice_status' ),
				null,
				1,
				null,
				array( 'single' => true )
			);
			if ( !$odoo_order ) {
				// Create the order in Odoo

				// Get the customer data
				$customer_data = $this->get_customer_data( $order );

				$order_data = array(
					'partner_id'         => (int) $customer_data['id'],
					'partner_invoice_id' => (int) $customer_data['invoice_id'],
					'state'              => $this->default_mapping[ $order->get_status() ],
					'note'               => __( 'Woo Order Id : ', 'wc2odoo' ) . $order_id,
					'payment_term_id'    => 1,
					'origin'             => $order_id,
					'date_order'         => date_format( $order->get_date_created(), 'Y-m-d H:i:s' ),
				);

				$odoo_order = $this->create( 'sale.order', $order_data );

				// Check if creation went ok
				if ( !$odoo_order ) {
					$this->log_error( 'Failed to create order in Odoo', $order_data );
					return false;
				}

			} elseif ( $odoo_order['state'] !== $this->default_mapping[ $order->get_status() ] ) {
				$this->log_info(
					'Order status mismatch',
					array(
						'order_id'     => $order_id,
						'order_status' => $order->get_status(),
						'odoo_status'  => $odoo_order['state'],
					)
				);
			}
			// Add order line items
			$this->add_order_line_items( $order, $odoo_order, (int) $customer_data['id'] );

		} catch (Exception $e) {
			$this->log_exception( 'Odoo order_sync failed', $e );
			return false;
		}
	}

	/**
	 * Manage Customer Data.
	 *
	 * @param WC_Order $order order objects data.
	 *
	 * @return array|bool $customer_data  return customer data or false if error is found
	 */
	public function get_customer_data( $order ) {
		if ( !$this->authenticate() ) {
			return false;
		}

		$customer_data         = array();
		$billing_address_order = $order->get_address( 'billing' );
		$user                  = $order->get_user();

		if ( $user ) {
			$email = $user->user_email;
		} else {
			$email = $billing_address_order['email'];
		}

		if ( $email ) {
			$customer_id = $this->search_read(
				'res.partner',
				array(
					array(
						'email',
						'=',
						$email,
					),
					array(
						'type',
						'=',
						'contact',
					),
				),
				array( 'id' ),
				null,
				1,
				null,
				array( 'single' => true )
			);

			// If user not exists in Odoo then Create New Customer in odoo.
			if ( empty( $customer_id ) || false === $customer_id ) {
				$this->log_info( 'User not found in Odoo, creating new', array( 'User Email' => $email ) );
				$customer_id = $this->create_or_update_customer( $user, null );
			} else {
				// Remove std class object from the result to match the create return value.
				$customer_id = (int) $customer_id->id;
			}

			if ( is_numeric( $customer_id ) ) {
				$customer_data['id']          = $customer_id;
				$customer_data['invoice_id']  = $this->get_or_create_address( 'invoice', $billing_address_order, $customer_id );
				$shipping_address_order       = $order->get_address( 'shipping' );
				$customer_data['shipping_id'] = $this->get_or_create_address( 'delivery', $shipping_address_order, $customer_id );
			}
		}

		return $customer_data;
	}

	/**
	 * Create or update customer.
	 *
	 * @param WP_User    $customer_data Customer data.
	 * @param int|null   $customer_id   Customer ID.
	 */
	public function create_or_update_customer( $customer_data, $customer_id ) {

		if ( !$customer_data ) {
			$this->log_error( 'Error creating customer in Odoo', array( 'msg' => 'Customer data is empty' ) );
			return false;
		}

		$all_meta_for_user = get_user_meta( $customer_data->ID );
		$state_county      = $this->get_state_and_country_codes( $all_meta_for_user['billing_state'][0], $all_meta_for_user['billing_country'][0] );
		$data              = array(
			'name'                              => get_user_meta( $customer_data->ID, 'first_name', true ) . ' ' . get_user_meta( $customer_data->ID, 'last_name', true ),
			'display_name'                      => get_user_meta( $customer_data->ID, 'first_name', true ) . ' ' . get_user_meta( $customer_data->ID, 'last_name', true ),
			'email'                             => $customer_data->user_email,
			'customer_rank'                     => 1,
			'type'                              => 'contact',
			'phone'                             => $all_meta_for_user['billing_phone'][0],
			'street'                            => $all_meta_for_user['billing_address_1'][0],
			'city'                              => $all_meta_for_user['billing_city'][0],
			'state_id'                          => $state_county['state'],
			'country_id'                        => $state_county['country'],
			'zip'                               => $all_meta_for_user['billing_postcode'][0],
			'l10n_latam_identification_type_id' => '4',
			'vat'                               => $this->format_rut( $all_meta_for_user['billing_rut'][0] ),
			'l10n_cl_sii_taxpayer_type'         => '1',
			'l10n_cl_dte_email'                 => $all_meta_for_user['billing_email'][0],
			'l10n_cl_activity_description'      => !empty( $all_meta_for_user['billing_giro'][0] ) ? $all_meta_for_user['billing_giro'][0] : 'Manicurista',
		);

		if ( $customer_id ) {
			$response = $this->update_record( 'res.partner', $customer_id, $data );
		} else {
			$response = $this->create_record( 'res.partner', $data );
		}

		return $response;
	}

	/**
	 * Get the state ID based on the state code and country code.
	 *
	 * @param string $state_code The state code.
	 * @param string $country_code The country code.
	 * @return array The state ID.
	 */
	public function get_state_and_country_codes( $state_code, $country_code ) {
		if ( !$this->authenticate() ) {
			return false;
		}
		$state_codes = array();

		$country = $this->search_read( 'res.country', array( array( 'code', '=', $country_code ) ), array( 'id' ), null, 1, null, array( 'single' => true ) );
		if ( $country ) {
			$state_codes['country'] = $country->id;

			if ( 'Región Metropolitana de Santiago' === $state_code ) {
				$state_code = 'Metropolitana';
			}
			$states = $this->search_read( 'res.country.state', array( array( 'name', 'like', "%{$state_code}%" ), array( 'country_id', '=', $country->id ) ), array( 'id' ), null, 1, null, array( 'single' => true ) );
			if ( $states ) {
				$state_codes['state'] = $states->id;
			} else {
				$state_codes['state'] = false;
			}
		} else {
			$state_codes['country'] = false;
			$state_codes['state']   = false;
		}

		return $state_codes;
	}

	/**
	 * Update record.
	 * @param string $model Model name.
	 * @param int    $id Record ID.
	 * @param array  $data Data to update.
	 * @return array|bool
	 */
	public function update_record( $model, $id, $data ) {
		if ( !$this->authenticate() ) {
			return false;
		}
		try {
			return $this->get_client()->update( $model, $id, $data );
		} catch ( Exception $e ) {
			$this->log_exception( 'Odoo update failed', $e );
			return false;
		}
	}

	/**
	 * Create record.
	 * @param string $model Model name.
	 * @param array  $data Data to create.
	 * @return array|bool
	 */
	public function create_record( $model, $data ) {
		if ( !$this->authenticate() ) {
			return false;
		}
		try {
			return $this->get_client()->create( $model, $data );
		} catch ( Exception $e ) {
			$this->log_exception( 'Odoo create failed', $e );
			return false;
		}
	}

	/**
	 * Create data for the odoo customer address.
	 *
	 * @param  string  $address_type address type delivery/invoice.
	 * @param  array   $userdata    [user data].
	 * @param  integer $parent_id   [user_id ].
	 *
	 * @return array              [formated address data for the customer]
	 */
	public function create_address_data( $address_type, $userdata, $parent_id ) {
		$data = array(
			'name'      => $userdata['first_name'] . ' ' . $userdata['last_name'],
			'email'     => $userdata['email'] ?? '',
			'street'    => $userdata['address_1'],
			'street2'   => $userdata['address_2'],
			'zip'       => $userdata['postcode'],
			'city'      => $userdata['city'] ?? '',
			'type'      => $address_type,
			'parent_id' => (int) $parent_id,
			'phone'     => $userdata['phone'] ?? false,
		);

		if ( ! empty( $userdata['state'] ) || ! empty( $userdata['country'] ) ) {
			$state_county = $this->get_state_and_country_codes( $userdata['state'], $userdata['country'] );
			if ( ! empty( $state_county ) ) {
				$data['state_id']   = $state_county['state'];
				$data['country_id'] = $state_county['country'];
			}
		}

		return $data;
	}

	public function format_rut( $rut ) {
		// Remove any non-numeric characters
		$rut = preg_replace( '/[^0-9]/', '', $rut );

		// Insert the hyphen before the last character
		$formatted_rut = substr( $rut, 0, -1 ) . '-' . substr( $rut, -1 );

		return $formatted_rut;
	}

	private function log_exception( $message, $exception ) {
		wc_get_logger()->error(
			$message,
			array(
				'message' => $exception->getMessage(),
				'code'    => $exception->getCode(),
				'trace'   => $exception->getTraceAsString(),
			)
		);
	}

	private function log_warning( $message, $context = array() ) {
		wc_get_logger()->warning( $message, $context );
	}

	private function log_info( $message, $context = array() ) {
		wc_get_logger()->info( $message, $context );
	}

	private function log_error( $message, $context = array() ) {
		wc_get_logger()->error( $message, $context );
	}

	private function prepare_order_data( $order ) {
		$order_data          = $order->get_data();
		$order_lines         = $order->get_items();
		$order_data['lines'] = array();
		foreach ($order_lines as $line) {
			$order_data['lines'][] = $line->get_data();
		}
		return $order_data;
	}

	private function get_or_create_address( $type, $address_order, $customer_id ) {
		$is_new_address = true;
		$odoo_address   = $this->search_read(
			'res.partner',
			array(
				array( 'parent_id', '=', $customer_id ),
				array( 'type', '=', $type ),
				array( 'street', '=ilike', $address_order['address_1'] ),
				array( 'zip', '=', $address_order['postcode'] ),
			),
			array( 'id' ),
			null,
			1,
			null,
			array( 'single' => true )
		);
		if ( $odoo_address ) {
			$is_new_address = false;
			return $odoo_address->id;
		}
		if ( $is_new_address ) {
			$address    = $this->create_address_data( $type, $address_order, $customer_id );
			$address_id = $this->create( 'res.partner', $address );
			if ( $address_id && !isset( $address_id['faultString'] ) ) {
				return $address_id;
			} else {
				$this->log_error( 'Error creating address for customer', array( 'Address' => $address ) );
				return false;
			}
		}
	}

	/**
	 * Get all the SKUs in Odoo for a given order
	 *
	 * @param WC_Order $order
	 * @return array
	 *
	 */
	public function get_odoo_skus( $order ) {
		$skus        = array();
		$order_items = $order->get_items();
		foreach ($order_items as $item) {
			$skus[] = $item->get_product()->get_sku();
		}
		// Query Odoo for the products
		$products = $this->search_read( 'product.product', array( array( 'default_code', 'in', $skus ) ), array( 'default_code', 'id' ), null, 1000, null, array( 'indexBy' => 'default_code' ) );
		return $products;
	}


	/**
	 * Add order line item to Odoo
	 *
	 * @param WC_Order $order
	 * @param array $odoo_order
	 * @param int $customer_id
	 *
	 */
	public function add_order_line_items( $order, $odoo_order, $customer_id ) {
		$order_items   = $order->get_items();
		$odoo_products = $this->get_odoo_skus( $order );

		foreach ($order_items as $item) {
			$product      = $item->get_product();
			$odoo_product = $odoo_products[ $product->get_sku() ] ?? false;
			if ( $odoo_product ) {
				$unit_price = number_format( (float) ( $item->get_total() / $item->get_quantity() ), 2, '.', '' );

				$line_data = array(
					'order_partner_id' => $customer_id,
					'order_id'         => $odoo_order['id'],
					'product_id'       => $odoo_product['id'],
					'product_uom_qty'  => $item->get_quantity(),
					'price_unit'       => $unit_price,
				);
				if ( $item->get_total_tax() > 0 ) {
					//Hardcoded IVA tax id, check if the array( 6, 0, array (3) ) is correct
					$line_data['tax_id'] = array( array( 6, 0, array( 3 ) ) );
				} else {
					$line_data['tax_id'] = array( array( 6, 0, array() ) );
				}

				$this->create( 'sale.order.line', $line_data );
			}
		}
	}
}
