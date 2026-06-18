<?php
/**
 * Main Woo2Odoo_Plugin Class
 *
 * @class Woo2Odoo_Plugin
 * @version 1.0.0
 * @since 1.0.0
 * @package Woo2Odoo_Plugin
 * @author Slemos
 */
namespace Woo2Odoo;

final class Woo2Odoo_Plugin {
	/**
	 * Woo2Odoo_Plugin The single instance of Woo2Odoo_Plugin.
	 * @var     object
	 * @access  private
	 * @since   1.0.0
	 */
	private static $instance = null;

	/**
	 * The token.
	 * @var     string
	 * @access  public
	 * @since   1.0.0
	 */
	public $token;

	/**
	 * The version number.
	 * @var     string
	 * @access  public
	 * @since   1.0.0
	 */
	public $version;

	/**
	 * The plugin directory URL.
	 * @var     string
	 * @access  public
	 * @since   1.0.0
	 */
	public $plugin_url;

	/**
	 * The plugin directory path.
	 * @var     string
	 * @access  public
	 * @since   1.0.0
	 */
	public string $plugin_path;

	// Admin - Start
	/**
	 * The admin object.
	 * @var     object
	 * @access  public
	 * @since   1.0.0
	 */
	public Woo2Odoo_Plugin_Admin $admin;

	/**
	 * The settings object for the Woo2Odoo plugin.
	 *
	 * @var     object
	 * @access  public
	 * @since   1.0.0
	 */
	public Woo2Odoo_Plugin_Settings $settings;
	// Admin - End

	/**
	 * Constructor function.
	 * @access  public
	 * @since   1.0.0
	 */
	public function __construct() {
		$this->token       = 'woo2odoo-plugin';
		$this->plugin_url  = plugin_dir_url( __FILE__ );
		$this->plugin_path = plugin_dir_path( __FILE__ );
		$this->version     = '1.0.0';

		// Admin - Start
		$this->settings = Woo2Odoo_Plugin_Settings::instance();

		if ( is_admin() ) {
			$this->admin = Woo2Odoo_Plugin_Admin::instance();
		}
		// Admin - End

		// Post Types - End
		register_activation_hook( __FILE__, array( $this, 'install' ) );

		add_action( 'init', array( $this, 'load_plugin_textdomain' ) );

		// Auto-sync orders to Odoo when status changes to processing or on-hold
		add_action( 'woocommerce_order_status_processing', array( $this, 'auto_sync_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_on-hold', array( $this, 'auto_sync_order' ), 10, 1 );
	}

	/**
	 * Automatically sync a WooCommerce order to Odoo when its status changes.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function auto_sync_order( int $order_id ): void {
		try {
			$order_manager = new Woo2Odoo_Order_Manager();
			$order_manager->order_sync( $order_id );
		} catch ( \Throwable $e ) {
			// Log but don't crash checkout flow
			error_log( 'woo2odoo auto_sync_order failed for order ' . $order_id . ': ' . $e->getMessage() );
		}
	}

	/**
	 * Main Woo2Odoo_Plugin Instance
	 *
	 * Ensures only one instance of Woo2Odoo_Plugin is loaded or can be loaded.
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

	/**
	 * Load the localisation file.
	 * @access  public
	 * @since   1.0.0
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain( 'woo2odoo-plugin', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	/**
	 * Cloning is forbidden.
	 * @access public
	 * @since 1.0.0
	 */
	public function __clone() {}

	/**
	 * Unserializing instances of this class is forbidden.
	 * @access public
	 * @since 1.0.0
	 */
	public function __wakeup() {}

	/**
	 * Installation. Runs on activation.
	 * @access  public
	 * @since   1.0.0
	 */
	public function install() {
		$this->log_version_number();
	}

	/**
	 * Log the plugin version number.
	 * @access  private
	 * @since   1.0.0
	 */
	private function log_version_number() {
		// Log the version number.
		update_option( $this->token . '-version', $this->version );
	}
}
