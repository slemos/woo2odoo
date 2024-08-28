<?php
/**
 * Plugin Name: Woo2odoo
 * Version: 0.1.0
 * Author: The WordPress Contributors
 * Author URI: https://woo.com
 * Text Domain: woo2odoo
 * Domain Path: /languages
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package extension
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'MAIN_PLUGIN_FILE' ) ) {
	define( 'MAIN_PLUGIN_FILE', __FILE__ );
}

require_once plugin_dir_path( __FILE__ ) . '/vendor/autoload.php';

require_once plugin_dir_path( __FILE__ ) . '/includes/admin/setup.php';

use Woo2odoo\Admin\Setup;

// phpcs:disable WordPress.Files.FileName

/**
 * WooCommerce fallback notice.
 *
 * @since 0.1.0
 */
function woo2odoo_missing_wc_notice() {
	/* translators: %s WC download URL link. */
	echo '<div class="error"><p><strong>' . sprintf( esc_html__( 'Woo2odoo requires WooCommerce to be installed and active. You can download %s here.', 'woo2odoo' ), '<a href="https://woo.com/" target="_blank">WooCommerce</a>' ) . '</strong></p></div>';
}

register_activation_hook( __FILE__, 'woo2odoo_activate' );

/**
 * Activation hook.
 *
 * @since 0.1.0
 */
function woo2odoo_activate() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'woo2odoo_missing_wc_notice' );
		return;
	}
}

if ( ! class_exists( 'woo2odoo' ) ) :
	/**
	 * The woo2odoo class.
	 */
	class woo2odoo {
		/**
		 * This class instance.
		 *
		 * @var \woo2odoo single instance of this class.
		 */
		private static $instance;

		/**
		 * Constructor.
		 */
		public function __construct() {
			if ( is_admin() ) {
				new Setup();
			}
		}

		/**
		 * Cloning is forbidden.
		 */
		public function __clone() {
			wc_doing_it_wrong( __FUNCTION__, __( 'Cloning is forbidden.', 'woo2odoo' ), $this->version );
		}

		/**
		 * Unserializing instances of this class is forbidden.
		 */
		public function __wakeup() {
			wc_doing_it_wrong( __FUNCTION__, __( 'Unserializing instances of this class is forbidden.', 'woo2odoo' ), $this->version );
		}

		/**
		 * Gets the main instance.
		 *
		 * Ensures only one instance can be loaded.
		 *
		 * @return \woo2odoo
		 */
		public static function instance() {

			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}
	}
endif;

add_action( 'plugins_loaded', 'woo2odoo_init', 10 );

/**
 * Initialize the plugin.
 *
 * @since 0.1.0
 */
function woo2odoo_init() {
	load_plugin_textdomain( 'woo2odoo', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'woo2odoo_missing_wc_notice' );
		return;
	}

	woo2odoo::instance();

}
