# Tasks: Implementación Stock Sync (Odoo → WooCommerce)

Plan de referencia: `plan-stock-sync.md`

| Task | Descripción | Estado | Agente |
|------|-------------|--------|--------|
| S-1 | Clase `Woo2Odoo_Stock_Manager` | completado | haiku-s1 |
| S-2 | Settings (`odoo_import_update_stocks` + frecuencia) | completado | haiku-s2 |
| S-3 | Cron en `Woo2Odoo_Plugin` | completado | haiku-s3 |
| S-4 | Tests unitarios `OdooStockManagerTest.php` | completado | haiku-s4 |
| S-5 | Script prueba manual `tests/test-stock-sync.php` | completado | haiku-s5 |
| S-6 | Validación en staging ARM | completado | sonnet |

## Notas de implementación

- Usar `free_qty` (no `qty_available`) — descuenta reservas de SOs mayoristas activas
- Usar `wc_update_product_stock()` para actualizar stock en WC (no `update_post_meta` directo)
- Logging via `$this->client->log_info()` / `log_error()`
- Settings key: `Woo2Odoo-plugin-export` (mismo grupo que el resto del plugin)

## Resultado S-6

**Estado: COMPLETADO**

### Acciones ejecutadas

1. rsync al ARM: OK (363,728 bytes)
2. Reinicio infra-php-1: OK
3. Error Transbank namespace collision bloqueaba ejecución → recreado mu-plugin `phpunit-filter.php` (había sido eliminado en recreado previo del contenedor)
4. Bug en test-stock-sync.php: `wc_get_product_stock()` no existe → corregido a `$product->get_stock_quantity()` 
5. Tests de stock en OdooStockManagerTest: 2 tests refactorizados (usaban `sync_all()` con `wc_get_products()` real → devolvía 477 productos del sitio) → refactorizados a testear `sync_product()` directamente

### Resultados

- **Tests unitarios**: 35 tests, 0 errores nuevos. 2 fallos pre-existentes en OrderManagerTest (testOrderSync, testAddOrderLineItems — no relacionados con stock sync)
- **Script SKU específico** (`--sku=GELCOL-100`): `free_qty = 297` desde Odoo, stock WC actualizado de 383 → 297 ✅
- **Script sync completo**: `updated=477, not_found=0, errors=0` ✅

### Archivos adicionales modificados en S-6

- `/srv/pinkmask/wp-content/mu-plugins/phpunit-filter.php` — recreado (filtra active_plugins a WC+woo2odoo cuando `PHPUNIT_TESTING=1`)
- `tests/test-stock-sync.php` — fix `wc_get_product_stock()` → `get_stock_quantity()`
- `tests/test-stockmanager.php` — 2 tests refactorizados para no usar `sync_all()` con WC real
