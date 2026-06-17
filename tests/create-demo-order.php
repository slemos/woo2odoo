<?php
/**
 * Crea una orden de prueba con pago simulado (Transbank o MercadoPago).
 *
 * Uso:
 *   wp --path=/ruta/wordpress eval-file create-demo-order.php -- [opciones]
 *
 * Opciones:
 *   --customer-id=N    ID de usuario WP registrado (requerido; guest orders fallan al sincronizar)
 *   --sku=SKU1,SKU2    SKUs separados por coma (default: primeros 2 productos del catálogo)
 *   --qty=1,2          Cantidades por SKU en el mismo orden (default: 1 por cada SKU)
 *   --gateway=tbk|mp   Gateway de pago a simular: tbk=Transbank, mp=MercadoPago (default: tbk)
 *   --label=TEXTO      Etiqueta para identificar la orden en los logs (default: demo)
 *
 * Ejemplos:
 *   wp eval-file create-demo-order.php -- --customer-id=1089
 *   wp eval-file create-demo-order.php -- --customer-id=42 --sku=PROD-001,PROD-002 --qty=2,1
 *   wp eval-file create-demo-order.php -- --customer-id=42 --gateway=mp --label=test-mp
 */

// ── Parsear argumentos ────────────────────────────────────────────────────────

$args        = array_slice( $argv ?? array(), 1 );
$customer_id = 0;
$skus        = array();
$qtys        = array();
$gateway     = 'tbk';
$label       = 'demo';

foreach ( $args as $arg ) {
    if ( preg_match( '/^--customer-id=(\d+)$/', $arg, $m ) ) {
        $customer_id = (int) $m[1];
    } elseif ( preg_match( '/^--sku=(.+)$/', $arg, $m ) ) {
        $skus = array_map( 'trim', explode( ',', $m[1] ) );
    } elseif ( preg_match( '/^--qty=(.+)$/', $arg, $m ) ) {
        $qtys = array_map( 'intval', explode( ',', $m[1] ) );
    } elseif ( preg_match( '/^--gateway=(tbk|mp)$/', $arg, $m ) ) {
        $gateway = $m[1];
    } elseif ( preg_match( '/^--label=(.+)$/', $arg, $m ) ) {
        $label = $m[1];
    }
}

if ( ! $customer_id ) {
    echo "ERROR: --customer-id es requerido (las órdenes de invitado no sincronizan con Odoo).\n";
    echo "Uso: wp eval-file create-demo-order.php -- --customer-id=N\n";
    exit( 1 );
}

// ── Validar usuario ───────────────────────────────────────────────────────────

$user = get_userdata( $customer_id );
if ( ! $user ) {
    echo "ERROR: No existe el usuario WP con ID=$customer_id.\n";
    exit( 1 );
}

// ── Resolver productos ────────────────────────────────────────────────────────

$items = array();

if ( ! empty( $skus ) ) {
    foreach ( $skus as $i => $sku ) {
        $pid = wc_get_product_id_by_sku( $sku );
        if ( ! $pid ) {
            echo "ERROR: SKU '$sku' no encontrado.\n";
            exit( 1 );
        }
        $items[] = array( 'product' => wc_get_product( $pid ), 'qty' => $qtys[ $i ] ?? 1 );
    }
} else {
    // Tomar los primeros 2 productos publicados del catálogo
    $posts = get_posts( array(
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => 2,
    ) );
    if ( count( $posts ) < 1 ) {
        echo "ERROR: No hay productos publicados en la tienda.\n";
        exit( 1 );
    }
    foreach ( $posts as $post ) {
        $items[] = array( 'product' => wc_get_product( $post->ID ), 'qty' => 1 );
    }
}

// ── Crear la orden ────────────────────────────────────────────────────────────

$order = wc_create_order( array( 'customer_id' => $customer_id ) );

