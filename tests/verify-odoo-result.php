<?php
/**
 * Verifica en arm-testing Odoo si existe una sale.order e invoice
 * para un WC order ID dado. Guarda resultado en JSON.
 *
 * Uso: php -d memory_limit=256M tests/verify-odoo-result.php <WC_ORDER_ID> <plugin_name>
 * Ejemplo: php -d memory_limit=256M tests/verify-odoo-result.php 17678 woo2odoo
 *
 * Corre dentro de infra-php-1 con PHPUNIT_TESTING=1 para no cargar plugins.
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

use winternet\odoo\JsonRpcClient;

$wc_order_id = $argv[1] ?? null;
$plugin_name = $argv[2] ?? 'unknown';
$out_label   = $argv[3] ?? $plugin_name;  // usado para el nombre del archivo de salida

if (!$wc_order_id) {
    echo "Uso: php verify-odoo-result.php <WC_ORDER_ID> <plugin_name>\n";
    exit(1);
}

$client = new JsonRpcClient(
    $_ENV['ODOO_URL'],
    $_ENV['ODOO_DBNAME'],
    $_ENV['ODOO_USER'],
    $_ENV['ODOO_PASSWORD']
);

echo "Buscando en Odoo para WC Order #{$wc_order_id} (plugin: {$plugin_name})...\n";

// Buscar sale.order con origin = WC order ID
$orders = $client->searchRead('sale.order', [
    'where'  => [['origin', 'like', (string)$wc_order_id]],
    'fields' => ['id', 'name', 'origin', 'amount_total', 'amount_untaxed',
                 'state', 'invoice_status', 'partner_id', 'date_order'],
    'limit'  => 5,
]);

$result = [
    'plugin'      => $plugin_name,
    'wc_order_id' => (int)$wc_order_id,
    'timestamp'   => date('c'),
    'sale_order'  => null,
    'invoice'     => null,
    'errors'      => [],
];

if (empty($orders)) {
    echo "ERROR: No se encontró sale.order con origin like '{$wc_order_id}'\n";
    $result['errors'][] = "No sale.order found with origin like '{$wc_order_id}'";
} else {
    $order = $orders[0];
    echo "✓ sale.order encontrada:\n";
    echo "  ID:             {$order->id}\n";
    echo "  Nombre:         {$order->name}\n";
    echo "  Origin:         {$order->origin}\n";
    echo "  Total:          {$order->amount_total}\n";
    echo "  Sin impuesto:   {$order->amount_untaxed}\n";
    echo "  Estado:         {$order->state}\n";
    echo "  Fact. status:   {$order->invoice_status}\n";
    echo "  Partner:        {$order->partner_id[1]}\n";

    $result['sale_order'] = (array)$order;

    // Buscar account.move (invoice) vinculada
    $invoices = $client->searchRead('account.move', [
        'where'  => [
            ['invoice_origin', 'like', (string)$wc_order_id],
            ['move_type', 'in', ['out_invoice', 'out_refund']],
        ],
        'fields' => ['id', 'name', 'invoice_origin', 'amount_total',
                     'amount_untaxed', 'state', 'payment_state',
                     'l10n_latam_document_type_id', 'l10n_latam_document_number'],
        'limit'  => 5,
    ]);

    if (empty($invoices)) {
        // Try linking via sale order name
        $invoices = $client->searchRead('account.move', [
            'where'  => [
                ['invoice_origin', 'like', $order->name],
                ['move_type', 'in', ['out_invoice', 'out_refund']],
            ],
            'fields' => ['id', 'name', 'invoice_origin', 'amount_total',
                         'amount_untaxed', 'state', 'payment_state',
                         'l10n_latam_document_type_id', 'l10n_latam_document_number'],
            'limit'  => 5,
        ]);
    }

    if (empty($invoices)) {
        echo "⚠ No se encontró invoice vinculada (puede ser normal si aún no se facturó)\n";
        $result['errors'][] = "No invoice found linked to sale order {$order->name}";
    } else {
        $inv = $invoices[0];
        echo "✓ Invoice encontrada:\n";
        echo "  ID:             {$inv->id}\n";
        echo "  Nombre:         {$inv->name}\n";
        echo "  Total:          {$inv->amount_total}\n";
        echo "  Estado:         {$inv->state}\n";
        echo "  Pago:           {$inv->payment_state}\n";
        $result['invoice'] = (array)$inv;
    }
}

// Guardar resultado
$out_dir = __DIR__ . '/results';
if (!is_dir($out_dir)) mkdir($out_dir, 0755, true);
$out_file = "{$out_dir}/{$out_label}-result.json";
file_put_contents($out_file, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nResultado guardado en: {$out_file}\n";

// Exit 1 si hay errores críticos
exit(empty($result['errors']) ? 0 : 1);
