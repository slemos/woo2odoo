# Conciliación Bancaria — Diseño woo2odoo

**Fecha:** 2026-06-11 | **Actualizado:** 2026-06-13
**Contexto:** Decisiones de diseño para el proceso de conciliación bancaria entre WooCommerce (Transbank/MercadoPago) y Odoo.

> **Estado (2026-06-13):** Decisiones tomadas y validadas E2E. Ver resumen al final del documento.

---

## Mecanismo elegido: Outstanding Payment (pago en espera)

El plugin no confirma facturas ni registra cobros automáticamente. El flujo se divide en lo que hace el plugin y lo que hace el ejecutivo en Odoo.

---

## Lo que hace el plugin automáticamente (al recibir la orden)

1. Crea el `sale.order` en Odoo
2. Crea la `account.move` con `move_type=out_invoice` en estado **draft**
3. **Si el pago fue por Transbank o MercadoPago**, crea además un `account.payment` y lo postea (`action_post`) con:
   - `payment_type: inbound`, `partner_type: customer`
   - Monto: el capturado por el gateway
   - Journal: el configurado en `payment_journal_id` de los settings del plugin
   - Memo trazable:
     - Transbank: `TBK-{authorizationCode} / WC#{order_id}`
     - MercadoPago: `MP-{payment_ids} / WC#{order_id}`
   - Fecha: fecha real de la transacción del gateway
4. **Si el pago fue por BACS** (transferencia bancaria), no se crea pago automático.

Al postear el `account.payment`, Odoo genera internamente:
```
Dr.  Outstanding Receipts (cuenta transitoria del journal)
Cr.  Accounts Receivable (partner)
```

---

## Pasos del ejecutivo en Odoo

### Paso 1 — Confirmar la factura

1. Abrir la boleta/factura (estado **Draft**)
2. Clic en **Confirm** → queda en estado *Posted*

### Paso 2 — Reconciliar factura con el Outstanding Payment

1. En la factura confirmada aparece la sección **"Outstanding Credits"** con el pago que el plugin creó
2. Clic en **Add** → Odoo reconcilia los Accounts Receivable de ambos documentos
3. Factura pasa a estado **"In Payment"** (pago posteado pero aún no reconciliado con el banco)

### Paso 3 — Reconciliar el movimiento bancario

Al importar el extracto bancario, aparece la línea del abono de Transbank/MercadoPago.

En **Accounting → Bank → Reconciliation**:
1. Seleccionar la línea del banco
2. Odoo sugiere el match automáticamente (por monto, fecha, y memo con el código de autorización)
3. Confirmar → Odoo genera:
```
Dr.  Cuenta Bancaria (journal del extracto)
Cr.  Outstanding Receipts (limpia la cuenta transitoria)
```
4. Factura pasa de *In Payment* a **Paid**

---

## Caso especial: abonos agrupados de Transbank

Transbank **no abona transacción por transacción** — liquida en un solo depósito el total del día, descontando comisiones:
```
TRANSBANK ABONO  →  $180,340  (suma de N transacciones del día)
```

### Opciones para manejarlo

**Opción A — Reconciliación manual agrupada**
1. Abrir la línea del banco en la vista de reconciliación
2. Clic en **"Add a line"** para ir agregando los `account.payment` individuales hasta que la suma cuadre
3. Si hay diferencia de comisiones, agregar una línea manual contra cuenta de gastos bancarios

**Opción B — Journal de tránsito Transbank (recomendada)**
- Configurar `payment_journal_id` del plugin apuntando a un **journal de tránsito Transbank** (tipo "Bank", cuenta transitoria), NO al journal del banco operacional
- Flujo:
  1. Cada WC order → 1 payment en journal Transbank (tránsito)
  2. Al reconciliar factura → Outstanding Credits del journal tránsito
  3. Al llegar el abono global del banco → 1 línea de extracto reconcilia contra N payments acumulados en el journal tránsito
- **Ventaja**: cada factura queda individualmente conciliada; el abono bancario global se cuadra contra el saldo acumulado del tránsito sin ambigüedad

---

## Configuración requerida en el plugin

| Setting | Descripción |
|---------|-------------|
| `invoiceJournal` | ID del journal de ventas (boletas/facturas tipo 39/33) |
| `payment_journal_id` | ID del journal de tránsito Transbank/MP (NO el banco operacional) |

Si `payment_journal_id` no está configurado, el plugin loguea un warning y omite la creación del pago → el ejecutivo debe registrarlo manualmente.

---

## Para BACS (transferencia bancaria)

1. Cliente envía comprobante y se verifica el depósito en el banco
2. Ejecutivo abre la factura (Draft) → **Confirm** → *Posted*
3. **Register Payment** desde la factura → selecciona journal de banco, monto y fecha del depósito
4. Odoo reconcilia → *Paid*

---

## Decisiones tomadas (2026-06-13)

- [x] **Opción elegida**: Opción B — journal `Bank (Scotiabank, ID 14)` directamente, sin journal de tránsito separado. El journal Banco tiene `outstanding_receipts_account_id = 110104` configurado, lo que permite generar el asiento Dr 110104 / Cr 110310 automáticamente.
- [x] `payment_journal_id = 14` configurado en Hostinger staging y validado en producción
- [x] Comportamiento con comisiones confirmado: la diferencia de ~2% se registra manualmente durante la conciliación del extracto bancario (cuenta 410325 Comisiones Transbank)

Ver flujo completo validado en: `docs/flujo-contable-transbank.md`