foreach ( $items as $item ) {
    $order->add_product( $item['product'], $item['qty'] );
    echo sprintf( "[%s] + %s x%d\n", $label, $item['product']->get_sku() ?: $item['product']->get_name(), $item['qty'] );
}

// Copiar dirección de facturación y envío desde el perfil WP del cliente
$billing_fields = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' );
foreach ( $billing_fields as $field ) {
    $value = get_user_meta( $customer_id, 'billing_' . $field, true );
    if ( $value ) {
        call_user_func( array( $order, 'set_billing_' . $field ), $value );
    }
}

$shipping_fields = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );
foreach ( $shipping_fields as $field ) {
    $value = get_user_meta( $customer_id, 'shipping_' . $field, true );
    if ( ! $value ) {
        $value = get_user_meta( $customer_id, 'billing_' . $field, true );
    }
    if ( $value ) {
        call_user_func( array( $order, 'set_shipping_' . $field ), $value );
    }
}

$order->set_billing_email( $user->user_email );

// ── Configurar gateway y simular metadatos de pago ───────────────────────────

if ( 'mp' === $gateway ) {
    $order->set_payment_method( 'woo-mercado-pago-basic' );
    $order->set_payment_method_title( 'MercadoPago' );
    $order->calculate_totals();
    $order->save();

    $order_id   = $order->get_id();
    $total      = $order->get_total();
    $mp_id      = rand( 1000000000, 9999999999 );
    $paid_date  = date( 'Y-m-d H:i:s' );

    $order->update_meta_data( '_Mercado_Pago_Payment_IDs', (string) $mp_id );
    $order->update_meta_data( '_paid_date',                $paid_date );
    $order->save();

    echo sprintf( "[%s] Gateway    : MercadoPago\n", $label );
    echo sprintf( "[%s] MP ID      : %s\n", $label, $mp_id );
    echo sprintf( "[%s] Paid date  : %s\n", $label, $paid_date );

} else {
    $order->set_payment_method( 'transbank_webpay_plus_rest' );
    $order->set_payment_method_title( 'Webpay Plus' );
    $order->calculate_totals();
    $order->save();

    $order_id  = $order->get_id();
    $total     = $order->get_total();
    $auth_code = 'AUTO' . rand( 10000, 99999 );
    $tbk_date  = date( 'd-m-Y H:i:s P' );

    $order->update_meta_data( 'transactionStatus', 'Autorizada' );
    $order->update_meta_data( 'amount',            (string) $total );
    $order->update_meta_data( 'authorizationCode', $auth_code );
    $order->update_meta_data( 'transactionDate',   $tbk_date );
    $order->save();

    echo sprintf( "[%s] Gateway    : Transbank Webpay\n", $label );
    echo sprintf( "[%s] Auth code  : %s\n", $label, $auth_code );
    echo sprintf( "[%s] TBK fecha  : %s\n", $label, $tbk_date );
}

echo sprintf( "[%s] Orden      : #%d\n", $label, $order_id );
echo sprintf( "[%s] Total      : %s CLP\n", $label, number_format( $total, 0, ',', '.' ) );
echo sprintf( "[%s] Cliente    : %s (ID=%d)\n", $label, $user->user_email, $customer_id );

// ── Disparar sync a Odoo vía cambio de estado ─────────────────────────────────

$order->update_status( 'processing', "[$label] Orden demo woo2odoo" );

$order      = wc_get_order( $order_id );
$so_id      = $order->get_meta( '_odoo_sale_order_id' );
$inv_id     = $order->get_meta( '_woo2odoo_invoice_id' );
$payment_id = $order->get_meta( '_woo2odoo_payment_id' );

echo sprintf( "[%s] Estado     : %s\n", $label, $order->get_status() );
echo sprintf( "[%s] Odoo SO    : %s\n", $label, $so_id      ?: '(pendiente - ver wc-logs)' );
echo sprintf( "[%s] Odoo INV   : %s\n", $label, $inv_id     ?: '(pendiente - ver wc-logs)' );
echo sprintf( "[%s] Odoo PAY   : %s\n", $label, $payment_id ?: '(pendiente - ver wc-logs)' );

