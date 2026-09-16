# Plan: Mejoras de Sincronización woo2odoo

**Updated:** 2026-07-11

---

## Phase 1 — COMPLETE (deployed to prod ARM, 2026-07-04)

All three fixes shipped in `feat/sync-guard-and-metabox` (PR #6, merged 2026-07-04) and are live in `infra-php-1`.

**Fix 1 — SO guard in `order_sync()`** (`Woo2Odoo_Order_Manager.php`)
- Searches for existing SO with `('state', '!=', 'cancel')`.
- If active SO found: **links** IDs in WC meta (`_odoo_sale_order_id`, `_woo2odoo_invoice_id`, `_woo2odoo_payment_id`), adds order note, sets `status=synced`. Does NOT block.
- Cancelled SOs: ignored → creates a new one.
- Context: incident 2026-07-03 — WC #17790 stuck at `pending` because manual SO S02469 (cancelled) existed; the `else` branch crashed with stdClass bug.

**Fix 2 — `↗` links in metabox** (`Woo2Odoo_Admin_Order_Metabox.php`)
- SO: `{odoo_url}/odoo/sales/{so_id}`
- Invoice: `{odoo_url}/odoo/accounting/customer-invoices/{invoice_id}`
- Payment: ID only (no direct URL in Odoo 17/18)

**Fix 3 — Live Odoo status in metabox**
- On render: queries Odoo for SO state, invoice state, payment state. Cached in transient (TTL 5min).
- "Refrescar" button: clears transient + `?woo2odoo_refresh=1` reload.
- Graceful degradation if Odoo unreachable.

---

## Phase 2 — Backlog (next iteration, separate branch)

### Extended admin actions

| Action | Description | Complexity |
|---|---|---|
| Re-sincronizar forzado | Cancel active SO in Odoo + flush transient + re-sync | Medium |
| Cancelar en Odoo | Cancel SO + children from WC admin | Medium |
| Actualizar cliente en Odoo | Push billing/shipping WC → Odoo partner | Medium |
| Pull datos cliente desde Odoo | Update WC customer from Odoo | High |

### Order list sync panel

Column "Odoo" in `WooCommerce > Pedidos` with sync state (synced/pending/failed/never) + filters. Bulk action: re-sync selected.

### Auto-retry for failed orders

Hourly job: find `_woo2odoo_sync_status = 'failed'` older than 15 min → retry (max 3 attempts with backoff).

---

## Notes

- The `else` block after the SO guard in `order_sync()` is dead code (guard returns before it). Cleanup in next PR.
- `fetch_live_status()` uses `execute('sale.order', 'search_read', ...)` directly, bypassing Redis cache, for fresh data.
