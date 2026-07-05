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
		$this->token       = 'Woo2Odoo-plugin';
		$this->plugin_url  = plugin_dir_url( __FILE__ );
		$this->plugin_path = plugin_dir_path( __FILE__ );
		$this->version     = '1.0.0';

		// Admin - Start
		$this->settings = Woo2Odoo_Plugin_Settings::instance();

		if ( is_admin() ) {
			$this->admin = Woo2Odoo_Plugin_Admin::instance();
			Woo2Odoo_Admin_Order_Metabox::register();
			Woo2Odoo_Sync_Status_Tab::register();
		}
		// Admin - End

		// Post Types - End
		add_action( 'init', array( $this, 'load_plugin_textdomain' ) );

		// Auto-sync orders to Odoo when status changes to processing or on-hold
		add_action( 'woocommerce_order_status_processing', array( $this, 'auto_sync_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_on-hold', array( $this, 'auto_sync_order' ), 10, 1 );

		// Auto-create credit note in Odoo when WC registers a refund
		add_action( 'woocommerce_order_refunded', array( $this, 'auto_refund_order' ), 10, 2 );

		// Stock sync cron hook
		add_action( 'odoo_process_import_update_stocks', array( $this, 'run_stock_sync' ) );

		// Reschedule stock sync when export settings are updated
		add_action( 'update_option_Woo2Odoo-plugin-export', array( $this, 'reschedule_stock_sync' ), 10, 2 );

		// Ensure cron is scheduled on every WP load if setting is active and cron is missing
		add_action( 'init', array( $this, 'maybe_schedule_stock_sync' ) );

		// Ensure plugin options are not autoloaded (avoids stale Redis alloptions blob)
		add_action( 'admin_init', array( $this, 'fix_options_autoload' ) );
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
	 * Automatically create a credit note in Odoo when WC registers a refund.
	 *
	 * @param int $order_id  WooCommerce order ID.
	 * @param int $refund_id WooCommerce refund post ID.
	 */
	public function auto_refund_order( int $order_id, int $refund_id ): void {
		try {
			$order_manager = new Woo2Odoo_Order_Manager();
			$order_manager->refund_sync( $order_id, $refund_id );
		} catch ( \Throwable $e ) {
			error_log( 'woo2odoo auto_refund_order failed for order ' . $order_id . ': ' . $e->getMessage() );
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

		// Schedule stock sync cron if enabled
		$export_settings = get_option( 'Woo2Odoo-plugin-export', array() );
		if ( ! empty( $export_settings['odoo_import_update_stocks'] ) && 'true' === $export_settings['odoo_import_update_stocks'] ) {
			if ( ! wp_next_scheduled( 'odoo_process_import_update_stocks' ) ) {
				$frequency = $export_settings['odoo_import_stocks_frequency'] ?? 'daily';
				wp_schedule_event( time(), $frequency, 'odoo_process_import_update_stocks' );
			}
		}
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

	/**
	 * Deactivation. Runs on plugin deactivation.
	 * @access  public
	 * @since   1.0.0
	 */
	public function deactivate(): void {
		wp_clear_scheduled_hook( 'odoo_process_import_update_stocks' );
	}

	/**
	 * Execute the stock synchronization from Odoo to WooCommerce.
	 * @access  public
	 * @since   1.0.0
	 */
	public function run_stock_sync(): void {
		try {
			$client         = new Woo2Odoo_Client();
			$stock_manager  = new Woo2Odoo_Stock_Manager( $client );
			$stats          = $stock_manager->sync_all();
			// Logging is already handled internally by sync_all()
		} catch ( \Throwable $e ) {
			error_log( 'woo2odoo run_stock_sync failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Reschedule the stock sync cron based on updated settings.
	 * Called when the 'Woo2Odoo-plugin-export' option is updated.
	 *
	 * @access  public
	 * @since   1.0.0
	 * @param   mixed $old_value The old option value.
	 * @param   mixed $new_value The new option value.
	 */
	public function reschedule_stock_sync( $old_value, $new_value ): void {
		wp_clear_scheduled_hook( 'odoo_process_import_update_stocks' );

		if ( isset( $new_value['odoo_import_update_stocks'] ) && 'true' === $new_value['odoo_import_update_stocks'] ) {
			$frequency = $new_value['odoo_import_stocks_frequency'] ?? 'daily';
			wp_schedule_event( time(), $frequency, 'odoo_process_import_update_stocks' );
		}
	}

	public function fix_options_autoload(): void {
		global $wpdb;
		// Plugin options don't need autoloading — removing them from alloptions
		// prevents the Redis alloptions blob from serving stale values after saves.
		// Runs on admin_init (idempotent: only writes if autoload is still 'on').
		foreach ( array( 'Woo2Odoo-plugin-connection', 'Woo2Odoo-plugin-export' ) as $option ) {
			$current = $wpdb->get_var(
				$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option )
			);
			if ( 'on' === $current ) {
				$wpdb->update( $wpdb->options, array( 'autoload' => 'off' ), array( 'option_name' => $option ) );
				wp_cache_delete( 'alloptions', 'options' );
			}
		}
	}

	public function maybe_schedule_stock_sync(): void {
		$export_settings = get_option( 'Woo2Odoo-plugin-export', array() );
		$enabled         = ! empty( $export_settings['odoo_import_update_stocks'] ) && 'true' === $export_settings['odoo_import_update_stocks'];

		if ( $enabled && ! wp_next_scheduled( 'odoo_process_import_update_stocks' ) ) {
			$frequency = $export_settings['odoo_import_stocks_frequency'] ?? 'daily';
			wp_schedule_event( time(), $frequency, 'odoo_process_import_update_stocks' );
		} elseif ( ! $enabled ) {
			wp_clear_scheduled_hook( 'odoo_process_import_update_stocks' );
		}
	}
}
