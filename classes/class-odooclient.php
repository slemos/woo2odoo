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

    public function read($model, $ids = [], $fields = [], $options = []) {
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
            // Check if the settings are getting updated
            if ( isset( $_GET['settings-updated'] ) && "true" === $_GET['settings-updated'] ) {
                // Clear the transient
                wp_cache_deletet( 'session_id', 'woo2odoo' );
            }
            // Check if we have a transient with the Odoo session id
            if ( $session_id = wp_cache_get( 'session_id', 'woo2odoo' ) ) {
                return $session_id;
            }
            else {
                $session_id = $this->get_client()->authenticate();
                wp_cache_set('session_id', $session_id, 'woo2odoo', HOUR_IN_SECONDS);
                return $session_id;
            }
        } catch (Exception $e) {
            wc_get_logger()->info('Odoo authentication failed with exception', array(
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ));
            return false;
        }
    }
}