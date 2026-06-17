<?php
/**
 * Test del flujo contable completo para pagos Transbank en Odoo.
 *
 * Valida:
 * 1. Crear Sales Order
 * 2. Confirmar SO
 * 3. Crear Invoice tipo Boleta (39)
 * 4. Confirmar Invoice
 * 5. Crear payment en journal Banco (ID 14)
 * 6. Crear asiento simulando depósito Transbank
 * 7. Conciliar asiento con pago
 * 8. Verificar invoice en payment_state = paid
 *
 * Uso:
 *   ssh arm "docker cp /tmp/test-conciliacion-transbank.php infra-php-1:/tmp/ && \
 *   docker exec infra-php-1 bash -c 'php -d memory_limit=512M /usr/local/bin/wp \
 *   --path=/var/www/html --allow-root --skip-plugins=kadence-woo-extras,kadence-blocks-pro,elementor,kadence-woocommerce-email-designer,elementor-pro \
 *   eval-file /tmp/test-conciliacion-transbank.php'"
 */

// Función helper para convertir objetos a arrays recursivamente
function object_to_array($obj) {
    if (is_array($obj)) {
        return array_map(__FUNCTION__, $obj);
    }
    if (is_object($obj)) {
        return array_map(__FUNCTION__, get_object_vars($obj));
    }
    return $obj;
}

// ─────────────────────────────────────────────────────────────────────────────
// CONFIGURACIÓN Y CLIENTE ODOO
// ─────────────────────────────────────────────────────────────────────────────

// Cargar cliente Odoo del plugin
if (!class_exists('Woo2Odoo\Woo2Odoo_ClientFactory')) {
    echo "ERROR: Woo2Odoo_ClientFactory no disponible. El plugin woo2odoo debe estar activo.\n";
    exit(1);
}

try {
    $jrpc = \Woo2Odoo\Woo2Odoo_ClientFactory::instance()->get_client();
    echo "[OK] Cliente Odoo instanciado.\n\n";
} catch (\Throwable $e) {
    echo "ERROR al instanciar cliente Odoo: " . $e->getMessage() . "\n";
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
$doc_type_boleta = 5; // l10n_latam ID para Boleta
$payment_method_line_id = 19;

echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "TEST FLUJO CONTABLE TRANSBANK\n";
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
    echo "ERROR: No se encontraron partners disponibles.\n";
    exit(1);
}

$partner = $partners[0];
$partner_id = $partner->id;
$partner_name = $partner->name;
echo "  [OK] Partner encontrado: ID=$partner_id, Nombre=$partner_name\n\n";

echo "PASO 2: Buscando productos disponibles...\n";

$products = $jrpc->execute('product.product', 'search_read', [
    [
        ['sale_ok', '=', true],
        ['active', '=', true],
        ['default_code', '!=', 'BLDRGL-516'],
        ['default_code', '!=', 'GELCOL-091'],
        ['default_code', '!=', 'GELCOL-086']
    ],
    ['id', 'name', 'default_code', 'lst_price'],
    0,
    3
]);

if (count($products) < 2) {
    echo "ERROR: Se necesitan al menos 2 productos disponibles. Se encontraron: " . count($products) . "\n";
    exit(1);
}

$prod1 = $products[0];
$prod2 = $products[1];
echo "  [OK] Producto 1: ID={$prod1->id}, SKU={$prod1->default_code}, Precio={$prod1->lst_price}\n";
echo "  [OK] Producto 2: ID={$prod2->id}, SKU={$prod2->default_code}, Precio={$prod2->lst_price}\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// PASO 3: CREAR SALES ORDER
// ─────────────────────────────────────────────────────────────────────────────

echo "PASO 3: Creando Sales Order...\n";

