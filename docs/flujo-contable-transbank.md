# Flujo contable Transbank — WC#18606

Pedido de prueba para documentar el flujo contable correcto en Odoo con pagos Transbank.

- **WC Order**: #18606
- **Cliente**: Benjamim Perez (partner 347)
- **Total WC**: 47.970 CLP
- **Depósito Transbank (neto)**: 47.010 CLP (≈ 2% comisión)
- **Fecha**: 2026-06-12

---

## Asientos involucrados

| Asiento | Tipo | Descripción |
|---------|------|-------------|
| BEL 007215 | Factura cliente (out_invoice) | Boleta generada por el plugin |
| PAGWC/2026/00001 | Asiento de diario (entry) | Pago de tránsito creado por el plugin |
| PBNK1/2026/00275 | Asiento de diario (entry) | Ingreso banco — depósito Transbank (creado manualmente por el contador) |

---

## Paso 1 — Estado inicial (reverted)
**Timestamp**: 2026-06-12 22:50:55 UTC

El usuario revirtió los asientos a estado previo después de detectar que el flujo del plugin no era correcto.

### BEL 007215 — Factura
| Campo | Valor |
|-------|-------|
| state | **draft** |
| payment_state | not_paid |
| amount_total | 47.970 |
| amount_residual | 47.970 |

**Líneas:**
| ID | Cuenta | Debe | Haber | Reconciliado |
|----|--------|------|-------|-------------|
| 64669 | 310115 Product Sales | — | 15.117 | NO |
| 64670 | 210710 VAT Tax Debit | — | 7.659 | NO |
| 64671 | 110310 Customers | 47.970 | — | **NO** (residual: 47.970) |
| 64672 | 310115 Product Sales | — | 12.597 | NO |
| 64673 | 310115 Product Sales | — | 12.597 | NO |

### PAGWC/2026/00001 — Pago tránsito (plugin)
| Campo | Valor |
|-------|-------|
| state | **draft** |
| journal | Pagos woocommerce (ID 28) |
| ref | Pedido WC#18606 |

**Líneas:**
| ID | Cuenta | Debe | Haber | Reconciliado |
|----|--------|------|-------|-------------|
| 64674 | 110111 Pagos woocommerce | 47.970 | — | NO (residual: 47.970) |
| 64675 | 110310 Customers | — | 47.970 | **NO** (residual: -47.970) |

### PBNK1/2026/00275 — Ingreso banco
| Campo | Valor |
|-------|-------|
| state | **posted** |
| journal | Bank |
| ref | 96689310-9 TRANSBAN |

**Líneas:**
| ID | Cuenta | Debe | Haber | Reconciliado |
|----|--------|------|-------|-------------|
| 64682 | 110101 Bank | 47.010 | — | NO (residual: 47.010) |
| 64683 | 110103 Bank Suspense Account | — | 47.010 | NO (residual: 0) |

### account.payment — Intentos anteriores (cancelados)
| ID | Nombre | State | Journal | Monto |
|----|--------|-------|---------|-------|
| 218 | — | canceled | Tránsito Transbank | 47.970 |
| 219 | — | canceled | Tránsito Transbank | 47.970 |
| 220 | PAY00004 | canceled | Pagos woocommerce | 47.970 |

> **Nota**: Los pagos 218–220 son los intentos fallidos del plugin. Quedaron cancelados sin generar asiento.

---

---

## Paso 2 — Creación manual del pago PAY00005
**Timestamp**: 2026-06-12 22:56 UTC

El usuario creó manualmente un `account.payment` desde el módulo Contabilidad de Odoo.

### PAY00005 — account.payment (ID 221)
| Campo | Valor |
|-------|-------|
| state | **in_process** |
| payment_type | inbound |
| partner_type | customer |
| journal | Pagos woocommerce (ID 28) |
| amount | 47.970 |
| partner | Benjamim Perez (ID 347) |
| **move_id** | **FALSE** ← sin asiento de diario |
| destination_account | 110310 Customers |
| reconciled_invoices | ninguna |

