# Bug — Pedidos de invitado (guest checkout) no sincronizan a Odoo

- **Fecha:** 2026-06-23
- **Severidad:** ALTA (afecta a la mayoría de clientes reales, que compran sin crear cuenta)
- **Estado:** ✅ Corregido y verificado en producción (rama `fix/guest-order-odoo-sync`)

## Síntoma

Un pago real con WebPay (pedido WC #17743) quedó `processing` correctamente, pero **no se
creó nada en Odoo**: sin `_odoo_sale_order_id`, sin notas de woo2odoo. Log de woo2odoo:

```
INFO  User not found in Odoo, creating new   {"User Email":"slemos.satue@gmail.com"}
ERROR Error creating customer in Odoo        {"msg":"Customer data is empty"}
ERROR Error getting customer data            {"order_id":17743}
```

## Causa raíz

En `Woo2Odoo_Order_Manager::get_customer_data()`:

```php
$user = $order->get_user();              // false en pedidos de INVITADO
...
$customer_id = $this->create_or_update_customer( $user, null );  // recibe false
```

`create_or_update_customer()` espera un `WP_User` y lee todo desde `get_user_meta()`:

```php
if ( !$customer_data ) {   // $user === false  →  true
    log_error('Customer data is empty');
    return false;          // aborta toda la sincronización del pedido
}
```

Los pedidos de invitado no tienen `WP_User` (`$order->get_user() === false`), así que el
partner nunca se construye y `order_sync()` aborta. Los pedidos sincronizados que sí
funcionaban eran de **usuarios registrados** (los tests e2e siempre registran usuario),
por lo que este camino quedó sin cubrir.

Los datos SÍ están en el pedido (`$order->get_address('billing')` + meta `_billing_rut`);
solo se estaban leyendo desde el lugar equivocado (user meta).

## Fix

Se agrega un método `create_customer_from_order( $order, $customer_id )` que arma el mismo
payload de `res.partner` desde la dirección de facturación del pedido y el meta `_billing_rut`.
En `get_customer_data()` se ramifica según haya o no `WP_User`:

```php
$customer_id = $user
    ? $this->create_or_update_customer( $user, null )   // registrado (sin cambios)
    : $this->create_customer_from_order( $order, null ); // invitado (nuevo)
```

El camino de usuario registrado queda **intacto** (riesgo bajo: cambio aditivo).

## Verificación (producción, pedido real #17743)

Re-disparada la sync tras el fix (`order_sync(17743) => true`):

- Partner Odoo **2487**: `Sebastian Satue`, VAT `24167623-4`, Carlos Xii 120, Santiago, Chile ✓
- Sales Order **S02425** (id 2424)
- Boleta borrador **id 12277** ($9.590)
- Pago **PBNK1/2026/00270** (id 201, `in_process`), vinculado a la boleta, monto 9.590 ✓

Notas del pedido en WC confirman los 3 pasos (SO / boleta / pago).

## Pendiente / relacionado

- **Pedido #17741** (prueba previa con MercadoPago, ya reembolsado) sufrió el mismo bug:
  no sincronizó y su refund no pudo exportarse (`Original Odoo invoice not found`). Decidir
  si se sincroniza manualmente (creando SO+boleta+nota de crédito) o se deja como está por
  ser una prueba reembolsada.
- Falta probar el flujo de **refund** de #17743 (nota de crédito en Odoo) — Fase 8.
