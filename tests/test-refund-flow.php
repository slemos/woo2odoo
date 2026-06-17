<?php
/**
 * Test Refund Flow — Fase 8
 *
 * Flujo:
 *   1. Crea orden WC con BACS → processing (dispara auto_sync → SO + invoice draft en Odoo)
 *   2. Confirma la invoice en Odoo (action_post → boleta electrónica tipo 39)
 *   3. Crea refund TOTAL → woo2odoo crea nota de crédito (tipo 61) en Odoo
 *   4. Crea segunda orden + refund PARCIAL (solo GELCOL-100) → segunda nota de crédito
 *
 * Uso:
 *   docker exec -e PHPUNIT_TESTING=0 -w /var/www/html/wp-content/plugins/woo2odoo \
 *     infra-php-1 php -d memory_limit=512M tests/test-refund-flow.php [--customer-id N]
 *
 * Output final (para scripting):
 *   FULL_ORDER_ID=XXXXX
 *   FULL_REFUND_ID=XXXXX
 *   PARTIAL_ORDER_ID=XXXXX
 *   PARTIAL_REFUND_ID=XXXXX
 */

// Stub Block Checkout (no disponible en CLI)
if (!function_exists('woocommerce_register_additional_checkout_field')) {
    function woocommerce_register_additional_checkout_field() {}
}

// Parse CLI args
$customer_id = 0;
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--customer-id' && isset($argv[$i + 1])) {
        $customer_id = (int) $argv[++$i];
    }
}

// Cargar WordPress
define('WP_ADMIN', true);
require_once('/var/www/html/wp-load.php');

if (!class_exists('WC_Order')) {
    fwrite(STDERR, "Error: WooCommerce no está activo.\n");
    exit(1);
}

// ─── Helpers ────────────────────────────────────────────────────────────────

function get_odoo_client(): \Woo2Odoo\Woo2Odoo_Client {
    $client = new \Woo2Odoo\Woo2Odoo_Client();
    if (!$client->authenticate()) {
        fwrite(STDERR, "Error: no se pudo autenticar con Odoo.\n");
        exit(1);
    }
    return $client;
}

function create_test_order(int $customer_id, string $label): WC_Order {
    global $billing, $rut, $product_1, $product_2;

    if ($customer_id > 0) {
        wp_set_current_user($customer_id);
    }

    $order = wc_create_order(['customer_id' => $customer_id]);
    if (is_wp_error($order)) {
        fwrite(STDERR, "[$label] Error al crear la orden: " . $order->get_error_message() . "\n");
        exit(1);
    }

    $order->add_product($product_1, 1);
    $order->add_product($product_2, 1);

    foreach ($billing as $field => $value) {
        $b = "set_billing_{$field}";
        if (method_exists($order, $b)) $order->$b($value);
        $s = "set_shipping_{$field}";
        if ($field !== 'email' && method_exists($order, $s)) $order->$s($value);
    }
    $order->update_meta_data('billing_rut', $rut);
    $order->set_payment_method('bacs');
    $order->set_payment_method_title('Transferencia Bancaria Directa');
    $order->calculate_totals();
    $order->save();

    echo "[$label] Orden creada ID={$order->get_id()}, Total={$order->get_total()} CLP\n";
    return $order;
}

function confirm_odoo_invoice(int $invoice_id, string $label): bool {
    $client = get_odoo_client();
    try {
        $client->execute('account.move', 'action_post', [[$invoice_id]]);
        echo "[$label] Invoice Odoo #$invoice_id confirmada (posted)\n";
        return true;
    } catch (\Throwable $e) {
        fwrite(STDERR, "[$label] Error al confirmar invoice #$invoice_id: " . $e->getMessage() . "\n");
        return false;
    }
}

