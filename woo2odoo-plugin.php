<?php
/**
 * Plugin Name: Woo2Odoo - WooCommerce to Odoo Integration
 * Plugin URI: http://github.com/slemos/woo2odoo
 * Description: WooCommerce to Odoo Integration plugin
 * Version: 1.0.0
 * Author: Sebastian Lemos
 * Author URI: http://github.com/slemos
 * Requires at least: 5.6.0
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

/**
 * Returns the main instance of Woo2odoo_Plugin to prevent the need to use globals.
 *
 * @since  1.0.0
 * @return object Woo2odoo_Plugin
 */
function woo2odoo_plugin() {
	return Woo2Odoo_Plugin::instance();
}
add_action( 'plugins_loaded', 'woo2odoo_plugin' );
