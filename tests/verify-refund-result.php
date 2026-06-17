<?php
/**
 * Verifica en arm-testing Odoo el resultado del flujo de refund.
 *
 * Dado un WC order ID, muestra:
 *   - sale.order en Odoo
 *   - Invoice original (out_invoice) y su estado
 *   - Notas de crédito (out_refund) vinculadas
 *   - Meta WP (_woo2odoo_invoice_id, _woo2odoo_return_invoice_id del refund)
 *
 * Uso (en bastion, sin WP):
 *   php -d memory_limit=256M tests/verify-refund-result.php <WC_ORDER_ID> [WC_REFUND_ID]
 *
 * Uso (en contenedor, con WP para leer post_meta):
 *   docker exec -e PHPUNIT_TESTING=0 -w /var/www/html/wp-content/plugins/woo2odoo \
 *     infra-php-1 php -d memory_limit=256M tests/verify-refund-result.php <WC_ORDER_ID> [WC_REFUND_ID]
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

use winternet\odoo\JsonRpcClient;

$wc_order_id  = (int) ($argv[1] ?? 0);
$wc_refund_id = (int) ($argv[2] ?? 0);

if (!$wc_order_id) {
    echo "Uso: php verify-refund-result.php <WC_ORDER_ID> [WC_REFUND_ID]\n";
    exit(1);
}

// Intentar cargar WP para leer post_meta (solo si corremos dentro del contenedor)
$wp_loaded = false;
$wp_load = '/var/www/html/wp-load.php';
if (file_exists($wp_load)) {
    if (!function_exists('woocommerce_register_additional_checkout_field')) {
        function woocommerce_register_additional_checkout_field() {}
    }
    define('WP_ADMIN', true);
    require_once($wp_load);
    $wp_loaded = class_exists('WC_Order');
}

$client = new JsonRpcClient(
    $_ENV['ODOO_URL'],
    $_ENV['ODOO_DBNAME'],
    $_ENV['ODOO_USER'],
    $_ENV['ODOO_PASSWORD']
);

$result = [
    'wc_order_id'  => $wc_order_id,
    'wc_refund_id' => $wc_refund_id ?: null,
    'timestamp'    => date('c'),
    'wp_meta'      => [],
    'sale_order'   => null,
    'invoice'      => null,
    'credit_notes' => [],
    'errors'       => [],
];

echo "═══════════════════════════════════════════════════\n";
echo " Verificando refund — WC Order #{$wc_order_id}\n";
echo "═══════════════════════════════════════════════════\n\n";

// ── WP post_meta ─────────────────────────────────────────────────────────────
if ($wp_loaded) {
    $odoo_inv   = (int) get_post_meta($wc_order_id, '_woo2odoo_invoice_id', true);
    $result['wp_meta']['order']['_woo2odoo_invoice_id'] = $odoo_inv;
    echo "WP post_meta (orden #{$wc_order_id}):\n";
    echo "  _woo2odoo_invoice_id: " . ($odoo_inv ?: '(vacío)') . "\n";

    if ($wc_refund_id) {
        $return_inv = (int) get_post_meta($wc_refund_id, '_woo2odoo_return_invoice_id', true);
        $result['wp_meta']['refund']['_woo2odoo_return_invoice_id'] = $return_inv;
        echo "WP post_meta (refund #{$wc_refund_id}):\n";
        echo "  _woo2odoo_return_invoice_id: " . ($return_inv ?: '(vacío)') . "\n";
    }
    echo "\n";
} else {
    echo "(WP no disponible — saltando lectura de post_meta)\n\n";
}

// ── sale.order ───────────────────────────────────────────────────────────────
echo "── sale.order ──────────────────────────────────────\n";
$orders = $client->searchRead('sale.order', [
    'where'  => [['origin', 'like', (string) $wc_order_id]],
    'fields' => ['id', 'name', 'origin', 'amount_total', 'state', 'invoice_status', 'partner_id'],
    'limit'  => 3,
]);

if (empty($orders)) {
    echo "✗ No se encontró sale.order con origin like '$wc_order_id'\n";
    $result['errors'][] = "No sale.order found";
} else {
    $so = $orders[0];
    echo "✓ ID:           {$so->id}\n";
    echo "  Nombre:       {$so->name}\n";
    echo "  Total:        {$so->amount_total}\n";
    echo "  Estado SO:    {$so->state}\n";
    echo "  Fact status:  {$so->invoice_status}\n";
    echo "  Partner:      {$so->partner_id[1]}\n";
    $result['sale_order'] = (array) $so;
}

// ── account.move (invoice original + credit notes) ───────────────────────────
echo "\n── account.move vinculadas ─────────────────────────\n";

// Search invoices by SO origin, then credit notes by reversed_entry_id
$so_name = $result['sale_order']['name'] ?? null;

// First find invoices linked to this SO
$inv_where = [['move_type', '=', 'out_invoice']];
if ($so_name) {
    $inv_where[] = ['invoice_origin', 'like', $so_name];
} else {
    $inv_where[] = ['invoice_origin', 'like', (string) $wc_order_id];
}

$invoices_found = $client->searchRead('account.move', [
    'where'  => $inv_where,
    'fields' => ['id', 'name', 'move_type', 'invoice_origin', 'amount_total',
                 'amount_untaxed', 'state', 'payment_state',
                 'l10n_latam_document_type_id', 'l10n_latam_document_number',
                 'reversed_entry_id'],
    'limit'  => 5,
    'order'  => 'id ASC',
]) ?: [];

// Find credit notes: search by reversed_entry_id OR by known ID from WP meta
$invoice_ids = array_column(array_map('get_object_vars', $invoices_found), 'id');
$cn_found = [];

// Collect known credit note IDs from WP meta
$known_cn_ids = [];
if (isset($result['wp_meta']['refund']['_woo2odoo_return_invoice_id']) &&
    $result['wp_meta']['refund']['_woo2odoo_return_invoice_id']) {
    $known_cn_ids[] = (int) $result['wp_meta']['refund']['_woo2odoo_return_invoice_id'];
}

// Build OR search: by reversed_entry_id or by direct ID from meta
$cn_where = [['move_type', '=', 'out_refund']];
if (!empty($known_cn_ids)) {
    $cn_where[] = ['id', 'in', $known_cn_ids];
} elseif (!empty($invoice_ids)) {
    $cn_where[] = ['reversed_entry_id', 'in', $invoice_ids];
}

if (!empty($known_cn_ids) || !empty($invoice_ids)) {
    $cn_found = $client->searchRead('account.move', [
        'where'  => $cn_where,
        'fields' => ['id', 'name', 'move_type', 'invoice_origin', 'amount_total',
                     'amount_untaxed', 'state', 'payment_state',
                     'l10n_latam_document_type_id', 'l10n_latam_document_number',
                     'reversed_entry_id'],
        'limit'  => 10,
        'order'  => 'id ASC',
    ]) ?: [];
}

$moves = array_merge($invoices_found, $cn_found);

if (empty($moves)) {
    echo "✗ No se encontraron account.move vinculadas\n";
    $result['errors'][] = "No account.move found";
} else {
    foreach ($moves as $move) {
        $type_label = $move->move_type === 'out_invoice' ? 'FACTURA' : 'NOTA CRÉDITO';
        $doc_type   = is_array($move->l10n_latam_document_type_id)
                        ? $move->l10n_latam_document_type_id[1]
                        : ($move->l10n_latam_document_type_id ?? '-');

        echo "\n  [$type_label] ID={$move->id}\n";
        echo "    Nombre:       {$move->name}\n";
        echo "    Tipo doc:     {$doc_type}\n";
        echo "    Total:        {$move->amount_total} CLP\n";
        echo "    Estado:       {$move->state}\n";
        echo "    Pago:         {$move->payment_state}\n";
        echo "    Origin:       {$move->invoice_origin}\n";
        if ($move->reversed_entry_id) {
            $rev_id = is_array($move->reversed_entry_id) ? $move->reversed_entry_id[0] : $move->reversed_entry_id;
            echo "    Revierte:     #{$rev_id}\n";
        }

        if ($move->move_type === 'out_invoice') {
            $result['invoice'] = (array) $move;
        } else {
            $result['credit_notes'][] = (array) $move;
        }
    }
}

// ── Resumen ───────────────────────────────────────────────────────────────────
echo "\n═══════════════════════════════════════════════════\n";
$nc_count = count($result['credit_notes']);
$inv_ok   = $result['invoice'] && $result['invoice']['state'] === 'posted';
$nc_ok    = $nc_count > 0;

echo " Invoice original: " . ($inv_ok   ? "✓ posted" : "✗ " . ($result['invoice']['state'] ?? 'no encontrada')) . "\n";
echo " Notas de crédito: " . ($nc_ok    ? "✓ $nc_count encontrada(s)" : "✗ ninguna") . "\n";
echo " Errores:          " . (empty($result['errors']) ? "ninguno" : implode(', ', $result['errors'])) . "\n";
echo "═══════════════════════════════════════════════════\n";

// Guardar JSON
$out_dir  = __DIR__ . '/results';
if (!is_dir($out_dir)) mkdir($out_dir, 0755, true);
$out_file = "{$out_dir}/refund-{$wc_order_id}-result.json";
file_put_contents($out_file, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nResultado guardado: {$out_file}\n";

exit(empty($result['errors']) && $inv_ok && $nc_ok ? 0 : 1);
