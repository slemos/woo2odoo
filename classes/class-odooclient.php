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

    public function search($model, $domain = [], $offset = 0, $limit = 0, $fields = []) {
        try {
            return $this->get_client()->search($model, $domain, $offset, $limit, $fields);
        } catch (\winternet\odoo\OdooException $e) {
            $this->log_error($e);
            return false;
        }
    }
    
    public function authenticate() {
        try {
            return $this->get_client()->authenticate();
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