> **Observación clave**: PAY00005 está en `in_process` pero **no tiene `move_id`** — el journal entry NO fue creado.
> Esto confirma que l10n_cl bloquea la creación del asiento de diario para pagos de cliente en `in_process`,
> incluso con el journal PAGWC (`l10n_latam_use_documents=False`).
> El comportamiento es idéntico al que provocó el cambio a `account.move` directo en iteraciones anteriores.

### Estado de los demás asientos en este momento
| Asiento | state | payment_state | amount_residual |
|---------|-------|---------------|-----------------|
| BEL 007215 | draft | not_paid | 47.970 |
| PAGWC/2026/00001 | draft | — | — |
| PBNK1/2026/00275 | posted | — | — |

---

## Paso 3 — Invoice confirmada + pago PAY00006 vía botón "Pagar"
**Timestamp**: 2026-06-12 23:03 UTC

PAY00005 (creado manualmente desde "Pagos de cliente") **no pudo vincularse** a la invoice — incluso marcado como "Enviado" no apareció en el widget de créditos disponibles.

El usuario confirmó BEL 007215 (posted) y usó el botón **"Pagar"** de la invoice, que llama a `action_register_payment` sobre `account.move`. Esto generó PAY00006 y lo vinculó automáticamente a la invoice.

### PAY00006 — account.payment (ID 222)
| Campo | Valor |
|-------|-------|
| state | **in_process** |
| payment_type | inbound |
| partner_type | customer |
| journal | Pagos woocommerce (ID 28) — `payment_method_line_id`: Manual (Pagos woocommerce) |
| amount | 47.970 |
| partner | Benjamim Perez (ID 347) |
| memo | WC#18606 |
| **move_id** | **false** ← sin asiento de diario |
| invoice_ids | [12056] |
| reconciled_invoice_ids | [12056] |
| is_matched | false |
| is_reconciled | false |
| outstanding_account_id | false |

### BEL 007215 — Invoice tras aplicar PAY00006
| Campo | Valor |
|-------|-------|
| state | **posted** |
| **payment_state** | **in_payment** ← "En proceso de pago" ✓ |
| amount_total | 47.970 |
| amount_residual | 47.970 ← sin cambio (no hay asiento real) |
| matched_payment_ids | [222] |
| reconciled_payment_ids | [222] |

---

## Análisis del flujo correcto

### ¿Por qué el botón "Pagar" funciona y el pago manual no?

`action_register_payment` (botón "Pagar" en la invoice) usa el wizard `account.payment.register` que:
1. Crea el `account.payment` con `invoice_ids = [id_invoice]`
2. Llama internamente a `action_create_payments` con contexto `active_ids = [invoice_id]`
3. Esto vincula el pago a la invoice y activa el campo `payment_state = in_payment`

Crear un `account.payment` directamente (sin el wizard ni el contexto `active_ids`) NO vincula el pago a la invoice → `payment_state` no cambia.

### Comportamiento de l10n_cl: `in_process` sin asiento de diario

Con la localización chilena, los pagos de cliente creados vía `action_register_payment` entran a estado `in_process` **sin generar journal entry** (`move_id = false`). El `payment_state = in_payment` de la invoice se basa en la relación `account.payment → invoice_ids`, no en reconciliación real de cuentas.

Esto es por diseño: Odoo CL espera que el asiento se cree cuando el documento electrónico sea confirmado por SII. Para pagos Transbank (no SII), el asiento se creará en el paso de conciliación bancaria.

### Flujo contable correcto identificado

```
1. WC Order pagado
   → Plugin crea account.payment vía action_register_payment (con invoice_id en contexto)
   → Invoice: "En proceso de pago" (payment_state = in_payment)
   → No hay journal entry todavía (move_id = false)

2. Transbank deposita en banco (neto, menos comisión 2%)
   → Odoo crea PBNK1/XXXX automáticamente (Dr Bank, Cr Bank Suspense)
   → Contador usa módulo "Extractos Bancarios" para conciliar

3. Conciliación bancaria
   → PBNK1 Bank Suspense se reconcilia con PAY00006
   → Se genera el journal entry del pago
   → Invoice: "Pagado" (payment_state = paid)
   → Diferencia (comisión) se registra en cuenta de gastos
```

