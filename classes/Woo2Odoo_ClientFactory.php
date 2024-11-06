<?php
/**
 * Woo2Odoo_ClientFactory
 *
 * @class       Woo2Odoo_ClientFactory
 * @version 1.0.0
 * @package Woo2Odoo
 * @category    Class
 * @author slemos
 */
namespace Woo2Odoo;

use winternet\odoo\JsonRpcClient;

class Woo2Odoo_ClientFactory {

	/**
	 * The single instance of OdooClientFactory.
	 * @var     object
	 * @access  private
	 * @since   1.0.0
	 */
	private static $instance = null;

	private $client;

	public function create_client(): JsonRpcClient {
		$settings = Woo2Odoo_Plugin::instance()->settings;
		// TODO secure injections
		$host      = $settings->get_value( 'odoo_url', '', 'connection' );
		$odoo_db   = $settings->get_value( 'dbname', '', 'connection' );
		$odoo_user = $settings->get_value( 'odoo_user', '', 'connection' );
		$odoo_pwd  = $settings->get_value( 'odoo_password', '', 'connection' );

		$this->client = new JsonRpcClient(
			$host,
			$odoo_db,
			$odoo_user,
			$odoo_pwd
		);
		return $this->client;
	}

	public function get_client(): JsonRpcClient {
		// You can force nenewing a Client based on createdAt
		if (!$this->client) {
			$this->client = $this->create_client();
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
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}
