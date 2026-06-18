<?php
/**
 * PHPUnit bootstrap for running tests inside infra-php-1 container.
 *
 * Does NOT use wordpress-tests-lib. Loads WordPress directly from the
 * real installation at /var/www/html, which already has WooCommerce active.
 * The Woo2Odoo\ namespace is available via vendor/autoload.php (PSR-4).
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

// Must be defined before wp-load.php
if (!defined('WP_ADMIN'))      define('WP_ADMIN', true);
if (!defined('WP_USER_ADMIN')) define('WP_USER_ADMIN', true);

// Boot WordPress — loads all active plugins (WooCommerce, etc.)
require_once '/var/www/html/wp-load.php';
require_once '/var/www/html/wp-admin/includes/admin.php';
require_once '/var/www/html/wp-admin/includes/plugin.php';