// ── Verificar estado en Odoo ──────────────────────────────────────────────────

if ( class_exists( 'Woo2Odoo\Woo2Odoo_ClientFactory' ) ) {
    try {
        $jrpc = Woo2Odoo\Woo2Odoo_ClientFactory::instance()->get_client();

        // SO
        if ( $so_id ) {
            $so = $jrpc->execute( 'sale.order', 'read', array( array( (int) $so_id ), array( 'name', 'state' ) ) );
            if ( ! empty( $so ) ) {
                echo sprintf( "[%s] SO Odoo    : %s (state=%s)\n", $label, $so[0]->name, $so[0]->state );
            }
        }

        // Boleta/Factura
        if ( $inv_id ) {
            $inv = $jrpc->execute( 'account.move', 'read', array( array( (int) $inv_id ), array( 'name', 'state', 'payment_state', 'amount_residual', 'l10n_latam_document_type_id' ) ) );
            if ( ! empty( $inv ) ) {
                $doc_type = ! empty( $inv[0]->l10n_latam_document_type_id ) ? $inv[0]->l10n_latam_document_type_id[1] : '-';
                echo sprintf( "[%s] INV Odoo   : %s (state=%s, pago=%s, residual=%.0f, tipo=%s)\n",
                    $label, $inv[0]->name, $inv[0]->state, $inv[0]->payment_state,
                    $inv[0]->amount_residual, $doc_type );
            }
        }

        // Pago (account.payment)
        if ( $payment_id ) {
            $pay = $jrpc->execute( 'account.payment', 'read', array(
                array( (int) $payment_id ),
                array( 'name', 'state', 'amount', 'move_id', 'is_reconciled' ),
            ) );
            if ( ! empty( $pay ) ) {
                $move_name = ! empty( $pay[0]->move_id ) ? $pay[0]->move_id[1] : '(sin asiento)';
                echo sprintf( "[%s] PAY Odoo   : %s (state=%s, amount=%.0f, move=%s, reconciled=%s)\n",
                    $label, $pay[0]->name, $pay[0]->state, $pay[0]->amount,
                    $move_name, $pay[0]->is_reconciled ? 'si' : 'no' );
            }
        } else {
            // Buscar account.payment por memo con WC# si no tenemos el ID guardado
            $export_opts = get_option( 'Woo2Odoo-plugin-export', array() );
            $pay_journal = isset( $export_opts['payment_journal_id'] ) ? (int) $export_opts['payment_journal_id'] : 0;
            if ( $pay_journal ) {
                $pays = $jrpc->execute( 'account.payment', 'search_read', array(
                    array(
                        array( 'journal_id', '=', $pay_journal ),
                        array( 'memo', 'like', 'WC#' . $order_id ),
                    ),
                    array( 'id', 'name', 'state', 'amount', 'move_id', 'is_reconciled' ),
                ) );
                if ( ! empty( $pays ) ) {
                    foreach ( $pays as $p ) {
                        $move_name = ! empty( $p->move_id ) ? $p->move_id[1] : '(sin asiento)';
                        echo sprintf( "[%s] PAY Odoo   : %s (state=%s, amount=%.0f, move=%s, reconciled=%s)\n",
                            $label, $p->name, $p->state, $p->amount,
                            $move_name, $p->is_reconciled ? 'si' : 'no' );
                    }
                } else {
                    echo sprintf( "[%s] PAY Odoo   : no encontrado en journal %d (ver wc-logs)\n", $label, $pay_journal );
                }
            }
        }
    } catch ( Exception $e ) {
        echo sprintf( "[%s] Odoo check : ERROR — %s\n", $label, $e->getMessage() );
    }
}

echo sprintf( "\nORDER_ID=%d TOTAL=%s GATEWAY=%s\n", $order_id, $total, $gateway );
