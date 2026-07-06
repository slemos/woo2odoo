# Woo2Odoo

WooCommerce → Odoo integration plugin. Syncs orders, partners, invoices and payments automatically via JSON-RPC 2.0.

> **Optimizado para Chile.** El plugin implementa los requerimientos de la localización chilena de Odoo (`l10n_cl`): RUT como identificador tributario, emisión de Boleta Electrónica (tipo 39) o Factura Electrónica (tipo 33) según el tipo de comprador, y mapeo de regiones al estándar de `res.country.state` de Odoo. Todos los flujos de pago soportados (Transbank WebpayPlus, MercadoPago y Transferencia Bancaria) se diseñaron para el mercado local chileno.

---

## ¿Qué hace?

Cuando un pedido WooCommerce pasa a estado **processing**, el plugin ejecuta automáticamente:

1. **Sale Order en Odoo** — con todos los line items, envío, notas y referencias al pedido WC
2. **Partner** — busca o crea el socio en Odoo usando RUT (VAT), email o dirección de billing. Funciona tanto para clientes registrados como para compras de invitados (guest checkout)
3. **Boleta o Factura Electrónica** (draft) — tipo 39 (Boleta) por defecto, tipo 33 (Factura) si el cliente marcó "requiero factura" en el checkout
4. **Pago en Odoo** — registra `account.payment` en el journal de banco configurado; soporta Transbank WebpayPlus, MercadoPago y Transferencia Bancaria (BACS)

Todo queda trazado: los IDs de Odoo (SO, factura, pago) se guardan como meta del pedido WC.

---

## Características

### Sincronización de pedidos
- Sync automático al pasar a `processing` (o `on-hold` para BACS pendiente de confirmación)
- **Sync guard** — si ya existe un Sale Order activo en Odoo con el mismo origin, lo vincula en vez de crear un duplicado
- **Soporte de invitados** — crea el partner en Odoo desde los datos de billing cuando no hay usuario WP
- Sync de reembolsos — crea notas de crédito en Odoo al registrar un reembolso WC
- Errores de Odoo surficiados como notas internas del pedido WC (visible en WP Admin)
- Captura `\Throwable` — errores PHP en mitad de un sync dejan el pedido en `failed` sin SO huérfana en Odoo

### Pagos soportados
| Gateway | Comportamiento |
|---------|---------------|
| Transbank WebpayPlus | Pago creado al llegar a `processing`; fecha extraída del token Transbank |
| MercadoPago | Pago creado al llegar a `processing`; fallback a `get_date_paid()` |
| Transferencia Bancaria (BACS) | Pago creado solo cuando el pedido pasa a `processing` (no en `on-hold`); el SO se confirma si está en borrador |

### Admin
- **Tab "Estado Odoo"** en WooCommerce Settings — tabla de pedidos con Sale Order / Boleta / Pago y estado de sync, filtros por estado, paginación y links directos a Odoo
- **Sincronización por fila** (botón AJAX) y **sincronización en background** con Action Scheduler para grupos de pedidos
- **Metabox live** en el pedido — estado en tiempo real desde Odoo (SO, invoice, payment) con badges de color, transient 5 min, botón "Refrescar"
- **Aviso global** cuando hay pedidos con error de sync (descartable 12 h por usuario)

### Stock
- Importa `free_qty` desde Odoo a WooCommerce según un cron configurable

### WP-CLI
```bash
# Sincronizar un pedido específico
wp woo2odoo sync 12345

# Reintentar pedidos fallidos (máx 20)
wp woo2odoo sync --status=failed --limit=20

# Preview de pedidos nunca sincronizados
wp woo2odoo sync --status=never --wc-status=processing --dry-run

# Backfill: vincular pedidos viejos a SO/boleta existentes en Odoo (sin crear nada)
wp woo2odoo backfill --before=2026-01-01 --dry-run
wp woo2odoo backfill --missing-invoice   # pedidos con SO pero sin boleta vinculada
```

