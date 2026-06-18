<?php
/**
 * Manual stock synchronization test script.
 *
 * Synchronizes product stock from Odoo to WooCommerce.
 * Uso:
 *   - Sincronizar todos los productos:
 *     PHPUNIT_TESTING=1 php tests/test-stock-sync.php
 *   - Sincronizar un producto específico por SKU:
 *     PHPUNIT_TESTING=1 php tests/test-stock-sync.php --sku=GELCOL-100
 *
 * Carga woo2odoo manualmente para evitar conflictos de plugins.
 */

// Parse arguments
$sku = null;
foreach ( $argv as $arg ) {
	if ( strpos( $arg, '--sku=' ) === 0 ) {
		$sku = substr( $arg, 6 );
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable( dirname( __DIR__ ) );
$dotenv->load();

define( 'WP_ADMIN', true );
require_once '/var/www/html/wp-load.php';

// Cargar clases del plugin manualmente (WordPress no lo hizo bajo PHPUNIT_TESTING)
$plugin_dir = dirname( __DIR__ );
require_once $plugin_dir . '/classes/Woo2Odoo_Client.php';
require_once $plugin_dir . '/classes/Woo2Odoo_Stock_Manager.php';

use Woo2Odoo\Woo2Odoo_Client;
use Woo2Odoo\Woo2Odoo_Stock_Manager;

// Instanciar cliente y manager
$client        = new Woo2Odoo_Client();
$stock_manager = new Woo2Odoo_Stock_Manager( $client );

echo "\n";

if ( ! empty( $sku ) ) {
	// Sincronizar un producto específico por SKU
	echo "[stock-sync] Syncing product by SKU: $sku\n";

	// Obtener cantidad de Odoo
	echo "[stock-sync] Fetching Odoo stock for SKU: $sku\n";
	$odoo_qty = $stock_manager->fetch_odoo_qty( $sku );

	if ( null === $odoo_qty ) {
		echo "[stock-sync] ERROR: SKU '$sku' not found in Odoo or connection failed\n\n";
		exit( 1 );
	}

	echo "[stock-sync] Odoo free_qty for $sku: " . (float) $odoo_qty . "\n";

	// Buscar producto en WC por SKU
	$products = wc_get_products(
		array(
			'sku'    => $sku,
			'limit'  => 1,
			'return' => 'objects',
		)
	);

	if ( empty( $products ) ) {
		echo "[stock-sync] ERROR: SKU '$sku' not found in WooCommerce\n\n";
		exit( 1 );
	}

	$product = $products[0];
	echo "[stock-sync] WC product found: ID=" . $product->get_id() . ", current stock=" . (int) $product->get_stock_quantity() . "\n";

	// Sincronizar el producto
	echo "[stock-sync] Syncing product...\n";
	$sync_result = $stock_manager->sync_product( $product );

	if ( $sync_result ) {
		$product = wc_get_product( $product->get_id() ); // reload
		echo "[stock-sync] Sync result: OK (new stock: " . (int) $product->get_stock_quantity() . ")\n\n";
		exit( 0 );
	} else {
		echo "[stock-sync] Sync result: FAILED\n\n";
		exit( 1 );
	}
} else {
	// Sincronizar todos los productos
	echo "[stock-sync] Starting sync for all products...\n";
	$stats = $stock_manager->sync_all();

	echo "[stock-sync] Done: updated=" . (int) $stats['updated'] . ", ";
	echo "not_found=" . (int) $stats['not_found'] . ", ";
	echo "errors=" . (int) $stats['errors'] . "\n\n";

	exit( 0 );
}