function wait_for_invoice_meta(int $order_id, string $label, int $retries = 5): int {
    for ($i = 0; $i < $retries; $i++) {
        // Forzar lectura desde DB (evitar caché de objeto)
        clean_post_cache($order_id);
        $invoice_id = (int) get_post_meta($order_id, '_woo2odoo_invoice_id', true);
        if ($invoice_id > 0) {
            echo "[$label] _woo2odoo_invoice_id=$invoice_id\n";
            return $invoice_id;
        }
        echo "[$label] Esperando _woo2odoo_invoice_id... (intento " . ($i + 1) . ")\n";
        sleep(2);
    }
    fwrite(STDERR, "[$label] ERROR: _woo2odoo_invoice_id no se guardó para orden $order_id\n");
    return 0;
}

// ─── Datos fijos ─────────────────────────────────────────────────────────────

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
$rut = '12345678-9';

$product_id_1 = wc_get_product_id_by_sku('GELCOL-100');
$product_id_2 = wc_get_product_id_by_sku('GELCOL-001');

if (!$product_id_1 || !$product_id_2) {
    fwrite(STDERR, "Error: GELCOL-100 o GELCOL-001 no encontrados en WC.\n");
    exit(1);
}

$product_1 = wc_get_product($product_id_1);
$product_2 = wc_get_product($product_id_2);

echo "Productos: GELCOL-100 (WC#$product_id_1), GELCOL-001 (WC#$product_id_2)\n";
echo "Cliente ID: $customer_id\n\n";

// ═══════════════════════════════════════════════════════════════════════════════
// CASO 1: REFUND TOTAL
// ═══════════════════════════════════════════════════════════════════════════════

echo "══ CASO 1: REFUND TOTAL ══════════════════════════════════════\n";

$order_full = create_test_order($customer_id, 'full');
$order_full_id = $order_full->get_id();

// Cambiar a processing → dispara auto_sync_order → crea SO + invoice en Odoo
echo "[full] Cambiando estado → processing...\n";
$order_full->update_status('processing', 'Test refund total');

// Leer el invoice_id guardado por create_invoice_for_so()
$invoice_full_id = wait_for_invoice_meta($order_full_id, 'full');
if (!$invoice_full_id) {
    fwrite(STDERR, "[full] ABORTANDO: no se encontró invoice en Odoo para orden $order_full_id\n");
    exit(1);
}

// Confirmar factura en Odoo (boleta tipo 39 ya asignada al crear, action_post la valida)
echo "[full] Confirmando invoice en Odoo...\n";
confirm_odoo_invoice($invoice_full_id, 'full');

// Crear refund TOTAL
echo "[full] Creando refund total...\n";
$refund_items_full = [];
foreach ($order_full->get_items() as $item_id => $item) {
    $refund_items_full[$item_id] = [
        'qty'          => $item->get_quantity(),
        'refund_total' => $item->get_total(),
    ];
}

$refund_full = wc_create_refund([
    'order_id'       => $order_full_id,
    'amount'         => $order_full->get_total(),
    'reason'         => 'Test refund total — Fase 8',
    'line_items'     => $refund_items_full,
    'refund_payment' => false,
    'restock_items'  => false,
]);

if (is_wp_error($refund_full)) {
    fwrite(STDERR, "[full] Error al crear refund: " . $refund_full->get_error_message() . "\n");
    exit(1);
}

$refund_full_id = $refund_full->get_id();
echo "[full] WC Refund creado ID=$refund_full_id\n";

// Verificar que se guardó el return invoice en Odoo
sleep(1);
clean_post_cache($refund_full_id);
$return_inv_full = (int) get_post_meta($refund_full_id, '_woo2odoo_return_invoice_id', true);
if ($return_inv_full) {
    echo "[full] ✓ Nota de crédito Odoo ID=$return_inv_full guardada en _woo2odoo_return_invoice_id\n";
} else {
    fwrite(STDERR, "[full] ✗ _woo2odoo_return_invoice_id NO se guardó para refund $refund_full_id\n");
}

echo "[full] Orden WC#$order_full_id → Refund WC#$refund_full_id → Nota crédito Odoo#$return_inv_full\n\n";

