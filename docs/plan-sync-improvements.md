# Plan: Mejoras de Sincronización woo2odoo

**Rama:** `feat/sync-guard-and-metabox`  
**Base:** `fix/guest-order-odoo-sync`  
**Fecha:** 2026-07-03

---

## Contexto

Incidente 2026-07-03: WC #17790 quedó con `_woo2odoo_sync_status=pending` porque existía un SO manual (S02469) con `origin=17790` en Odoo. La sincronización automática lo encontró, entró al `else` y crasheó con un bug stdClass (ya corregido en `fix/guest-order-odoo-sync`). Incluso con el bug corregido, si el SO existe y está activo, el sync intentaría agregar líneas a un pedido equivocado.

Adicionalmente, el metabox "Sincronización Odoo" solo muestra IDs almacenados en WC meta — sin reflejar el estado real en Odoo ni permitir navegar directamente.

---

## Fase 1 — Implementación actual (rama `feat/sync-guard-and-metabox`)

### Fix 1: Guard de SO activo en `order_sync()`

**Archivo:** `classes/Woo2Odoo_Order_Manager.php`

**Cambio:** Agregar filtro `('state', '!=', 'cancel')` a la búsqueda de SO existente.

**Comportamiento nuevo:**

| Resultado búsqueda | Acción |
|---|---|
| No encontrado | Crear SO nuevo (comportamiento actual) |
| Encontrado + `state = cancel` | Ignorar, crear SO nuevo (caso nuevo) |
| Encontrado + `state != cancel` | Bloquear sync + nota en pedido WC + log warning |

**Nota en pedido WC cuando bloqueado:**
```
Woo2Odoo: sincronización bloqueada — existe un pedido activo en Odoo 
(SO ID {id}, estado {state}). Cancela el SO en Odoo antes de re-sincronizar, 
o usa la acción "Re-sincronizar forzado" desde el metabox.
```

**Impacto:** Con este fix, el incidente de hoy se habría resuelto solo: S02469 cancelado → search_read no lo encuentra → sync crea S02487 correctamente, sin intervención manual.

---

### Fix 2: Links "Ver en Odoo" en metabox

**Archivo:** `classes/Woo2Odoo_Admin_Order_Metabox.php`

Reemplazar los IDs de texto plano por links directos a Odoo:

- SO: `{odoo_url}/odoo/sales/{so_id}`
- Boleta: `{odoo_url}/odoo/accounting/customer-invoices/{invoice_id}`
- Pago: no tiene URL directa en Odoo 17 — mostrar solo ID

La URL base se obtiene de `Woo2Odoo-plugin-connection.odoo_url`.

---

### Fix 3: Estado live desde Odoo en metabox

**Archivo:** `classes/Woo2Odoo_Admin_Order_Metabox.php`

Cuando el metabox se renderiza, consultar Odoo en tiempo real para mostrar:

| Campo | Fuente |
|---|---|
| SO state | `sale.order.state` |
| Invoice state | `account.move.state` |
| Payment state | `account.move.payment_state` |
| Sync status (WC) | `_woo2odoo_sync_status` meta |

**Cache:** transient por `order_id` con TTL 5 minutos (evita llamada Odoo en cada page load).

**Botón "Refrescar":** borra el transient y recarga la página (`?woo2odoo_refresh=1`).

**Formato visual:**
```
Sale Order     S02487 [Ver en Odoo ↗]    ● sale
Boleta         ID 12790 [Ver ↗]          ● posted
Pago           ID 14321                   ● in_payment
Sync WC        ✓ synced  (2026-07-03 13:09)
               [Refrescar desde Odoo]
```

---

## Fase 2 — Siguiente iteración (rama separada)

### Acciones de administración extendidas

| Acción | Descripción | Complejidad |
|---|---|---|
| Re-sincronizar forzado | Cancela SO activo en Odoo + flush cache + re-sync | Media |
| Cancelar en Odoo | Cancela SO + hijos desde WC admin | Media |
| Actualizar cliente en Odoo | Push billing/shipping WC → Odoo partner | Media |
| Pull datos cliente desde Odoo | Actualiza WC customer con datos Odoo | Alta |

### Panel de sync en lista de pedidos

Columna "Odoo" en `WooCommerce > Pedidos` con estado de sync (synced/pending/failed/never) y filtros. Acción masiva: re-sincronizar seleccionados.

### Retry automático de fallidos

Job cron cada hora: busca `_woo2odoo_sync_status = 'failed'` con más de 15 min de antigüedad y los reintenta (máx 3 intentos con backoff).

---

## Archivos modificados (Fase 1)

- `classes/Woo2Odoo_Order_Manager.php` — guard SO activo
- `classes/Woo2Odoo_Admin_Order_Metabox.php` — links + estado live + refresh
- `docs/plan-sync-improvements.md` — este archivo

---

## Estado de implementación

- [x] Rama `feat/sync-guard-and-metabox` creada
- [x] Fix 1: guard SO activo en `order_sync()` — commit `f321ce3`
- [x] Fix 2: links "Ver en Odoo" en metabox — commit `eb14cf4`
- [x] Fix 3: estado live desde Odoo en metabox — commit `eb14cf4`
- [x] Push a origin
- [x] Deploy a ARM prod (OPcache reloaded 2026-07-03)
- [ ] Tests manuales en WC admin (verificar metabox en pedido real)
- [ ] PR a `main`

## Notas de implementación

- El `else` del bloque `if(!$odoo_order)` en `order_sync()` es código muerto (el guard retorna antes), pero inofensivo. Cleanup en próximo PR.
- `fetch_live_status()` en el metabox usa `execute('sale.order', 'search_read', ...)` — sin pasar por la caché Redis del plugin, para garantizar datos frescos.
- El botón "Refrescar" recarga la página con `?woo2odoo_refresh=1` (sin AJAX). Simple y seguro.
