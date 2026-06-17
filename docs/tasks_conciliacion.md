# Tareas de Implementación para Flujo de Conciliación Transbank

## Estado del Plugin

### Resumen de avance (2026-06-13)

| Fase | Estado | Detalle |
|------|--------|---------|
| Fase 1: Creación de payment | ✅ **COMPLETO** | `account.payment` en journal Banco, asiento Dr 110104 / Cr 110310 |
| Fase 2: Asiento depósito Transbank | 🔴 Pendiente | Requiere integración con extracto bancario |
| Fase 3: Conciliación automática | 🔴 Pendiente | Pendiente fase 2 |
| Fase 4: UI en WordPress | 🔴 Pendiente | — |
| Fase 5: Testing y QA | 🟡 Parcial | Validado manualmente en producción (WC#18607) |
| Fase 6: Documentación | 🟡 Parcial | `flujo-contable-transbank.md` actualizado |

**Validado en producción**: WC#18607 (57.980 CLP) → PBNK1/2026/00273 (in_process, move_id presente) ✅

---

## Checklist de Implementación

### Fase 1: Creación Automática de Asientos (✅ COMPLETO)

#### 1.1 Hook al Confirmar Pago Transbank
- [x] Hook: `woocommerce_order_status_processing` en `Woo2Odoo_Order_Manager::order_sync()`
  - Gateway detectado: `transbank_webpay_plus_rest` (meta `transactionStatus = 'Autorizada'`)
  - Se ejecuta cuando WC cambia orden a `processing`

#### 1.2 Crear Asiento de Pago en Odoo
- [x] Obtener invoice ID de Odoo (meta: `_woo2odoo_invoice_id`)
- [x] Obtener monto y fecha de pago desde `get_payment_info_from_wc_order()`
- [x] Llamar JSON-RPC `account.payment.create()`:
  ```php
  [
      'payment_type' => 'inbound',
      'partner_type' => 'customer',
      'partner_id'   => $partner_id,  // commercial_partner_id resuelto
      'journal_id'   => $journal_id,  // configurable, default Banco (ID 14)
      'amount'       => $payment_info['amount'],
      'date'         => $payment_info['date'],
      'memo'         => $payment_info['memo'],
      'currency_id'  => 44,  // CLP
      'invoice_ids'  => [[4, $invoice_id, 0]]  // many2many add
  ]
  ```
- [x] Confirmar pago: `account.payment.action_post([$payment_id])`
- [x] Verificar via `move_id` (l10n_cl usa `in_process`, no `posted`)
- [x] Guardar payment ID en meta: `_woo2odoo_payment_id`
- [x] Logs en wc-logs con estado y move_id

#### 1.3 Error Handling
- [x] Log de error si `payment_journal_id` no configurado
- [x] Log de error si `move_id = false` tras action_post (journal mal configurado)
- [ ] Reintentos automáticos en caso de timeout
- [ ] Notificar admin por email si falla

#### Configuración requerida en Odoo
- `payment_journal_id` = **14** (journal Banco/Scotiabank)
- El journal debe tener `outstanding_receipts_account_id` configurado (110104)
- **NO usar journal PAGWC (ID 28)** — no tiene `payment_account_id` configurado → no crea asiento

---

### Fase 2: Simulación de Depósito Transbank (🔴 PENDIENTE)

#### 2.1 Datos de Depósito
- [ ] Crear tabla/meta para registros de depósito Transbank
- [ ] Campos:
  - `deposit_date` (fecha de depósito)
  - `net_amount` (98% del total)
  - `commission` (2% del total)
  - `transaction_ids` (órdenes incluidas)
  - `odoo_move_id` (asiento creado)
- [ ] Ubicación sugerida: Custom post type `woo2odoo_transbank_deposit` en WordPress

#### 2.2 Crear Asiento de Depósito en Odoo
- [ ] Cuando se procesa un depósito (manual o automático):
  - [ ] Crear account.move en journal Banco (ID 14):
    ```php
    [
        'move_type' => 'entry',
        'journal_id' => 14,
        'date' => $deposit_date,
        'ref' => 'TBK Deposit - ' . $deposit_id,
        'company_id' => 1,
        'line_ids' => [
            [0, 0, [
                'account_id' => 233,  // Bank 110101
                'debit' => $net_amount,
                'credit' => 0
            ]],
            [0, 0, [
                'account_id' => 235,  // Suspense 110103
                'debit' => 0,
                'credit' => $net_amount
            ]]
        ]
    ]
    ```
  - [ ] Confirmar: `account.move.action_post([$move_id])`
  - [ ] Guardar move ID en meta del depósito
- [ ] Crear comisión contable (si aplica):
  - [ ] Dr [110299] Bank Fees / Cr [110103] Suspense
  - [ ] Monto: commission = total * 0.02

#### 2.3 Integración con Transbank API
- [ ] Obtener datos de depósito desde API Transbank
- [ ] Automatizar creación de depósito diario/semanal
- [ ] Campos a mapear:
  - `transactionDate` → `deposit_date`
  - `amount` → neto (98%)
  - `responseCode` → validación

---

### Fase 3: Conciliación Automática (🔴 PENDIENTE)

#### 3.1 Matching de Líneas Contables
- [ ] Función `reconcile_transbank_payment()`:
  - [ ] Entrada: payment_id (Odoo) y deposit_move_id (Odoo)
  - [ ] Obtener línea Dr Outstanding Receipts del payment
  - [ ] Obtener línea Cr Suspense del deposit
  - [ ] Verificar que los montos coincidan
  - [ ] Llamar: `account.move.line.reconcile([line_ids])`

#### 3.2 Manejo de Discrepancias
- [ ] Si monto de pago != monto de depósito (por comisión):
  - [ ] Crear asiento de ajuste para diferencia
  - [ ] Dr [110299] Bank Fees / Cr [110103] Suspense
- [ ] Si líneas no coinciden:
  - [ ] Log de warning
  - [ ] Marcar como "pendiente revisión manual"
  - [ ] Notificar contador

#### 3.3 Validaciones
- [ ] Validar que invoice existe en Odoo
- [ ] Validar que payment está en estado 'posted'
- [ ] Validar que deposit está en estado 'posted'
- [ ] Validar que partner es el mismo

---

### Fase 4: Interfaz de Usuario (🔴 PENDIENTE)

#### 4.1 Página de Depósitos Transbank
- [ ] Crear página admin en WordPress:
  - [ ] Listar depósitos con estado
  - [ ] Mostrar transacciones vinculadas
  - [ ] Botón "Crear Asiento en Odoo"
  - [ ] Botón "Conciliar"
  - [ ] Logs de conciliación

#### 4.2 Meta Box en Orden WC
- [ ] Mostrar estado de pago en Odoo:
  - [ ] Payment ID
  - [ ] Payment State
  - [ ] Linked Invoice (si existe)
  - [ ] Deposit Status
- [ ] Botón "Crear Payment en Odoo" (manual)

#### 4.3 Reportes
- [ ] Reporte: Órdenes sin Payment creado
- [ ] Reporte: Depósitos pendientes de conciliación
- [ ] Reporte: Discrepancias de monto

---

### Fase 5: Testing y QA (🔴 PENDIENTE)

#### 5.1 Tests Unitarios
- [ ] Test: Crear payment correctamente
- [ ] Test: Obtener datos de pago Transbank
- [ ] Test: Crear asiento de depósito
- [ ] Test: Reconciliar movimientos
- [ ] Test: Error handling y reintentos

#### 5.2 Tests de Integración
- [ ] Flujo completo: WC order → Odoo payment → Odoo deposit → Reconciled
- [ ] Test con montos diferentes (producto variantes)
- [ ] Test con múltiples órdenes en un depósito
- [ ] Test con comisiones y ajustes

#### 5.3 Casos Edge
- [ ] Orden con monto 0 (producto sin precio)
- [ ] Orden con múltiples líneas
- [ ] Depósito parcial (no toda la orden)
- [ ] Reintento después de fallo
- [ ] Pago rechazado en Transbank

---

### Fase 6: Documentación (🔴 PENDIENTE)

#### 6.1 Documentación de Usuario
- [ ] Guía: Cómo configurar journal Banco en Odoo
- [ ] Guía: Cómo vincular órdenes WC con invoices Odoo
- [ ] Guía: Cómo procesar un depósito Transbank
- [ ] FAQ: Preguntas comunes

#### 6.2 Documentación Técnica
- [ ] API del plugin (métodos públicos)
- [ ] Hooks disponibles
- [ ] Filtros para customización
- [ ] Ejemplos de extensión

#### 6.3 Troubleshooting
- [ ] Qué hacer si un payment no se crea
- [ ] Qué hacer si la conciliación falla
- [ ] Cómo revisar logs
- [ ] Cómo contactar soporte

---

## Priorización de Tareas

### Completado
1. ✅ Validar flujo contable en Odoo (test-conciliacion-transbank-v2.php)
2. ✅ Implementar `create_outstanding_payment()` con `account.payment`
3. ✅ Validar en producción (WC#18607, dev.pink-mask.cl)
4. ✅ Documentar flujo automático vs manual para el contador
5. ✅ **Validación E2E completa por el contador** (2026-06-13): boleta confirmada, extracto bancario registrado, conciliación Outstanding Receipts vs depósito Transbank — flujo funciona según lo planificado

### Pendiente — Corto Plazo
5. 🔴 Reintentos automáticos y notificación admin por email (Fase 1.3)
6. 🔴 Investigar por qué `_odoo_sale_order_id` no se guarda en meta (bug menor)
7. 🔴 Meta box en orden WC con estado de pago Odoo (Fase 4.2)

### Pendiente — Mediano Plazo
8. 🔴 Implementar creación de asiento depósito Transbank (Fase 2)
9. 🔴 Implementar reconciliación automática Outstanding Receipts (Fase 3)
10. 🔴 Tabla/registro de depósitos Transbank en WordPress (Fase 2.1)

### Pendiente — Largo Plazo
11. 🔴 Página de admin para depósitos Transbank (Fase 4.1)
12. 🔴 Reportes de conciliación pendiente (Fase 4.3)
13. 🔴 Tests de integración E2E (Fase 5.2)

---

## Dependencias Externas

- **Odoo API**: Validado ✅
- **Transbank API**: Requiere integración (obtener monto + comisión)
- **WC Hooks**: Estándar, disponibles ✅
- **WordPress Meta**: Disponible ✅

---

## Recursos y Referencias

### Script de Test
- Ubicación: `/home/ubuntu/dev/woo2odoo/tests/test-conciliacion-transbank.php`
- Ejecutor: `wp eval-file`
- Resultado: ✅ EXITOSO

### Resultado del Test
- Documento: `/home/ubuntu/dev/woo2odoo/docs/test-conciliacion-resultado.md`
- Datos: IDs generados, estados finales, análisis

### Configuración Odoo
- Company ID: 1
- Journal Banco: 14 (Scotiabank)
- Account Outstanding Receipts: 236
- Account Bank: 233
- Account Suspense: 235
- Account Customers: 47 (reconcile=True)

---

## Métricas de Éxito

- [ ] Todos los pagos Transbank confirmados en WC se replican en Odoo
- [ ] Asientos de depósito se crean automáticamente
- [ ] Conciliación ocurre sin errores
- [ ] 100% de transacciones Transbank están reconciliadas
- [ ] Cero discrepancias de monto
- [ ] Dashboard muestra estado de pagos en tiempo real

---

## Notas Adicionales

### Decisiones de Diseño

1. **Payment vs Move**: Usar `account.payment` (no crear moves directamente) porque:
   - Odoo lo genera con estructura correcta automáticamente
   - Integra mejor con journal y reconciliación
   - Estándar en procesos contables Odoo

2. **Journal Banco (ID 14)**: Hardcodeado en la primera fase, pero hacer configurable después

3. **Cuentas Contables**: Hardcodeadas inicialmente, implementar lookup en siguiente fase

### Riesgos Identificados

- ⚠️ Si invoice no existe en Odoo, crear payment fallará (validar antes)
- ⚠️ Si partner no existe, falso positivo de reconciliación (validar antes)
- ⚠️ Transbank comisión puede variar (hardcodeado al 2% ahora)
- ⚠️ Sincronización de horarios entre WC y Odoo puede causar desfases

### Mitigación

- Implementar pre-validaciones antes de crear payment
- Logs exhaustivos de cada operación
- Reintentos automáticos con backoff
- Notificaciones al admin si algo falla

---

## Autor y Fecha

- **Test ejecutado**: 2026-06-12 23:46:25 UTC
- **Sistema**: Plugin Woo2Odoo
- **Entorno**: WordPress + WooCommerce + Odoo (JSON-RPC)
