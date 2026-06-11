<?php
/**
 * Crea una orden de prueba simulando pago Transbank.
 * Uso: php tests/create-tbk-test-order.php [--label LABEL]
 */
if (!function_exists('woocommerce_register_additional_checkout_field')) {
    function woocommerce_register_additional_checkout_field() {}
}

$label = 'tbk-test';
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--label' && isset($argv[$i + 1])) {
        $label = $argv[++$i];
    }
}

define('WP_ADMIN', true);
require_once('/var/www/html/wp-load.php');

$billing = [
    'first_name' => 'Sebastian',
    'last_name'  => 'Lemos',
    'email'      => 'slemos.satue@gmail.com',
    'address_1'  => 'La Capitanía 81',
    'city'       => 'Santiago',
    'state'      => 'RM',
    'country'    => 'CL',
    'phone'      => '+56912345678',
];

$product_id_1 = wc_get_product_id_by_sku('GELCOL-100');
$product_id_2 = wc_get_product_id_by_sku('GELCOL-001');
echo "[$label] GELCOL-100 ID=$product_id_1, GELCOL-001 ID=$product_id_2\n";

$order = wc_create_order(['customer_id' => 0]);
$order->add_product(wc_get_product($product_id_1), 1);
$order->add_product(wc_get_product($product_id_2), 1);

foreach ($billing as $field => $value) {
    $bill_setter = "set_billing_{$field}";
    if (method_exists($order, $bill_setter)) $order->$bill_setter($value);
    $ship_setter = "set_shipping_{$field}";
    if ($field !== 'email' && method_exists($order, $ship_setter)) $order->$ship_setter($value);
}
$order->update_meta_data('billing_rut', '12345678-9');

// Transbank payment method
$order->set_payment_method('transbank_webpay_plus_rest');
$order->set_payment_method_title('Webpay Plus');

$order->calculate_totals();
$order->save();
$order_id = $order->get_id();

// Inject Transbank metas (simulated) via WC order API (HPOS-compatible)
$auth_code = 'AUTO' . rand(10000, 99999);
$tbk_date  = date('d-m-Y H:i:s P');
$order->update_meta_data('transactionStatus',  'Autorizada');
$order->update_meta_data('amount',              (string) $order->get_total());
$order->update_meta_data('authorizationCode',   $auth_code);
$order->update_meta_data('transactionDate',     $tbk_date);
$order->save();

echo "[$label] Orden guardada ID=$order_id, Total=" . $order->get_total() . " CLP\n";
echo "[$label] TBK metas: status=Autorizada, amount=" . $order->get_total() . ", code=$auth_code\n";

// Trigger sync
$order->update_status('processing', "Test TBK simulado: $label");
echo "[$label] Estado → processing\n";

echo "\nORDER_ID=$order_id\n";
