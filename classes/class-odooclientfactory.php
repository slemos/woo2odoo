<?php
/**
 * OdooClientFactory
 *
 * @class       OdooClientFactory
 * @version	1.0.0
 * @package	Woo2Odoo_Plugin
 * @category	Class
 * @author slemos
 */

class OdooClientFactory
{
    /**
	 * The single instance of OdooClientFactory.
	 * @var 	object
	 * @access  private
	 * @since 	1.0.0
	 */
	private static $instance = null;

    private $client;

    public function createClient() : \winternet\odoo\JsonRpcClient
    {
        $settings = Woo2Odoo_Plugin::instance()->settings;
        // TODO secure injections
        $host  = $settings->get_value('odoo_url', '', 'connection');
        $odooDb   = $settings->get_value('dbname', '', 'connection');
        $odooUser = $settings->get_value('odoo_user', '', 'connection');
        $odooPwd  = $settings->get_value('odoo_password', '', 'connection');

        $this->client = new \winternet\odoo\JsonRpcClient(
            $host,
            $odooDb,
            $odooUser,
            $odooPwd
        );
        return $this->client;
    }
    
    public function getClient() : \winternet\odoo\JsonRpcClient
    {
        // You can force nenewing a Client based on createdAt
        if (!$this->client) {
            $this->client = $this->createClient();
        }
        return $this->client;
    }

	/**
	 * Main OdooClientFactory Instance
	 *
	 * Ensures only one instance of OdooClientFactory is loaded or can be loaded.
	 *
	 * @since 1.0.0
	 * @static
	 * @see Woo2Odoo_Plugin()
	 * @return Main Woo2Odoo_Plugin instance
	 */
	public static function instance () {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}