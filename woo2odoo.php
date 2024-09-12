<?php
/**
 * Plugin Name: Woo2Odoo
 * Plugin URI: http://github.com/slemos/woo2odoo
 * Description: WooCommerce to Odoo Integration plugin
 * Version: 1.0.0
 * Author: Sebastian Lemos
 * Author URI: http://github.com/slemos
 * Requires at least: 5.6.0
 * Requires Plugins:  woocommerce
 * Tested up to: 5.6.0
 *
 * Text Domain: woo2odoo-plugin
 * Domain Path: /languages/
 *
 * @package Woo2Odoo
 * @category Core
 * @author slemos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

require_once 'classes/class-woo2odoo-plugin.php';
require_once 'vendor/autoload.php';

/**
 * WooCommerce fallback notice.
 *
 * @since 0.1.0
 */
function woo2odoo_missing_wc_notice() {
	/* translators: %s WC download URL link. */
	echo '<div class="error"><p><strong>' . sprintf( esc_html__( 'Woo2odoo requires WooCommerce to be installed and active. You can download %s here.', 'woo2odoo-plugin' ), '<a href="https://woo.com/" target="_blank">WooCommerce</a>' ) . '</strong></p></div>';
}

/**
 * Returns the main instance of Woo2odoo_Plugin to prevent the need to use globals.
 *
 * @since  1.0.0
 * @return object Woo2odoo_Plugin
 */
function woo2odoo() {
	load_plugin_textdomain( 'woo2odoo', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );
	
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'woo2odoo_missing_wc_notice' );
		return;
	}

	return Woo2Odoo_Plugin::instance();
}
add_action( 'plugins_loaded', 'woo2odoo' );