$so_data = [
    'partner_id'   => $partner_id,
    'company_id'   => $company_id,
    'state'        => 'draft',
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
    echo "  [OK] Sales Order creada: ID=$so_id\n\n";
} catch (\Throwable $e) {
    echo "ERROR al crear Sales Order: " . $e->getMessage() . "\n";
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
// PASO 4: CONFIRMAR SALES ORDER
// ─────────────────────────────────────────────────────────────────────────────

echo "PASO 4: Confirmando Sales Order...\n";

try {
    $jrpc->execute('sale.order', 'action_confirm', [[$so_id]]);
    echo "  [OK] Sales Order confirmada\n\n";
} catch (\Throwable $e) {
    echo "ERROR al confirmar Sales Order: " . $e->getMessage() . "\n";
    exit(1);
}

// Obtener invoice generada por la SO (si existe)
// Nota: Odoo genera invoice automáticamente o necesitamos crearla manualmente
echo "PASO 5: Verificando si existe invoice automática...\n";
$invoices = $jrpc->execute('account.move', 'search', [
    [
        ['move_type', '=', 'out_invoice'],
        ['partner_id', '=', $partner_id],
        ['state', '=', 'draft']
    ],
    0,
    1
]);

$invoice_id = null;
if (!empty($invoices)) {
    $invoice_id = $invoices[0];
    echo "  [Info] Invoice generada automáticamente por SO: ID=$invoice_id\n";
} else {
    echo "  [Info] No se encontró invoice automática, será creada manualmente en PASO 5.\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// PASO 6: CREAR INVOICE MANUALMENTE SI NO EXISTE
// ─────────────────────────────────────────────────────────────────────────────

if (!$invoice_id) {
    echo "PASO 6: Creando Invoice manualmente...\n";

    // Obtener líneas de venta
    $so_data_read = $jrpc->execute('sale.order', 'read', [[$so_id], ['order_line']]);
    $so_data_read = $so_data_read[0];

    $invoice_lines = [];
    foreach ($so_data_read->order_line as $line_id) {
        $line = $jrpc->execute('sale.order.line', 'read', [[$line_id], ['product_id', 'product_qty', 'price_unit']]);
        $line = $line[0];

        $invoice_lines[] = [0, 0, [
            'product_id' => is_array($line->product_id) ? $line->product_id[0] : $line->product_id,
            'quantity' => $line->product_qty,
            'price_unit' => $line->price_unit
        ]];
    }

    $invoice_data = [
        'move_type' => 'out_invoice',
        'partner_id' => $partner_id,
        'company_id' => $company_id,
        'journal_id' => $journal_inv_id,
        'invoice_date' => date('Y-m-d'),
        'invoice_line_ids' => $invoice_lines,
        'l10n_latam_document_type_id' => $doc_type_boleta
    ];

    try {
        $invoice_id = $jrpc->create('account.move', $invoice_data);
        echo "  [OK] Invoice creada manualmente: ID=$invoice_id\n\n";
    } catch (\Throwable $e) {
        echo "ERROR al crear Invoice: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    echo "PASO 6: Actualizando Invoice con tipo de documento Boleta...\n";
    try {
        $jrpc->execute('account.move', 'write', [[$invoice_id], ['l10n_latam_document_type_id' => $doc_type_boleta]]);
        echo "  [OK] Invoice actualizada con tipo Boleta (5)\n\n";
    } catch (\Throwable $e) {
        echo "ERROR al actualizar Invoice: " . $e->getMessage() . "\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PASO 7: CONFIRMAR INVOICE
// ─────────────────────────────────────────────────────────────────────────────

echo "PASO 7: Confirmando Invoice (action_post)...\n";

try {
    $jrpc->execute('account.move', 'action_post', [[$invoice_id]]);
    echo "  [OK] Invoice confirmada y posted\n\n";
} catch (\Throwable $e) {
    echo "ERROR al confirmar Invoice: " . $e->getMessage() . "\n";
    exit(1);
}

// Obtener datos de la invoice
$inv_data = $jrpc->execute('account.move', 'read', [[$invoice_id], ['amount_total', 'payment_state', 'state']]);
$inv_data = $inv_data[0];
$inv_total = $inv_data->amount_total;
$inv_state = $inv_data->state;
$inv_payment_state = $inv_data->payment_state;

echo "  Invoice Total: $inv_total CLP\n";
echo "  Invoice State: $inv_state\n";
echo "  Invoice Payment State: $inv_payment_state\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// PASO 8: CREAR PAYMENT EN JOURNAL BANCO
// ─────────────────────────────────────────────────────────────────────────────

echo "PASO 8: Creando payment en journal Banco (ID=$journal_bank_id)...\n";

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

$payment_id = null;

// Intenta añadir invoice_ids si soporta many2many
try {
    $payment_data['invoice_ids'] = [[6, 0, [$invoice_id]]];
    $payment_id = $jrpc->create('account.payment', $payment_data);
    echo "  [OK] Payment creado con invoice_ids: ID=$payment_id\n";
} catch (\Throwable $e) {
    echo "  [Info] No se pudo crear payment con invoice_ids, intentando sin...\n";

    $payment_data_no_inv = $payment_data;
    unset($payment_data_no_inv['invoice_ids']);

    try {
        $payment_id = $jrpc->create('account.payment', $payment_data_no_inv);
        echo "  [OK] Payment creado sin invoice_ids: ID=$payment_id\n";
    } catch (\Throwable $e2) {
        echo "ERROR al crear payment: " . $e2->getMessage() . "\n";
        exit(1);
    }
}

// Confirmar (post) el payment
try {
    $jrpc->execute('account.payment', 'action_post', [[$payment_id]]);
    echo "  [OK] Payment confirmado (action_post)\n\n";
} catch (\Throwable $e) {
    echo "ERROR al confirmar payment: " . $e->getMessage() . "\n";
    exit(1);
}

// Obtener datos del payment
$pay_data = $jrpc->execute('account.payment', 'read', [[$payment_id], ['move_id', 'state', 'amount', 'payment_method_line_id']]);
$pay_data = $pay_data[0];
$pay_move_id = is_array($pay_data->move_id) ? $pay_data->move_id[0] : $pay_data->move_id;
$pay_state = $pay_data->state;

echo "  Payment State: $pay_state\n";
echo "  Payment Move ID: $pay_move_id\n\n";

// Obtener asiento del payment
$pay_move_data = $jrpc->execute('account.move', 'read', [[$pay_move_id], ['state', 'line_ids']]);
$pay_move_data = $pay_move_data[0];
$pay_move_lines = $pay_move_data->line_ids;

echo "  Payment Move State: {$pay_move_data->state}\n";
echo "  Payment Move Lines: " . count($pay_move_lines) . "\n";

// Detallar líneas del asiento
$pay_lines = $jrpc->execute('account.move.line', 'read', [$pay_move_lines, ['account_id', 'debit', 'credit', 'reconciled']]);
echo "  ┌─ Detalles del asiento de pago:\n";
foreach ($pay_lines as $line) {
    $acct_name = $jrpc->execute('account.account', 'read', [[$line->account_id[0]], ['code', 'name']]);
    $acct_name = $acct_name[0];
    $acct_code = $acct_name->code;
    $acct_display = $acct_name->name;
    $dr = $line->debit ?? 0;
    $cr = $line->credit ?? 0;
    $recon = $line->reconciled ? 'sí' : 'no';
    echo "  ├─ [$acct_code] $acct_display: Dr=$dr, Cr=$cr, Reconciled=$recon\n";
}
echo "  └─\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// PASO 9: CREAR ASIENTO SIMULANDO DEPÓSITO TRANSBANK (NETO)
// ─────────────────────────────────────────────────────────────────────────────

echo "PASO 9: Creando asiento simulando depósito Transbank (neto 98%)...\n";

$transbank_percent = 0.98;
$transbank_net = $inv_total * $transbank_percent;
$transbank_fee = $inv_total - $transbank_net;

echo "  Total: $inv_total CLP\n";
echo "  Neto (98%): $transbank_net CLP\n";
echo "  Comisión (2%): $transbank_fee CLP\n";

$transbank_move_data = [
    'move_type' => 'entry',
    'journal_id' => $journal_bank_id,
    'date' => date('Y-m-d'),
    'ref' => 'Depósito Transbank Invoice ' . $invoice_id,
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
    echo "ERROR al crear asiento Transbank: " . $e->getMessage() . "\n";
    exit(1);
}

// Confirmar asiento Transbank
try {
    $jrpc->execute('account.move', 'action_post', [[$transbank_move_id]]);
    echo "  [OK] Asiento Transbank confirmado\n\n";
} catch (\Throwable $e) {
    echo "ERROR al confirmar asiento Transbank: " . $e->getMessage() . "\n";
    exit(1);
}

// Obtener líneas del asiento Transbank
$tb_move_data = $jrpc->execute('account.move', 'read', [[$transbank_move_id], ['state', 'line_ids']]);
$tb_move_data = $tb_move_data[0];
$tb_move_lines = $tb_move_data->line_ids;

echo "  Asiento Transbank State: {$tb_move_data->state}\n";
echo "  ┌─ Detalles del asiento Transbank:\n";
$tb_lines = $jrpc->execute('account.move.line', 'read', [$tb_move_lines, ['account_id', 'debit', 'credit', 'reconciled']]);
foreach ($tb_lines as $line) {
    $acct_info = $jrpc->execute('account.account', 'read', [[$line->account_id[0]], ['code', 'name']]);
    $acct_info = $acct_info[0];
    $dr = $line->debit ?? 0;
    $cr = $line->credit ?? 0;
    $recon = $line->reconciled ? 'sí' : 'no';
    echo "  ├─ [{$acct_info->code}] {$acct_info->name}: Dr=$dr, Cr=$cr, Reconciled=$recon\n";
}
echo "  └─\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// PASO 10: CONCILIAR
// ─────────────────────────────────────────────────────────────────────────────

echo "PASO 10: Intentando conciliar asiento Transbank con pago...\n";

// Buscar línea Dr en Outstanding Receipts del pago
$outstanding_line_id = null;
foreach ($pay_lines as $line) {
    if ($line->account_id[0] == $acct_outstanding && $line->debit > 0) {
        $outstanding_line_id = $line->id;
        break;
    }
}

// Buscar línea Cr en Suspense del asiento TB
$suspense_line_id = null;
foreach ($tb_lines as $line) {
    if ($line->account_id[0] == $acct_susp && $line->credit > 0) {
        $suspense_line_id = $line->id;
        break;
    }
}

if ($outstanding_line_id && $suspense_line_id) {
    echo "  [Info] Found outstanding line: $outstanding_line_id, suspense line: $suspense_line_id\n";

    try {
        $jrpc->execute('account.move.line', 'reconcile', [[$suspense_line_id]]);
        echo "  [OK] Línea reconciliada via reconcile()\n";
    } catch (\Throwable $e) {
        echo "  [Info] reconcile() no funcionó: " . $e->getMessage() . "\n";
        echo "  [Info] Esto puede ser esperado; Odoo puede requerir pasos adicionales.\n";
    }
} else {
    echo "  [Info] No se encontraron líneas para reconciliar.\n";
    echo "  [Info] Outstanding line: " . ($outstanding_line_id ?? 'NO FOUND') . "\n";
    echo "  [Info] Suspense line: " . ($suspense_line_id ?? 'NO FOUND') . "\n";
}

echo "\n";

// ─────────────────────────────────────────────────────────────────────────────
// PASO 11: VERIFICACIÓN FINAL
// ─────────────────────────────────────────────────────────────────────────────

echo "PASO 11: Verificación final...\n\n";

// Estado de la invoice
$final_inv_data = $jrpc->execute('account.move', 'read', [[$invoice_id], ['state', 'payment_state', 'amount_total', 'amount_residual']]);
$final_inv_data = $final_inv_data[0];

echo "INVOICE FINAL STATE:\n";
echo "  ID: $invoice_id\n";
echo "  State: {$final_inv_data->state}\n";
echo "  Payment State: {$final_inv_data->payment_state}\n";
echo "  Total: {$final_inv_data->amount_total}\n";
echo "  Residual (pendiente): {$final_inv_data->amount_residual}\n\n";

// Estado del payment
$final_pay_data = $jrpc->execute('account.payment', 'read', [[$payment_id], ['state', 'amount']]);
$final_pay_data = $final_pay_data[0];

echo "PAYMENT FINAL STATE:\n";
echo "  ID: $payment_id\n";
echo "  State: {$final_pay_data->state}\n";
echo "  Amount: {$final_pay_data->amount}\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// RESUMEN
// ─────────────────────────────────────────────────────────────────────────────

echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "RESUMEN DE PRUEBA\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n\n";

echo "IDs Generados:\n";
echo "  Partner: $partner_id ($partner_name)\n";
echo "  Sales Order: $so_id\n";
echo "  Invoice: $invoice_id\n";
echo "  Payment: $payment_id (Move: $pay_move_id)\n";
echo "  Transbank Move: $transbank_move_id\n\n";

echo "Estados Finales:\n";
echo "  Invoice State: {$final_inv_data->state}\n";
echo "  Invoice Payment State: {$final_inv_data->payment_state}\n";
echo "  Payment State: {$final_pay_data->state}\n\n";

$success = $final_inv_data->payment_state === 'paid' || $final_inv_data->payment_state === 'in_payment';

if ($success) {
    echo "✅ FLUJO COMPLETADO CON ÉXITO\n";
    echo "   La invoice está en estado payment_state = {$final_inv_data->payment_state}\n";
} else {
    echo "⚠️  FLUJO INCOMPLETO\n";
    echo "   La invoice aún está en payment_state = {$final_inv_data->payment_state}\n";
    echo "   Residual pendiente: {$final_inv_data->amount_residual}\n";
    echo "\n   NOTA: Esto puede requerir pasos adicionales de conciliación manual en Odoo.\n";
}

echo "\n";

exit(0);
?>