// ═══════════════════════════════════════════════════════════════════════════════
// CASO 2: REFUND PARCIAL (solo GELCOL-100)
// ═══════════════════════════════════════════════════════════════════════════════

echo "══ CASO 2: REFUND PARCIAL (solo GELCOL-100) ══════════════════\n";

$order_partial = create_test_order($customer_id, 'partial');
$order_partial_id = $order_partial->get_id();

echo "[partial] Cambiando estado → processing...\n";
$order_partial->update_status('processing', 'Test refund parcial');

$invoice_partial_id = wait_for_invoice_meta($order_partial_id, 'partial');
if (!$invoice_partial_id) {
    fwrite(STDERR, "[partial] ABORTANDO: no se encontró invoice en Odoo para orden $order_partial_id\n");
    exit(1);
}

echo "[partial] Confirmando invoice en Odoo...\n";
confirm_odoo_invoice($invoice_partial_id, 'partial');

// Crear refund PARCIAL: solo reembolsamos GELCOL-100
echo "[partial] Creando refund parcial (solo GELCOL-100)...\n";
$refund_items_partial = [];
$partial_amount = 0;

foreach ($order_partial->get_items() as $item_id => $item) {
    $product = $item->get_product();
    if ($product && $product->get_sku() === 'GELCOL-100') {
        $refund_items_partial[$item_id] = [
            'qty'          => $item->get_quantity(),
            'refund_total' => $item->get_total(),
        ];
        $partial_amount += $item->get_total();
        echo "[partial] Reembolsando GELCOL-100 x{$item->get_quantity()} = {$item->get_total()} CLP\n";
    }
}

if (empty($refund_items_partial)) {
    fwrite(STDERR, "[partial] ERROR: no se encontró GELCOL-100 en la orden $order_partial_id\n");
    exit(1);
}

$refund_partial = wc_create_refund([
    'order_id'       => $order_partial_id,
    'amount'         => $partial_amount,
    'reason'         => 'Test refund parcial (GELCOL-100) — Fase 8',
    'line_items'     => $refund_items_partial,
    'refund_payment' => false,
    'restock_items'  => false,
]);

if (is_wp_error($refund_partial)) {
    fwrite(STDERR, "[partial] Error al crear refund: " . $refund_partial->get_error_message() . "\n");
    exit(1);
}

$refund_partial_id = $refund_partial->get_id();
echo "[partial] WC Refund creado ID=$refund_partial_id, Monto=$partial_amount CLP\n";

sleep(1);
clean_post_cache($refund_partial_id);
$return_inv_partial = (int) get_post_meta($refund_partial_id, '_woo2odoo_return_invoice_id', true);
if ($return_inv_partial) {
    echo "[partial] ✓ Nota de crédito Odoo ID=$return_inv_partial guardada en _woo2odoo_return_invoice_id\n";
} else {
    fwrite(STDERR, "[partial] ✗ _woo2odoo_return_invoice_id NO se guardó para refund $refund_partial_id\n");
}

echo "[partial] Orden WC#$order_partial_id → Refund WC#$refund_partial_id → Nota crédito Odoo#$return_inv_partial\n\n";

// ═══════════════════════════════════════════════════════════════════════════════
// RESUMEN
// ═══════════════════════════════════════════════════════════════════════════════

echo "══ RESUMEN ════════════════════════════════════════════════════\n";
echo "Refund total:   WC#$order_full_id → Refund#$refund_full_id → NC Odoo#$return_inv_full\n";
echo "Refund parcial: WC#$order_partial_id → Refund#$refund_partial_id → NC Odoo#$return_inv_partial\n";

// OUTPUT PARA SCRIPTING
echo "\nFULL_ORDER_ID={$order_full_id}\n";
echo "FULL_REFUND_ID={$refund_full_id}\n";
echo "PARTIAL_ORDER_ID={$order_partial_id}\n";
echo "PARTIAL_REFUND_ID={$refund_partial_id}\n";
