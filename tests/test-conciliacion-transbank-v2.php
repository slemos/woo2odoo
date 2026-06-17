<?php
/**
 * Test del flujo contable con montos reales - Versión 2.
 * Igual al test anterior pero usando productos con precio > 0.
 *
 * Productos usados:
 * - BLDRGL-501 (Builder Gel - Clear): 15,118 CLP
 * - ARTGEL-1300 (Nail Art Gel - Black): 8,395 CLP
 */

// ─────────────────────────────────────────────────────────────────────────────
// CONFIGURACIÓN Y CLIENTE ODOO
// ─────────────────────────────────────────────────────────────────────────────

if (!class_exists('Woo2Odoo\Woo2Odoo_ClientFactory')) {
    echo "ERROR: Woo2Odoo_ClientFactory no disponible.\n";
    exit(1);
}

try {
    $jrpc = \Woo2Odoo\Woo2Odoo_ClientFactory::instance()->get_client();
    echo "[OK] Cliente Odoo instanciado.\n\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

// Datos conocidos
$company_id      = 1;
$journal_bank_id = 14;
$journal_inv_id  = 9;
$acct_customers  = 47;
$acct_bank       = 233;
$acct_susp       = 235;
$acct_outstanding = 236;
$doc_type_boleta = 5;

echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "TEST FLUJO CONTABLE TRANSBANK - CON MONTOS REALES\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// PASO 1: BUSCAR PARTNER Y PRODUCTOS
// ─────────────────────────────────────────────────────────────────────────────

echo "PASO 1: Buscando partner (cliente) disponible...\n";

$partners = $jrpc->execute('res.partner', 'search_read', [
    [
        ['customer_rank', '>', 0],
        ['active', '=', true],
        ['id', '!=', 347]
    ],
    ['id', 'name', 'vat', 'email'],
    0,
    5
]);

if (empty($partners)) {
    echo "ERROR: No se encontraron partners.\n";
    exit(1);
}

$partner = $partners[0];
$partner_id = $partner->id;
$partner_name = $partner->name;
echo "  [OK] Partner: ID=$partner_id, Nombre=$partner_name\n\n";

echo "PASO 2: Buscando productos con precio > 0...\n";

// Buscar productos específicos por SKU
$products = $jrpc->execute('product.product', 'search_read', [
    [
        ['default_code', 'in', ['BLDRGL-501', 'ARTGEL-1300']],
        ['sale_ok', '=', true],
        ['active', '=', true]
    ],
    ['id', 'name', 'default_code', 'lst_price'],
    0,
    2
]);

if (count($products) < 2) {
    echo "ERROR: Se necesitan 2 productos. Se encontraron: " . count($products) . "\n";
    exit(1);
}

$prod1 = $products[0];
$prod2 = $products[1];
echo "  [OK] Producto 1: ID={$prod1->id}, SKU={$prod1->default_code}, Precio={$prod1->lst_price}\n";
echo "  [OK] Producto 2: ID={$prod2->id}, SKU={$prod2->default_code}, Precio={$prod2->lst_price}\n\n";

// Calcular total esperado
$total_expected = ($prod1->lst_price * 2) + ($prod2->lst_price * 1);
echo "  ➤ Total esperado: " . number_format($total_expected, 0, ',', '.') . " CLP\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// PASO 3: CREAR SALES ORDER
// ─────────────────────────────────────────────────────────────────────────────

echo "PASO 3: Creando Sales Order...\n";

$so_data = [
    'partner_id'   => $partner_id,
    'company_id'   => $company_id,
    'order_line'   => [
        [0, 0, [
            'product_id' => $prod1->id,
            'product_qty' => 2,
            'price_unit' => $prod1->lst_price
        ]],
        [0, 0, [
            'product_id' => $prod2->id,
            'product_qty' => 1,
            'price_unit' => $prod2->lst_price
        ]]
    ]
];

try {
    $so_id = $jrpc->create('sale.order', $so_data);
    echo "  [OK] SO creada: ID=$so_id\n\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
// PASO 4: CONFIRMAR SALES ORDER
// ─────────────────────────────────────────────────────────────────────────────

echo "PASO 4: Confirmando Sales Order...\n";

try {
    $jrpc->execute('sale.order', 'action_confirm', [[$so_id]]);
    echo "  [OK] SO confirmada\n\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
// PASO 5: CREAR INVOICE MANUALMENTE
// ─────────────────────────────────────────────────────────────────────────────

echo "PASO 5: Creando Invoice manualmente...\n";

$invoice_data = [
    'move_type' => 'out_invoice',
    'partner_id' => $partner_id,
    'company_id' => $company_id,
    'journal_id' => $journal_inv_id,
    'invoice_date' => date('Y-m-d'),
    'l10n_latam_document_type_id' => $doc_type_boleta,
    'invoice_line_ids' => [
        [0, 0, [
            'product_id' => $prod1->id,
            'quantity' => 2,
            'price_unit' => $prod1->lst_price
        ]],
        [0, 0, [
            'product_id' => $prod2->id,
            'quantity' => 1,
            'price_unit' => $prod2->lst_price
        ]]
    ]
];

try {
    $invoice_id = $jrpc->create('account.move', $invoice_data);
    echo "  [OK] Invoice creada: ID=$invoice_id\n\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
// PASO 6: CONFIRMAR INVOICE
// ─────────────────────────────────────────────────────────────────────────────

echo "PASO 6: Confirmando Invoice...\n";

try {
    $jrpc->execute('account.move', 'action_post', [[$invoice_id]]);
    echo "  [OK] Invoice confirmada\n\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

// Obtener datos de la invoice
$inv_data = $jrpc->execute('account.move', 'read', [[$invoice_id], ['amount_total', 'payment_state', 'state']]);
$inv_data = $inv_data[0];
$inv_total = $inv_data->amount_total;

echo "  Invoice Total: " . number_format($inv_total, 0, ',', '.') . " CLP\n";
echo "  Invoice State: {$inv_data->state}\n";
echo "  Invoice Payment State: {$inv_data->payment_state}\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// PASO 7: CREAR PAYMENT EN JOURNAL BANCO
// ─────────────────────────────────────────────────────────────────────────────

echo "PASO 7: Creando Payment en Banco...\n";

$payment_data = [
    'payment_type' => 'inbound',
    'partner_type' => 'customer',
    'partner_id' => $partner_id,
    'journal_id' => $journal_bank_id,
    'amount' => $inv_total,
    'date' => date('Y-m-d'),
    'memo' => 'Pago Transbank - Invoice ' . $invoice_id,
    'company_id' => $company_id
];

try {
    $payment_data['invoice_ids'] = [[6, 0, [$invoice_id]]];
    $payment_id = $jrpc->create('account.payment', $payment_data);
    echo "  [OK] Payment creado: ID=$payment_id\n";
} catch (\Throwable $e) {
    $payment_data_no_inv = $payment_data;
    unset($payment_data_no_inv['invoice_ids']);
    try {
        $payment_id = $jrpc->create('account.payment', $payment_data_no_inv);
        echo "  [OK] Payment creado (sin invoice_ids): ID=$payment_id\n";
    } catch (\Throwable $e2) {
        echo "ERROR: " . $e2->getMessage() . "\n";
        exit(1);
    }
}

// Confirmar payment
try {
    $jrpc->execute('account.payment', 'action_post', [[$payment_id]]);
    echo "  [OK] Payment confirmado\n\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

// Obtener datos del payment
$pay_data = $jrpc->execute('account.payment', 'read', [[$payment_id], ['move_id', 'state', 'amount']]);
$pay_data = $pay_data[0];
$pay_move_id = is_array($pay_data->move_id) ? $pay_data->move_id[0] : $pay_data->move_id;

echo "  Payment State: {$pay_data->state}\n";
echo "  Payment Move: $pay_move_id\n\n";

// Obtener asiento del payment
$pay_move_data = $jrpc->execute('account.move', 'read', [[$pay_move_id], ['state', 'line_ids']]);
$pay_move_data = $pay_move_data[0];
$pay_move_lines = $pay_move_data->line_ids;

$pay_lines = $jrpc->execute('account.move.line', 'read', [$pay_move_lines, ['account_id', 'debit', 'credit', 'reconciled']]);

echo "┌─ ASIENTO DE PAGO (Move $pay_move_id):\n";
foreach ($pay_lines as $line) {
    $acct = $jrpc->execute('account.account', 'read', [[$line->account_id[0]], ['code', 'name']]);
    $acct = $acct[0];
    $dr = number_format($line->debit, 2, ',', '.');
    $cr = number_format($line->credit, 2, ',', '.');
    $recon = $line->reconciled ? 'sí' : 'no';
    echo "├─ [{$acct->code}] {$acct->name}\n";
    echo "│  ├─ Débito: {$dr} | Crédito: {$cr} | Reconciled: {$recon}\n";
}
echo "└─\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// PASO 8: CREAR ASIENTO TRANSBANK (NETO)
// ─────────────────────────────────────────────────────────────────────────────

echo "PASO 8: Creando asiento simulando depósito Transbank...\n";

$transbank_net = $inv_total * 0.98;
$transbank_fee = $inv_total * 0.02;

echo "  Total de Invoice: " . number_format($inv_total, 2, ',', '.') . " CLP\n";
echo "  Neto (98%): " . number_format($transbank_net, 2, ',', '.') . " CLP\n";
echo "  Comisión (2%): " . number_format($transbank_fee, 2, ',', '.') . " CLP\n\n";

$transbank_move_data = [
    'move_type' => 'entry',
    'journal_id' => $journal_bank_id,
    'date' => date('Y-m-d'),
    'ref' => 'TBK Deposit #' . $invoice_id,
    'company_id' => $company_id,
    'line_ids' => [
        [0, 0, [
            'account_id' => $acct_bank,
            'debit' => $transbank_net,
            'credit' => 0
        ]],
        [0, 0, [
            'account_id' => $acct_susp,
            'debit' => 0,
            'credit' => $transbank_net
        ]]
    ]
];

try {
    $transbank_move_id = $jrpc->create('account.move', $transbank_move_data);
    echo "  [OK] Asiento Transbank creado: ID=$transbank_move_id\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

// Confirmar asiento Transbank
try {
    $jrpc->execute('account.move', 'action_post', [[$transbank_move_id]]);
    echo "  [OK] Asiento Transbank confirmado\n\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

// Obtener detalles del asiento Transbank
$tb_move_data = $jrpc->execute('account.move', 'read', [[$transbank_move_id], ['state', 'line_ids']]);
$tb_move_data = $tb_move_data[0];
$tb_move_lines = $tb_move_data->line_ids;

$tb_lines = $jrpc->execute('account.move.line', 'read', [$tb_move_lines, ['account_id', 'debit', 'credit', 'reconciled']]);

echo "┌─ ASIENTO TRANSBANK (Move $transbank_move_id):\n";
foreach ($tb_lines as $line) {
    $acct = $jrpc->execute('account.account', 'read', [[$line->account_id[0]], ['code', 'name']]);
    $acct = $acct[0];
    $dr = number_format($line->debit, 2, ',', '.');
    $cr = number_format($line->credit, 2, ',', '.');
    echo "├─ [{$acct->code}] {$acct->name}\n";
    echo "│  ├─ Débito: {$dr} | Crédito: {$cr}\n";
}
echo "└─\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// PASO 9: VERIFICACIÓN FINAL
// ─────────────────────────────────────────────────────────────────────────────

echo "PASO 9: Verificación final...\n\n";

$final_inv = $jrpc->execute('account.move', 'read', [[$invoice_id], ['state', 'payment_state', 'amount_total', 'amount_residual']]);
$final_inv = $final_inv[0];

echo "ESTADO FINAL DE INVOICE:\n";
echo "  ID: $invoice_id\n";
echo "  State: {$final_inv->state}\n";
echo "  Payment State: {$final_inv->payment_state}\n";
echo "  Total: " . number_format($final_inv->amount_total, 2, ',', '.') . " CLP\n";
echo "  Residual: " . number_format($final_inv->amount_residual, 2, ',', '.') . " CLP\n\n";

$final_pay = $jrpc->execute('account.payment', 'read', [[$payment_id], ['state', 'amount']]);
$final_pay = $final_pay[0];

echo "ESTADO FINAL DE PAYMENT:\n";
echo "  ID: $payment_id\n";
echo "  State: {$final_pay->state}\n";
echo "  Amount: " . number_format($final_pay->amount, 2, ',', '.') . " CLP\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// RESUMEN
// ─────────────────────────────────────────────────────────────────────────────

echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "RESUMEN\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n\n";

echo "IDs Generados:\n";
echo "  Partner: $partner_id ($partner_name)\n";
echo "  Sale Order: $so_id\n";
echo "  Invoice: $invoice_id\n";
echo "  Payment: $payment_id (Move: $pay_move_id)\n";
echo "  Transbank Move: $transbank_move_id\n\n";

echo "Montos:\n";
echo "  Invoice Total: " . number_format($final_inv->amount_total, 2, ',', '.') . " CLP\n";
echo "  Payment Amount: " . number_format($final_pay->amount, 2, ',', '.') . " CLP\n";
echo "  Transbank Neto: " . number_format($transbank_net, 2, ',', '.') . " CLP\n";
echo "  Transbank Fee: " . number_format($transbank_fee, 2, ',', '.') . " CLP\n\n";

echo "Estados Finales:\n";
echo "  Invoice: {$final_inv->state} / Payment: {$final_inv->payment_state}\n";
echo "  Payment: {$final_pay->state}\n";
echo "  Transbank: {$tb_move_data->state}\n\n";

$success = $final_inv->amount_residual == 0 && $final_pay->state == 'posted';

if ($success) {
    echo "✅ FLUJO EXITOSO CON MONTOS REALES\n";
    echo "   Invoice totalmente pagada (residual = 0)\n";
} else {
    echo "⚠️ FLUJO PARCIAL\n";
    echo "   Residual pendiente: " . number_format($final_inv->amount_residual, 2, ',', '.') . " CLP\n";
}

echo "\n";
exit(0);
?>