### Lo que el plugin debe cambiar

En vez de crear `account.move` directamente, el plugin debe:
1. Crear `account.payment.register` con `journal_id`, `amount`, `payment_date`, `communication`
2. Llamar `action_create_payments` con contexto `active_ids = [invoice_id], active_model = 'account.move'`

Esto replica exactamente el flujo del botón "Pagar" de la invoice.

---

## Paso 4 — No fue posible conciliar PBNK1/2026/00275 con PAY00006
**Timestamp**: 2026-06-12 23:17 UTC

El usuario intentó conciliar el asiento bancario PBNK1/2026/00275 (ingreso Transbank) contra PAY00006, pero no fue posible. En cambio creó un **nuevo pago PBNK1/2026/00270 directamente desde la invoice**, usando el journal Banco.

### PBNK1/2026/00270 — account.payment (ID 223) — journal Banco
| Campo | Valor |
|-------|-------|
| state | in_process |
| journal | **Banco (ID 14)** |
| amount | 47.970 |
| **move_id** | **[12059, "PBNK1/2026/00270 (WC#18606)"]** ← ¡Tiene journal entry! |
| outstanding_account_id | **[236, "110104 Outstanding Receipts"]** |
| destination_account_id | [47, "110310 Clientes"] |
| is_reconciled | **true** |
| invoice_ids | [12056] |
| reconciled_invoice_ids | [12056] |

### account.move 12059 — Asiento del pago
| Campo | Valor |
|-------|-------|
| state | **posted** |
| move_type | entry |
| journal | Banco (ID 14) |
| **l10n_latam_use_documents** | **false** |
| has_reconciled_entries | **true** |
| origin_payment_id | [223, "PBNK1/2026/00270"] |
| line_ids | [64708, 64709] |

---

## Análisis comparativo — Por qué Banco crea asiento y PAGWC no

| Campo | PAY00006 (PAGWC journal 28) | PBNK1/2026/00270 (Banco journal 14) |
|-------|----------------------------|-------------------------------------|
| move_id | **false** | **[12059, posted]** |
| outstanding_account_id | **false** | **[236, 110104 Outstanding Receipts]** |
| is_reconciled | false | true |
| journal entry creado | NO | SÍ |

**El diferenciador es `outstanding_receipts_account_id` del journal:**
- **Banco (ID 14)**: tiene `outstanding_receipts_account_id = 110104` → Odoo sabe qué cuenta debitar → crea el asiento Dr 110104 / Cr 110310
- **PAGWC (ID 28)**: NO tiene `outstanding_receipts_account_id` configurado → `outstanding_account_id = false` → Odoo no puede crear el asiento

Esto es independiente de `l10n_latam_use_documents` (ambos tienen `False`). El problema es la falta de configuración del journal PAGWC.

## Flujo correcto final identificado

```
account.move 12059 (PBNK1/2026/00270):
  Dr 110104 Outstanding Receipts  47.970  ← open (pendiente de conciliación bancaria)
  Cr 110310 Customers             47.970  ← reconciled con invoice BEL 007215

Invoice BEL 007215:
  Dr 110310 Customers  47.970  ← reconciled con move 12059
  → payment_state = in_payment o paid
```

Cuando Transbank deposita el neto (47.010):
1. Extracto bancario: Dr 110101 Bank / Cr 110103 Suspense (47.010)
2. Bank reconciliation: match 110103 Suspense vs 110104 Outstanding Receipts
3. Diferencia 960 = comisión → Dr Comisiones / Cr 110104 (o ajuste en conciliación)
4. Invoice → "Pagado"

## Fix necesario en el plugin

**Opción A (recomendada):** Configurar `outstanding_receipts_account_id` en el journal PAGWC (ID 28).
- Establecer a 110111 (Pagos woocommerce) como cuenta de tránsito
- Entonces el plugin puede usar `action_register_payment` con journal PAGWC
- Genera Dr 110111 / Cr 110310 → invoice "in_payment"
- Contador reconcilia 110111 contra depósito banco

