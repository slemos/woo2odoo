<?php
/**
 * One-time setup script: creates required test data in arm-testing Odoo.
 * Run inside infra-php-1: php -d memory_limit=256M tests/setup-odoo-testdata.php
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

use winternet\odoo\JsonRpcClient;

$client = new JsonRpcClient(
    $_ENV['ODOO_URL'],
    $_ENV['ODOO_DBNAME'],
    $_ENV['ODOO_USER'],
    $_ENV['ODOO_PASSWORD']
);

echo "Authenticated as UID: {$client->uid}\n";

// Find Chilean IVA 19% tax ID
$tax = $client->searchRead('account.tax', [
    'where' => [
        ['country_code', '=', 'CL'],
        ['type_tax_use', '=', 'sale'],
        ['amount', '=', 19],
    ],
    'fields' => ['id', 'name'],
    'limit'  => 1,
]);
$tax_id = $tax[0]->id ?? null;
echo "IVA 19% tax id: {$tax_id}\n";

// Find or create product category
$category = $client->searchRead('product.category', [
    'where' => [['name', '=', 'All']],
    'fields' => ['id'],
    'limit'  => 1,
]);
$cat_id = $category[0]->id ?? 1;

$skus = ['GELCOL-100' => 'Gel Colágeno 100ml', 'GELCOL-001' => 'Gel Colágeno 1ml'];
$created = [];
foreach ($skus as $sku => $name) {
    $existing = $client->searchRead('product.product', [
        'where'  => [['default_code', '=', $sku]],
        'fields' => ['id', 'name', 'default_code'],
        'limit'  => 1,
    ]);
    if (!empty($existing)) {
        echo "Product {$sku} already exists (id={$existing[0]->id})\n";
        $created[$sku] = $existing[0]->id;
        continue;
    }
    $product_id = $client->create('product.product', [
        'name'         => $name,
        'default_code' => $sku,
        'type'         => 'consu',
        'categ_id'     => $cat_id,
        'list_price'   => 1000,
        'taxes_id'     => $tax_id ? [[4, $tax_id]] : [],
    ]);
    echo "Created product {$sku} => id={$product_id}\n";
    $created[$sku] = $product_id;
}

// Find an existing sale.order for testAddOrderLineItems (needs a real order id)
$orders = $client->searchRead('sale.order', [
    'where'  => [['state', 'in', ['draft', 'sent']]],
    'fields' => ['id', 'name', 'state'],
    'limit'  => 1,
]);
if (!empty($orders)) {
    echo "Use this order_id for testAddOrderLineItems: {$orders[0]->id} ({$orders[0]->name})\n";
} else {
    echo "No draft orders found — testAddOrderLineItems will need its own order\n";
}

echo "Setup complete.\n";
