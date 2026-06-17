<?php
/**
 * Fuerza la sincronización de una orden WC con Odoo.
 * Uso: PHPUNIT_TESTING=1 php tests/sync-order.php <order_id>
 *
 * Carga woo2odoo manualmente para evitar conflictos de plugins (Kadence, etc.)
 */
if ( empty( $argv[1] ) ) {
    echo "Uso: php tests/sync-order.php <order_id>\n";
    exit(1);
}
$order_id = (int) $argv[1];

require_once dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

define( 'WP_ADMIN', true );
require_once '/var/www/html/wp-load.php';

// Cargar clases del plugin manualmente (WordPress no lo hizo bajo PHPUNIT_TESTING)
$plugin_dir = dirname(__DIR__);
require_once $plugin_dir . '/classes/Woo2Odoo_Client.php';
require_once $plugin_dir . '/classes/Woo2Odoo_Order_Manager.php';

$order = wc_get_order( $order_id );
if ( ! $order ) {
    echo "ERROR: Orden #$order_id no encontrada.\n";
    exit(1);
}

echo "Orden #$order_id\n";
echo "  Status     : " . $order->get_status() . "\n";
echo "  Total      : " . $order->get_total() . " CLP\n";
echo "  Método pago: " . $order->get_payment_method() . "\n";
echo "  TBK status : " . $order->get_meta('transactionStatus') . "\n";
echo "  TBK code   : " . $order->get_meta('authorizationCode') . "\n";
echo "  Odoo SO ID : " . ( $order->get_meta('_odoo_sale_order_id') ?: '(no sincronizado)' ) . "\n\n";

echo "Iniciando sincronización...\n";
$manager = new Woo2Odoo_Order_Manager();
$result  = $manager->order_sync( $order );

echo "Resultado: ";
var_dump( $result );

$order = wc_get_order( $order_id );
echo "\nOdoo SO ID después del sync: " . ( $order->get_meta('_odoo_sale_order_id') ?: '(fallido)' ) . "\n";