**Opción B:** Usar directamente el journal Banco para el pago del plugin.
- Plugin llama `action_register_payment` con journal Banco (ID 14)
- Genera Dr 110104 / Cr 110310 → invoice "in_payment"
- Contador usa extracto bancario → reconciliar 110104 vs depósito

**Opción A** preserva la separación contable Transbank/Banco usando 110111 como tránsito.
**Opción B** es más simple pero mezcla el pago WC directamente en la cuenta bancaria Outstanding Receipts.

---

## Paso 5 — Estado final y causa raíz confirmada
**Timestamp**: 2026-06-12 23:22 UTC

### move 12059 — Líneas del asiento de PBNK1/2026/00270
| ID | Cuenta | Debe | Haber | Residual | Reconciliado |
|----|--------|------|-------|---------|-------------|
| 64708 | 110104 Outstanding Receipts | 47.970 | — | 47.970 | **NO** (open) |
| 64709 | 110310 Customers | — | 47.970 | 0 | **SI** (reconciled con invoice) |

### BEL 007215 — Estado final tras PBNK1/2026/00270
| Campo | Valor |
|-------|-------|
| state | posted |
| **payment_state** | **in_payment** |
| amount_residual | 0 |
| line 64671 (110310) | reconciled: **SI** |

La invoice muestra "En proceso de pago" con residual = 0 → estado correcto para el flujo del contador.
La línea 64708 (Dr 110104, 47.970) queda abierta → disponible para conciliación con el extracto bancario.

---

## Causa raíz exacta — account.payment.method.line

### Comparación de payment method lines

| PML ID | Journal | payment_account_id |
|--------|---------|-------------------|
| **19** | Banco (ID 14) | **110104 Outstanding Receipts** |
| **25** | Pagos woocommerce (ID 28) | **FALSE** |

`payment_account_id` en `account.payment.method.line` es el campo que determina la cuenta de tránsito del pago:
- Si está configurado → Odoo crea el asiento `Dr payment_account / Cr receivable` → `move_id` apunta al asiento
- Si es `false` → Odoo no puede crear el asiento → `move_id = false` → sin journal entry

El journal PAGWC fue creado vía API sin configurar este campo. El journal Banco lo tiene configurado a 110104.

## Fix definitivo implementado en el plugin

El plugin fue actualizado para crear `account.payment` directamente (en lugar de `account.move`), usando el journal Banco (ID 14) que tiene `outstanding_receipts_account_id = 110104`. Esto genera el asiento Dr 110104 / Cr 110310 y vincula el pago a la invoice mediante `invoice_ids`.

```php
// Woo2Odoo_Order_Manager::create_outstanding_payment()
$payment_id = $this->client->create_record( 'account.payment', [
    'payment_type' => 'inbound',
    'partner_type' => 'customer',
    'partner_id'   => $partner_id,
    'journal_id'   => $journal_id,   // 14 = Banco (Scotiabank)
    'amount'       => $payment_info['amount'],
    'date'         => $payment_info['date'],
    'memo'         => $payment_info['memo'],
    'currency_id'  => 44,            // CLP
    'invoice_ids'  => [[4, $invoice_id, 0]],  // many2many add
]);
$this->client->execute( 'account.payment', 'action_post', [[$payment_id]] );
```

---

## Paso 6 — Validación en producción con plugin actualizado

**Timestamp**: 2026-06-13 00:51 UTC
**WC Order**: #18607 (dev.pink-mask.cl — Hostinger)
**Cliente**: fernandagarcia@gmail.com (ID=1089)
**Total**: 57.980 CLP
**Productos**: UVLMP-002 x1 + BLDRGL-516 x1
**Gateway**: Transbank Webpay (simulado, AUTH AUTO56616)

### Resultado del sync automático WC → Odoo

