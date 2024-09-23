<?php

/**
 * Class OdooClient
 * 
 * Wrapper for the Odoo JSON-RPC client, used to control the exceptions and errors
 * Log to WooCommerce logs
 * 
 */

require_once 'class-odooclientfactory.php';

class OdooClient {

    private $is_authenticated = false;

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

    private $client;

    public function __construct() {
        $this->client = OdooClientFactory::instance()->getClient();
    }

    private function get_client() {
        if ( !$this->client ) {
            $this->client = OdooClientFactory::instance()->getClient();
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
    public function read($model, $ids = [], $fields = [], $options = []) {
        if ( !$this->authenticate() ) {
            return false;
        }

        try {
            return $this->get_client()->read($model, $ids, $fields, $options);
        } catch (Exception $e) {
            wc_get_logger()->info('Odoo search failed with exception', array(
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ));
            return false;
        }
    }
    
    /**
     * Call to function search_read in Odoo
     * 
	 * @param array $args : Available keys:
	 *  - `where` : Filter/conditions (called "domain" in Odoo terms). Eg.: `[
	 *  	[
	 *  		'move_type',
	 *  		'=',    // docs: https://stackoverflow.com/questions/29442993/which-are-the-available-domain-operators-in-openerp-odoo
	 *  		'out_invoice',
	 *  	],
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
    public function search_read( $model, $where = [], $fields = [], $offset = null, $limit = null, $order = null, $options = [] ) {
        
        if ( !$this->authenticate() ) {
            return false;
        }
       // Generate a unique cache key
        $cache_key = 'odoo_search_read_' . md5(serialize(func_get_args()));

        // Check if the cache exists
        $cached_result = wp_cache_get( $cache_key, 'woo2odoo' );
        if ($cached_result !== false) {
            return $cached_result;
        }
        
        try {
            $args = [
                'where' => $where,
                'fields' => $fields,
                'offset' => $offset,
                'limit' => $limit,
                'order' => $order
            ];

            // Perform the search_read operation
            $result = $this->get_client()->searchRead($model, $args, $options);

            // Store the result in a transient
            wp_cache_set($cache_key, $result, 'woo2odoo', HOUR_IN_SECONDS);

            return $result;
        } catch (Exception $e) {
            wc_get_logger()->info('Odoo search_read failed with exception', array(
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ));
            return false;
        }
    }

    public function authenticate() {
        try {
            if ( !$this->is_authenticated ) {
                $this->session_id = $this->get_client()->authenticate();
                if ( $this->session_id ) {
                    $this->is_authenticated = true;
                }
                else {
                    $this->is_authenticated = false;
                    wc_get_logger()->warning('Odoo authentication failed', array(
                        'session_id' => $this->session_id
                    ));
                }
            }
            return $this->is_authenticated;
        } catch (Exception $e) {
            wc_get_logger()->info('Odoo authentication failed with exception', array(
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ));
            return false;
        }
    }

    public function create($model, $data) {
        if ( !$this->authenticate() ) {
            return false;
        }
        try {
            return $this->get_client()->create($model, $data);
        } catch (Exception $e) {
            wc_get_logger()->error('Odoo create failed with exception', array(
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ));
            return false;
        }
    }


    public function order_sync ( $order_id ) {
        if ( !$this->authenticate() ) {
            return false;
        }

        try {
            $order = wc_get_order( $order_id );
            if ( !$order ) {
                wc_get_logger()->warning('Odoo order_sync failed: Order not found', array(
                    'order_id' => $order_id
                ));
                return false;
            }

            // Search if the order exists in Odoo
            $odoo_order = $this->search_read('sale.order', [
                [
                    'origin',
                    '=',
                    (int) $order_id,
                ],
            ],  ['id', 'amount_total', 'state', 'invoice_status'], null, 1, null, ['single' => true]);
            if ( !$odoo_order ) {
                // Create the order in Odoo
                $order_data = $order->get_data();
                $order_lines = $order->get_items();
                $order_data['lines'] = [];
                foreach ($order_lines as $line) {
                    $order_data['lines'][] = $line->get_data();
                }

                $odoo_order = $this->create('sale.order', $order_data);
            }
            else {
                //Already exists, validate if the status matches
                if ( $odoo_order['state'] !== $this->default_mapping[$order->get_status()] ) {
                    wc_get_logger()->info('Order status mismatch', array(
                        'order_id' => $order_id,
                        'order_status' => $order->get_status(),
                        'odoo_status' => $odoo_order['state'],
                    ));

                }

            }


            $order = wc_get_order( $order_id );
            if ( !$order ) {
                return false;
            }



            $order_data = $order->get_data();
            $order_lines = $order->get_items();
            $order_data['lines'] = [];
            foreach ($order_lines as $line) {
                $order_data['lines'][] = $line->get_data();
            }
            




        } catch (Exception $e) {
            wc_get_logger()->info('Odoo order_sync failed with exception', array(
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ));
            return false;
        }
    }

    /**
     * Manage Customer Data.
     *
     * @param object $user  userdata.
     * @param object $order order objects data.
     *
     * @return array|bool $customer_data  return customer data or false if error is found
     */
    public function get_customer_data( $user, $order ) {
        if ( !$this->authenticate() ) {
            return false;
        }
        
        $customer_data = array();

        if ( $user && $user->user_email !== null ) {
            $customer_id = get_user_meta( $user->ID, '_odoo_id', true );
            wc_get_logger()->info( 'Customer id ',  [ 'Odoo Id' => $customer_id ] );

            // If we haven't this user sync to Odoo.
            if ( ! $customer_id ) {
                // Search record in the Odoo By email.
                $conditions  = array( array( 'email', '=', $user->user_email ) );
                $customer_id = $this->search_read( 'res.partner', [[
                    'email',
                    '=',
                    $user->user_email,
                ]], ['id'], null, 1, null, ['single' => true] );

                // If user not exists in Odoo then Create New Customer in odoo.
                if ( empty( $customer_id ) || false === $customer_id ) {
                    wc_get_logger()->info( 'User not found in Odoo proceed to create it', [ 'User Email' => $user->user_email ] );
                    exit();
                    $customer_id = $this->create_or_update_customer( $user, null );
                    update_user_meta( $user->ID, '_odoo_id', $customer_id );
                }
            }

            if ( is_numeric( $customer_id->id ) ) {
                $customer_data['id'] = $customer_id->id;
                $is_new_billing_address = true;

                $billing_address = $this->create_address_data( 'invoice', $order->get_address( 'billing' ), $customer_id->id );

                // Search the billing address in the odoo.
                $odoo_billing_address = $this->search_read( 'res.partner', [[
                    'parent_id', '=', $customer_id->id,],
                    ['type', '=', 'invoice'],
                    ['street', '=ilike', $billing_address['street']],
                    ['zip', '=', $billing_address['zip']],
                ], ['id'], null, 1, null, ['single' => true] );

                if ( $odoo_billing_address ) {
                    $customer_data['invoice_id'] = $odoo_billing_address->id;
                    $is_new_billing_address      = false;
                }

                if ( $is_new_billing_address ) {
                    $billing_id = $this->create( 'res.partner', $billing_address );

                    if ( $billing_id && ! isset( $billing_id['faultString'] ) ) {
                        $customer_data['invoice_id'] = $billing_id;
                    } else {
                        wc_get_logger()->error( 'Error for creating billing address for customer', ['Billing' => $billing_address ] );
                        return false;
                    }
                }
                $is_new_shipping_address = true;
                
                $shipping_address = $this->create_address_data( 'delivery', $order->get_address( 'shipping' ), $customer_id->id );

                // Search the shipping address in the odoo.
                $odoo_shipping_address = $this->search_read( 'res.partner', [[
                    'parent_id', '=', $customer_id->id,],
                    ['type', '=', 'invoice'],
                    ['street', '=ilike', $shipping_address['street']],
                    ['zip', '=', $shipping_address['zip']],
                ], ['id'], null, 1, null, ['single' => true] );

                if ( $odoo_shipping_address ) {
                    $customer_data['shipping_id'] = $odoo_shipping_address->id;
                    $is_new_shipping_address      = false;
                }

                if ( $is_new_shipping_address ) {
                    $shipping_id = $odoo_api->create_record( 'res.partner', $shipping_address );

                    if ( $shipping_id ) {
                        $customer_data['shipping_id'] = $shipping_id;
                    } else {
                        wc_get_logger()->error( 'Error for creating shipping address for customer', ['Shipping' => $shipping_address ] );
                        return false;
                    }
                }
            }
        }

        if ( ! $user || false === $user ) {
            $customer = $this->search_or_create_guest_user( $order );

            if ( ! $customer ) {
                $error_msg = 'Error for Search customer =>' . $user->user_email . 'Msg : ' . print_r( $customer['msg'], true );
                $odoo_api->add_log( $error_msg );

                return false;
            }
            $customer_id                  = $customer;
            $customer_data['id']          = $customer_id;
            $customer_data['invoice_id']  = $billing_id ?? $customer_id;
            $customer_data['shipping_id'] = $shipping_id ?? $customer_id;
        }

        return $customer_data;
    }


    /**
     * Create or update customer.
     *
     * @param mixed    $customer_data Customer data.
     * @param int|null $customer_id   Customer ID.
     */
    public function create_or_update_customer( $customer_data, $customer_id ) {
        if ( !$this->authenticate() ) {
            return false;
        }

        $all_meta_for_user = get_user_meta( $customer_data->ID );
        $state_county      = $this->get_state_and_country_codes( $all_meta_for_user['billing_state'][0], $all_meta_for_user['billing_country'][0] );
        $data              = array(
            'name'          => get_user_meta( $customer_data->ID, 'first_name', true ) . ' ' . get_user_meta( $customer_data->ID, 'last_name', true ),
            'display_name'  => get_user_meta( $customer_data->ID, 'first_name', true ) . ' ' . get_user_meta( $customer_data->ID, 'last_name', true ),
            'email'         => $customer_data->user_email,
            'customer_rank' => 1,
            'type'          => 'contact',
            'phone'         => $all_meta_for_user['billing_phone'][0],
            'street'        => $all_meta_for_user['billing_address_1'][0],
            'city'          => $all_meta_for_user['billing_city'][0],
            'state_id'      => $state_county['state'],
            'country_id'    => $state_county['country'],
            'zip'           => $all_meta_for_user['billing_postcode'][0],
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
        
        $country = $this->search_read( 'res.country', [ [ 'code', '=', $country_code ] ], ['id'], null, 1, null, ['single' => true] );
        if ( $country ) {
            $state_codes['country'] = $country->id;

            if ( 'Región Metropolitana de Santiago' === $state_code ) {
                $state_code = 'Metropolitana';
            }
            $states = $this->search_read( 'res.country.state', [ [ 'name', 'like', "%{$state_code}%" ], [ 'country_id', '=', $country->id ] ], ['id'], null, 1, null, ['single' => true] );
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
            wc_get_logger()->error( 'Odoo update failed with exception', array(
                'message' => $e->getMessage(),
                'code'    => $e->getCode(),
                'trace'   => $e->getTraceAsString(),
            ) );
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
            wc_get_logger()->error( 'Odoo create failed with exception', array(
                'message' => $e->getMessage(),
                'code'    => $e->getCode(),
                'trace'   => $e->getTraceAsString(),
            ) );
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
        $data     = array(
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
}