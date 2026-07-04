# Plan: Sincronización de Stock Odoo → WooCommerce

**Fecha:** 2026-06-18  
**Estado:** Pendiente de implementación

---

## Contexto

wc2odoo tenía un mecanismo de sincronización de stock vía cron: consultaba `product.product` en Odoo y actualizaba `_stock`, `_manage_stock` y `_stock_status` en WooCommerce. woo2odoo no tiene esta funcionalidad.

---

## Objetivo

Implementar sincronización periódica de stock desde Odoo hacia WooCommerce, activable desde el panel de administración del plugin con frecuencia configurable.

---

## Diseño

### Flujo principal

```
[WP Cron] → odoo_process_import_update_stocks
               ↓
         Woo2Odoo_Stock_Manager::sync_all()
               ↓
         Obtiene todos los WC products con SKU
               ↓
         Por cada SKU → searchRead('product.product', where=[('default_code','=',sku)])
               ↓
         free_qty → wc_update_product_stock()
               ↓
         Log resultado (éxito / no encontrado / error)
```

### Mapeo de campos Odoo → WooCommerce

| Campo Odoo | Campo WC | Notas |
|---|---|---|
| `free_qty` | `_stock` (cantidad) | Stock no reservado por SOs activas (mayorista o cualquier canal) |
| `free_qty > 0` | `_stock_status` = `instock` | |
| `free_qty <= 0` | `_stock_status` = `outofstock` | |
| — | `_manage_stock` = `yes` | Siempre activar gestión de stock |

> **Nota:** Se usa `free_qty` en lugar de `qty_available` deliberadamente. `free_qty` descuenta automáticamente las reservas de SOs mayoristas confirmadas en Odoo, evitando que WooCommerce venda stock ya comprometido. Esta decisión viene de wc2odoo (`class-wc2odoo-functions.php:263`) donde ya estaba implementada así.

### Modelo Odoo

- Modelo: `product.product`
- Filtro: `[('default_code', '=', $sku)]`
- Campos: `['qty_available', 'default_code', 'name']`
- Límite: 1 resultado por SKU

---

## Archivos a crear / modificar

### Nuevo: `classes/Woo2Odoo_Stock_Manager.php`

Clase responsable de toda la lógica de sincronización:

- `__construct(Woo2Odoo_Client $client)` — recibe el cliente ya autenticado
- `sync_all(): array` — itera todos los WC products con SKU, retorna stats `['updated'=>N, 'not_found'=>N, 'errors'=>N]`
- `sync_product(WC_Product $product): bool` — sincroniza un producto individual, retorna éxito/fallo
- `fetch_odoo_qty(string $sku): float|null` — consulta Odoo, retorna qty o null si no encontrado

### Modificar: `classes/Woo2Odoo_Plugin.php`

- Registrar hook de cron: `add_action('odoo_process_import_update_stocks', [$stock_manager, 'sync_all'])`
- Al activar plugin: programar cron si el setting está activado
- Al desactivar plugin: limpiar el cron hook

### Modificar: `classes/Woo2Odoo_Plugin_Settings.php`

Agregar dos opciones al grupo de settings existente:

| Setting | Tipo | Descripción |
|---|---|---|
| `odoo_import_update_stocks` | checkbox (yes/no) | Activar sincronización periódica de stock |
| `odoo_import_stocks_frequency` | select | Frecuencia: `hourly`, `twicedaily`, `daily` |

### Modificar: `classes/Woo2Odoo_Plugin_Admin.php`

- Agregar sección "Sincronización de Stock" en el panel admin con los dos campos anteriores
- Al guardar settings, re-programar o limpiar el cron según el valor guardado

---

## Tests

### Nuevo: `tests/OdooStockManagerTest.php`

Tests unitarios con mocks del cliente:

- `testSyncAllUpdatesStockForKnownSku` — SKU encontrado en Odoo → `wc_update_product_stock` llamado con qty correcta
- `testSyncAllSkipsProductWithoutSku` — producto sin SKU → no hace llamada a Odoo
- `testSyncAllHandlesOdooNotFound` — Odoo retorna array vacío → stock WC no cambia, contabiliza `not_found`
- `testSyncAllHandlesOdooException` — Odoo lanza excepción → contabiliza `errors`, no rompe el loop
- `testFetchOdooQtyReturnNullWhenNotFound`

### Script manual: `tests/test-stock-sync.php`

Script CLI para probar la sincronización en el contenedor sin necesidad de esperar el cron.

---

## Decisiones de diseño

1. **Clase separada** (`Woo2Odoo_Stock_Manager`) en lugar de agregar métodos a `Woo2Odoo_Order_Manager` — mantiene responsabilidades separadas.

2. **Sincronización por SKU** (`default_code`) — mismo campo que usa wc2odoo; es el identificador natural entre WC y Odoo.

3. **Solo Odoo → WC** (unidireccional) — el stock maestro es Odoo. WC no exporta stock hacia Odoo (se descuenta automáticamente al confirmar la Sale Order).

4. **`wc_update_product_stock()`** en lugar de `update_post_meta` directo — usa la API de WC para disparar los hooks correctos (notificaciones de stock bajo, etc.).

5. **Logging** — usar `$this->client->log_info()` / `log_error()` igual que el resto del plugin.

6. **Productos variables** — en primera versión, solo productos simples (iteramos variaciones como productos independientes ya que cada una tiene su propio SKU en WC).

---

## Tareas

### TASK S-1: Clase `Woo2Odoo_Stock_Manager`
- Crear `classes/Woo2Odoo_Stock_Manager.php`
- Implementar `fetch_odoo_qty()` usando `searchRead`
- Implementar `sync_product()`
- Implementar `sync_all()` con stats de retorno

### TASK S-2: Settings
- Agregar `odoo_import_update_stocks` y `odoo_import_stocks_frequency` en `Woo2Odoo_Plugin_Settings.php`
- Agregar sección en `Woo2Odoo_Plugin_Admin.php`

### TASK S-3: Cron
- Registrar hook en `Woo2Odoo_Plugin.php`
- Programar/limpiar cron al activar/desactivar plugin y al guardar settings

### TASK S-4: Tests unitarios
- Crear `tests/OdooStockManagerTest.php` con los 5 casos descritos arriba

### TASK S-5: Script de prueba manual
- Crear `tests/test-stock-sync.php` para validar en contenedor ARM

### TASK S-6: Validación en staging ARM
- Ejecutar `test-stock-sync.php` en `infra-php-1`
- Verificar que los productos en WC toman el stock de Odoo correctamente

---

## Criterio de aceptación

- Cron `odoo_process_import_update_stocks` se programa al activar el setting
- Al ejecutarse, los productos WC cuyo SKU exista en Odoo quedan con `_stock` = `qty_available` de Odoo
- Productos sin SKU o no encontrados en Odoo no son modificados
- Log refleja cuántos productos fueron actualizados / no encontrados / con error
- Tests S-4 pasan en verde
