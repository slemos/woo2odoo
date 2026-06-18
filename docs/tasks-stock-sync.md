# Tasks: Implementación Stock Sync (Odoo → WooCommerce)

Plan de referencia: `plan-stock-sync.md`

| Task | Descripción | Estado | Agente |
|------|-------------|--------|--------|
| S-1 | Clase `Woo2Odoo_Stock_Manager` | completado | haiku-s1 |
| S-2 | Settings (`odoo_import_update_stocks` + frecuencia) | completado | haiku-s2 |
| S-3 | Cron en `Woo2Odoo_Plugin` | completado | haiku-s3 |
| S-4 | Tests unitarios `OdooStockManagerTest.php` | completado | haiku-s4 |
| S-5 | Script prueba manual `tests/test-stock-sync.php` | completado | haiku-s5 |
| S-6 | Validación en staging ARM | fallido | haiku-s6 |

## Notas de implementación

- Usar `free_qty` (no `qty_available`) — descuenta reservas de SOs mayoristas activas
- Usar `wc_update_product_stock()` para actualizar stock en WC (no `update_post_meta` directo)
- Logging via `$this->client->log_info()` / `log_error()`
- Settings key: `Woo2Odoo-plugin-export` (mismo grupo que el resto del plugin)

## Resultado S-6

**Estado: FALLIDO**

### Acciones ejecutadas

1. Sincronización de archivos: OK (rsync completado, 363,728 bytes enviados)
2. Reinicio contenedor PHP: OK (infra-php-1 restarted y levantó correctamente)
3. Tests unitarios: FALLIDO
4. Script stock sync (GELCOL-100): FALLIDO
5. Script sync completo: FALLIDO

### Error detectado

Conflicto de namespace fatal en PHP que bloquea toda ejecución:

```
PHP Fatal error:  Cannot declare interface TransbankVendor\Psr\Http\Message\UriInterface, 
because the name is already in use in 
/var/www/html/wp-content/plugins/transbank-webpay-plus-rest/vendor-prefixed/psr/http-message/src/UriInterface.php 
on line 25
```

### Causa raíz

Hay una collision de namespace entre:
- Plugin: `transbank-webpay-plus-rest` 
- Librería: PSR HTTP Message Interface

El plugin Transbank tiene una versión vendor-prefixed de la librería PSR, pero hay otra copia sin prefijo que intenta declararse, causando fatal error. Esto impide la ejecución de cualquier script PHP en el contenedor.

### Impacto

No se pueden ejecutar tests unitarios ni scripts de validación de stock sync mientras este error persista. El contenedor PHP tiene un fallo crítico que afecta a toda ejecución de PHP.

### Próximos pasos necesarios

Requiere investigación de:
1. Por qué hay dos versiones de la librería PSR
2. Si el plugin Transbank necesita actualización
3. Si hay un conflict en composer.lock entre plugins
4. Considerar desactivar plugin Transbank temporalmente para validar woo2odoo, o resolver la collision