| Elemento | ID | Nombre | Estado |
|---|---|---|---|
| Invoice | 12067 | (boleta tipo 39) | `draft` / `not_paid` |
| Payment | 226 | PBNK1/2026/00273 | `in_process` ✅ |
| Journal entry | 12066 | PBNK1/2026/00273 (Pedido WC#18607) | `posted` ✅ |

### Verificación del account.payment 226

| Campo | Valor |
|---|---|
| state | **in_process** ✅ |
| journal | Banco (ID 14) — prefijo PBNK1 |
| amount | 57.980 CLP |
| move_id | PBNK1/2026/00273 ✅ (journal entry creado) |
| memo | Pedido WC#18607 |
| is_reconciled | no (pendiente conciliación bancaria) |

### Asiento contable generado (move 12066)

| Cuenta | Debe | Haber | Estado |
|---|---|---|---|
| 110104 Outstanding Receipts | 57.980 | — | open (pendiente conciliación bancaria) |
| 110310 Customers | — | 57.980 | reconciled con invoice 12067 |

> **El pago aparece en "Pagos de cliente" de Odoo** (prefijo PBNK1) ✅
> **El journal entry se creó correctamente** ✅
> **La invoice queda en draft** — el contador debe confirmarla (ver flujo operativo abajo)

---

## Flujo operativo actual — Automático vs Manual

### Lo que hace el plugin automáticamente (por cada orden WC pagada)

1. **Crea Sale Order** en Odoo (estado `sale`)
2. **Crea Invoice (boleta)** tipo 39 Electronic Receipt — en estado **`draft`**
3. **Crea account.payment** en journal Banco → `PBNK1/XXXX`
   - Estado: `in_process`
   - Asiento: Dr 110104 Outstanding Receipts / Cr 110310 Customers
   - Vinculado a la invoice vía `invoice_ids`
4. Guarda en meta WC: `_woo2odoo_invoice_id`, `_woo2odoo_payment_id`

### Lo que debe hacer el contador manualmente

#### Por cada orden recibida

**Paso 1 — Confirmar la boleta**
- Ir a Contabilidad → Clientes → Facturas
- Abrir la boleta en estado "Borrador"
- Revisar líneas, cliente y monto
- Hacer clic en **"Confirmar"** (`action_post`)
- La invoice pasa a `posted` y `payment_state = in_payment` (En proceso de pago) ✅

#### Cuando Transbank deposita (típicamente D+1 o D+2)

**Paso 2 — Registrar extracto bancario**
- Ir a Contabilidad → Banco → Scotiabank
- Importar extracto o crear línea manual:
  - Monto: total depositado por Transbank (neto, ~98% del total acumulado)
  - Referencia: número de lote Transbank

**Paso 3 — Conciliar extracto contra Outstanding Receipts**
- En el extracto bancario, Odoo sugiere automáticamente las líneas Dr 110104 pendientes
- Seleccionar las líneas que corresponden al depósito
- Si hay diferencia por comisión (2%), registrar en cuenta "Comisiones Bancarias" (110299)
- Confirmar conciliación
- Las invoices vinculadas cambian a `payment_state = paid` ✅

### Flujo contable completo

```
WC Order pagada  →  Plugin (automático)
  ├─ Sale Order (sale)
  ├─ Invoice draft (boleta tipo 39)
  └─ account.payment PBNK1/XXXX (in_process)
       Dr 110104 Outstanding Receipts  [total CLP]  ← open
       Cr 110310 Customers             [total CLP]  ← reconciled con invoice

Contador confirma boleta  (manual, por orden)
  └─ Invoice: posted / in_payment ("En proceso de pago")

Transbank deposita neto (total - 2%)  →  Extracto bancario
  Dr 110101 Bank (Scotiabank)         [neto CLP]
  Cr 110103 Bank Suspense             [neto CLP]

Contador concilia extracto  (manual, por lote de depósito)
  ├─ Match: 110103 Suspense ↔ 110104 Outstanding Receipts
  ├─ Diferencia comisión → Dr 110299 Comisiones Bancarias
  └─ Invoices → payment_state = paid ("Pagado") ✅
```

> **Validación E2E confirmada — 2026-06-13**: El contador ejecutó los pasos manuales completos (confirmar boleta, registrar extracto, conciliar contra Outstanding Receipts). El flujo funciona según lo planificado.
