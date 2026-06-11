<?php
/**
 * Create Test Order — Phase 6 Comparison Script
 *
 * Uso:
 *   php tests/create-test-order.php [--coupon CODE] [--customer-id N] [--label LABEL]
 *
 * Opciones:
 *   --coupon CODE      Aplicar cupón WC (ej: PINK10). Default: sin cupón.
 *   --customer-id N    ID de usuario WP para orden autenticada. 0 = anónimo. Default: 0.
 *   --label LABEL      Etiqueta para output (usada por scripts de automatización).
 *
 * Output: imprime "ORDER_ID=XXXXX" en la última línea para fácil parsing.
 *
 * Correr dentro del contenedor:
 *   docker exec -e PHPUNIT_TESTING=0 -w /var/www/html/wp-content/plugins/woo2odoo \
 *     infra-php-1 php -d memory_limit=512M tests/create-test-order.php [opciones]
 */

// Stub Block Checkout (no disponible en CLI)
if (!function_exists('woocommerce_register_additional_checkout_field')) {
    function woocommerce_register_additional_checkout_field() {}
}

// Parse CLI args
$options = [];
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--coupon' && isset($argv[$i + 1])) {
        $options['coupon'] = $argv[++$i];
    } elseif ($argv[$i] === '--customer-id' && isset($argv[$i + 1])) {
        $options['customer_id'] = (int)$argv[++$i];
    } elseif ($argv[$i] === '--label' && isset($argv[$i + 1])) {
        $options['label'] = $argv[++$i];
    }
}

$coupon_code  = $options['coupon']      ?? null;
$customer_id  = $options['customer_id'] ?? 0;
$label        = $options['label']       ?? 'test';

// Cargar WordPress
define('WP_ADMIN', true);
require_once('/var/www/html/wp-load.php');

if (!class_exists('WC_Order')) {
    fwrite(STDERR, "Error: WooCommerce no está activo.\n");
    exit(1);
}

// ===== DATOS FIJOS DE PRUEBA =====
// Los mismos para todas las variantes (cliente, productos, dirección)
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

// ===== PASO 1: PRODUCTOS =====
$product_id_1 = wc_get_product_id_by_sku('GELCOL-100');
$product_id_2 = wc_get_product_id_by_sku('GELCOL-001');

if (!$product_id_1 || !$product_id_2) {
    fwrite(STDERR, "Error: GELCOL-100 (ID=$product_id_1) o GELCOL-001 (ID=$product_id_2) no encontrados.\n");
    exit(1);
}

echo "[$label] GELCOL-100 ID=$product_id_1, GELCOL-001 ID=$product_id_2\n";

// ===== PASO 2: CREAR ORDEN =====
// Si cliente autenticado, set WP current user
if ($customer_id > 0) {
    wp_set_current_user($customer_id);
    $wp_user = get_userdata($customer_id);
    echo "[$label] Cliente autenticado: {$wp_user->user_login} (ID=$customer_id)\n";
} else {
    echo "[$label] Cliente anónimo (guest)\n";
}

$order = wc_create_order(['customer_id' => $customer_id]);

if (is_wp_error($order)) {
    fwrite(STDERR, "Error al crear la orden: " . $order->get_error_message() . "\n");
    exit(1);
}

// ===== PASO 3: PRODUCTOS =====
$product_1 = wc_get_product($product_id_1);
$product_2 = wc_get_product($product_id_2);

$order->add_product($product_1, 1);
$order->add_product($product_2, 1);

echo "[$label] Productos agregados: GELCOL-100 x1, GELCOL-001 x1\n";

// ===== PASO 4: BILLING + SHIPPING (misma dirección — replica "ship to same address" del checkout) =====
foreach ($billing as $field => $value) {
    $bill_setter = "set_billing_{$field}";
    if (method_exists($order, $bill_setter)) {
        $order->$bill_setter($value);
    }
    // Copiar a shipping (WC checkout lo hace automáticamente cuando el cliente no cambia la dirección)
    $ship_setter = "set_shipping_{$field}";
    if ($field !== 'email' && method_exists($order, $ship_setter)) {
        $order->$ship_setter($value);
    }
}
$order->update_meta_data('billing_rut', $rut);

// ===== PASO 5: PAGO =====
$order->set_payment_method('bacs');
$order->set_payment_method_title('Transferencia Bancaria Directa');

// ===== PASO 6: CALCULAR TOTALES (antes del cupón) =====
$order->calculate_totals();

// ===== PASO 7: CUPÓN (opcional) =====
if ($coupon_code) {
    $coupon_result = $order->apply_coupon($coupon_code);
    if (is_wp_error($coupon_result)) {
        echo "[$label] ⚠ Cupón '$coupon_code' falló: " . $coupon_result->get_error_message() . "\n";
    } else {
        echo "[$label] Cupón '$coupon_code' aplicado\n";
        $order->calculate_totals();
    }
}

// ===== PASO 8: GUARDAR =====
$order->save();
$order_id = $order->get_id();

echo "[$label] Orden guardada ID=$order_id, Total=" . $order->get_total() . " CLP\n";

// ===== PASO 9: CAMBIAR A PROCESSING (dispara auto-sync en woo2odoo) =====
$order->update_status('processing', "Test comparativo: $label");

echo "[$label] Estado → processing\n";

// Detalle completo
echo "\n[$label] === RESUMEN ===\n";
echo "[$label] WC Order ID:    $order_id\n";
echo "[$label] Customer ID:    $customer_id\n";
echo "[$label] Cupón:          " . ($coupon_code ?? '(ninguno)') . "\n";
echo "[$label] Subtotal:       " . $order->get_subtotal() . "\n";
echo "[$label] Descuento:      " . $order->get_discount_total() . "\n";
echo "[$label] Total:          " . $order->get_total() . "\n";

foreach ($order->get_items() as $item) {
    echo "[$label]   - {$item->get_name()} x{$item->get_quantity()} = {$item->get_subtotal()}\n";
}
foreach ($order->get_items('coupon') as $coupon_item) {
    echo "[$label]   Cupón: {$coupon_item->get_name()} -" . $coupon_item->get_discount() . "\n";
}

// OUTPUT PARA SCRIPTING — debe ser la última línea
echo "ORDER_ID={$order_id}\n";
