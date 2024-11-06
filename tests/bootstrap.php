<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Starter_Plugin
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

$_tests_dir = getenv( 'WP_TESTS_DIR' );

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

require 'vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	define( 'WP_ADMIN', TRUE );
//define( 'WP_NETWORK_ADMIN', TRUE ); // Need for Multisite
define( 'WP_USER_ADMIN', TRUE );

require_once('/var/www/html/wp-load.php');
require_once( '/var/www/html/wp-admin/includes/admin.php' );
require_once( '/var/www/html/wp-admin/includes/plugin.php' );

activate_plugin( '/var/www/html/wp-content/plugins/woocommerce/woocommerce.php' );
	//require_once( '/var/www/html/wp-content/plugins/woocommerce/woocommerce.php' );
	require dirname( dirname( __FILE__ ) ) . '/woo2odoo.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";
