# Planes ejecutados — archivo histórico

Planes y bugs ya resueltos. Se conservan como referencia de diseño y decisiones.
**No son documentación viva** — verificar contra el código actual.

| Documento | Qué fue | Estado |
|---|---|---|
| `plan-stock-sync.md` | Sincronización de stock Odoo → WC | ✅ Implementado — `Woo2Odoo_Stock_Manager` (PR #3/#4) |
| `bug-guest-order-no-sync-20260623.md` | Pedidos de invitado no sincronizaban a Odoo | ✅ Resuelto — `create_customer_from_order()` (PR #4) |

## Planes activos (NO archivados, en `docs/`)

- `plan-sync-improvements.md` — Fase 1 ✅ (guard + metabox + WP-CLI, PR #6); Fase 2 pendiente (re-sync forzado, cancelar en Odoo, panel de lista)

El historial de versiones vive en `CHANGELOG.md` (raíz del repo).