### Técnico
- **JSON-RPC 2.0** vía [`winternet-studio/odoo-jsonrpc-client`](https://github.com/winternet-studio/odoo-jsonrpc-client) + Guzzle — compatible con PHP 8.3 (el XML-RPC de Odoo 18 está bloqueado)
- **HPOS compatible** — todas las lecturas y escrituras de meta de pedidos usan WC CRUD (`$order->get_meta()`, `$order->update_meta_data() + save()`); compatible con WooCommerce High-Performance Order Storage activo
- Caché de `search_read` **opt-in** — por defecto todas las lecturas son frescas; solo se cachean lookups inmutables (SKU→id, país/región)
- Validación pre-flight: pedidos con líneas de cantidad ≤ 0 fallan limpiamente antes de tocar Odoo

---

## Requisitos

| Componente | Versión mínima |
|-----------|---------------|
| WordPress | 6.5+ |
| WooCommerce | 8.0+ (HPOS requiere 7.1+) |
| Odoo | 16+ (probado en Odoo SaaS 17/18) |
| PHP | 8.0+ (8.3 recomendado) |

---

## Instalación

1. Sube la carpeta del plugin a `/wp-content/plugins/woo2odoo/`
2. Actívalo en **Plugins**
3. Ve a **WooCommerce → Ajustes → Woo2Odoo Plugin**

### Configuración requerida

**Pestaña Connection:**
- **Odoo URL** — URL raíz de tu instancia, ej. `https://tu-instancia.odoo.com`
- **Database** — nombre de la base de datos Odoo
- **Username** — email del usuario Odoo
- **API Key** — API key de Odoo (distinta de la contraseña web; generarla en Odoo → Ajustes → Técnico → Claves de API)

**Pestaña Export:**
- **Invoice Journal ID** — ID numérico del journal de facturas de cliente en Odoo
- **Payment Journal ID** — ID numérico del journal bancario para pagos entrantes

---

## Desarrollo

### Dependencias

```bash
composer install
npm install
```

### Entorno local (wp-env)

```bash
# Iniciar WordPress local con el plugin activo
npm run wp-env start

# Con Xdebug
npm run wp-env start -- --xdebug=profile,trace,debug
```

El entorno local corre en `http://localhost:8888`.

### Tests (PHPUnit portable)

La suite de integración corre contra el `wp-env` local sin credenciales de producción:

```bash
# Correr todos los tests
npm run wp-env run -- --env-cwd=wp-content/plugins/woo2odoo wordpress ./vendor/bin/phpunit

# O directamente si wp-env está corriendo
npx wp-env run wordpress php vendor/bin/phpunit
```

Los tests verifican el flujo completo: creación de order manager, sincronización de pedidos BACS, validación de pagos, y comportamiento del guard de SOs activos.

CI: GitHub Actions corre `PHPUnit (wp-env)` y `Plugin Check` en cada PR.

### Estructura del plugin

```
woo2odoo/
├── woo2odoo.php                    # Plugin header, bootstrap
├── classes/
│   ├── Woo2Odoo_Plugin.php         # Registro de hooks y servicios
│   ├── Woo2Odoo_Order_Manager.php  # Lógica de sync: SO, partner, invoice, pago
│   ├── Woo2Odoo_Client.php         # Cliente JSON-RPC hacia Odoo
│   ├── Woo2Odoo_ClientFactory.php  # Factory con caché de cliente
│   ├── Woo2Odoo_Admin_Order_Metabox.php  # Metabox live en pedido
│   ├── Woo2Odoo_Sync_Status_Tab.php      # Tab "Estado Odoo" con Action Scheduler
│   ├── Woo2Odoo_Stock_Manager.php  # Sync de stock desde Odoo
│   ├── Woo2Odoo_Plugin_Admin.php   # Páginas de admin
│   ├── Woo2Odoo_Plugin_Settings.php      # Tabs de configuración
│   └── Woo2Odoo_CLI.php            # Comandos WP-CLI
├── tests/
│   ├── bootstrap.php               # Bootstrap wp-env
│   └── wpunit-order-manager.php    # Tests de integración
├── vendor/                         # Dependencias Composer
├── CHANGELOG.md
└── readme.txt                      # readme.txt para el repositorio WP.org
```

---

## Notas de localización Chile

- **RUT** se guarda como `vat` en el partner de Odoo. El método `format_rut()` normaliza el formato (cuerpo sin puntos, guión, dígito verificador preservando `K`)
- **Boleta Electrónica (tipo 39)** es el documento por defecto. Si el cliente marcó "requiero factura" en el checkout, se emite **Factura Electrónica (tipo 33)**
- Las **regiones chilenas** se mapean al `res.country.state` de Odoo usando códigos ISO 3166-2:CL
- El plugin requiere que el módulo `l10n_cl` esté instalado en Odoo y que el diario de facturas esté configurado para documentos electrónicos chilenos

---

## Changelog

Ver [CHANGELOG.md](CHANGELOG.md) para el historial completo.

| Versión | Resumen |
|---------|---------|
| 1.5.1 | Fix HPOS: `get_post_meta → wc_get_order()->get_meta()` en creación de factura; eliminada función muerta `create_invoice()` |
| 1.5.0 | Sync de pago BACS a Odoo; errores de Odoo como notas WC; suite PHPUnit portable (wp-env); CI GitHub Actions |
| 1.4.0 | Tab "Estado Odoo"; sync en background con Action Scheduler; aviso global de errores |
| 1.3.x | Guard de SO activo; metabox live; WP-CLI; fix RUT con K; caché opt-in; fix guest orders |
| 1.0.0 | Release inicial: SO, partner, boleta draft, reembolsos, l10n Chile, JSON-RPC, PHP 8.3 |

---

## Licencia

GPLv3 — ver [LICENSE](https://www.gnu.org/licenses/gpl-3.0.html).
