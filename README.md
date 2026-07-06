# Woo2Odoo

WooCommerce → Odoo integration plugin. Syncs orders, partners, invoices and payments automatically via JSON-RPC 2.0.

> **Optimized for Chile.** This plugin implements the requirements of the Chilean Odoo localization (`l10n_cl`): RUT as the tax identifier, issuance of Boleta Electrónica (type 39) or Factura Electrónica (type 33) depending on the buyer type, and region mapping to the `res.country.state` standard in Odoo. All supported payment flows (Transbank WebpayPlus, MercadoPago, and Bank Transfer) are designed for the Chilean local market.

---

## What does it do?

When a WooCommerce order moves to **processing** status, the plugin automatically:

1. **Creates a Sale Order in Odoo** — with all line items, shipping, notes and references to the WC order
2. **Finds or creates the Odoo partner** — using RUT (VAT), email or billing address; works for both registered customers and guest checkouts
3. **Issues a draft Boleta or Factura Electrónica** — type 39 (Boleta) by default, type 33 (Factura) if the customer requested an invoice at checkout
4. **Registers the payment in Odoo** — creates an `account.payment` in the configured bank journal; supports Transbank WebpayPlus, MercadoPago and Bank Transfer (BACS)

All Odoo record IDs (Sale Order, invoice, payment) are stored as WC order meta for full traceability.

---

## Features

### Order sync
- Automatic sync on transition to `processing` (or `on-hold` for BACS pending confirmation)
- **Sync guard** — if an active Sale Order already exists in Odoo with the same origin, it links to it instead of creating a duplicate
- **Guest checkout support** — builds the Odoo partner from billing address when no WP user exists
- **Refund sync** — creates credit notes in Odoo when a WC refund is registered
- Odoo errors surfaced as internal WC order notes (visible in WP Admin)
- Catches `\Throwable` — PHP errors mid-sync set the order to `failed` without leaving an orphaned SO in Odoo

### Supported payment gateways
| Gateway | Behavior |
|---------|----------|
| Transbank WebpayPlus | Payment created on `processing`; date extracted from the Transbank token |
| MercadoPago | Payment created on `processing`; falls back to `get_date_paid()` |
| Bank Transfer (BACS) | Payment created only when the order reaches `processing` (not `on-hold`); confirms the SO if it is still in draft |

### Admin
- **"Odoo Status" tab** in WooCommerce Settings — order list with Sale Order / Invoice / Payment meta and sync status, state filters, pagination, and direct links to Odoo
- **Per-row sync** (AJAX button) and **background bulk sync** via Action Scheduler
- **Live metabox** on the order screen — real-time Odoo status (SO name, invoice state, payment state) with color badges, 5-min transient cache and a "Refresh" button
- **Global admin notice** when orders fail to sync (dismissible per user for 12 h)

### Stock
- Imports `free_qty` from Odoo to WooCommerce on a configurable cron schedule

### WP-CLI
```bash
# Sync a specific order
wp woo2odoo sync 12345

# Retry all failed orders (max 20)
wp woo2odoo sync --status=failed --limit=20

# Preview never-synced orders without touching anything
wp woo2odoo sync --status=never --wc-status=processing --dry-run

# Backfill: link old orders to existing Odoo SOs/invoices (no records created)
wp woo2odoo backfill --before=2026-01-01 --dry-run
wp woo2odoo backfill --missing-invoice   # orders with an SO but no linked invoice
```

### Technical
- **JSON-RPC 2.0** via [`winternet-studio/odoo-jsonrpc-client`](https://github.com/winternet-studio/odoo-jsonrpc-client) + Guzzle — PHP 8.3 compatible (Odoo 18 blocks XML-RPC remote calls)
- **HPOS compatible** — all order meta reads and writes use WC CRUD (`$order->get_meta()`, `$order->update_meta_data() + save()`); fully compatible with WooCommerce High-Performance Order Storage
- **Opt-in `search_read` cache** — all reads are fresh by default; only immutable lookups (SKU→id, country/region) are cached
- **Pre-flight validation** — orders with line items of quantity ≤ 0 fail cleanly before touching Odoo

---

## Requirements

| Component | Minimum version |
|-----------|----------------|
| WordPress | 6.5+ |
| WooCommerce | 8.0+ (HPOS requires 7.1+) |
| Odoo | 16+ (tested on Odoo SaaS 17/18) |
| PHP | 8.0+ (8.3 recommended) |

---

## Installation

1. Upload the plugin folder to `/wp-content/plugins/woo2odoo/`
2. Activate it via the **Plugins** screen
3. Go to **WooCommerce → Settings → Woo2Odoo Plugin**

### Required settings

**Connection tab:**
- **Odoo URL** — root URL of your Odoo instance, e.g. `https://your-instance.odoo.com`
- **Database** — Odoo database name
- **Username** — Odoo user email
- **API Key** — Odoo API key (different from the web login password; generate one under Odoo Settings → Technical → API Keys)

**Export tab:**
- **Invoice Journal ID** — numeric ID of the Odoo customer invoice journal
- **Payment Journal ID** — numeric ID of the Odoo bank journal used for incoming payments

---

## Development

### Dependencies

```bash
composer install
npm install
```

### Local environment (wp-env)

```bash
# Start local WordPress with the plugin active
npm run wp-env start

# With Xdebug
npm run wp-env start -- --xdebug=profile,trace,debug
```

The local environment runs at `http://localhost:8888`.

### Tests (portable PHPUnit)

The integration suite runs against the local `wp-env` environment without any production credentials:

```bash
# Run all tests
npm run wp-env run -- --env-cwd=wp-content/plugins/woo2odoo wordpress ./vendor/bin/phpunit

# Or directly if wp-env is already running
npx wp-env run wordpress php vendor/bin/phpunit
```

Tests cover the full sync flow: order manager instantiation, BACS order sync, payment validation, and active SO guard behavior.

CI: GitHub Actions runs `PHPUnit (wp-env)` and `Plugin Check` on every PR.

### Plugin structure

```
woo2odoo/
├── woo2odoo.php                          # Plugin header, bootstrap
├── classes/
│   ├── Woo2Odoo_Plugin.php               # Hook and service registration
│   ├── Woo2Odoo_Order_Manager.php        # Sync logic: SO, partner, invoice, payment
│   ├── Woo2Odoo_Client.php               # JSON-RPC client for Odoo
│   ├── Woo2Odoo_ClientFactory.php        # Client factory with instance cache
│   ├── Woo2Odoo_Admin_Order_Metabox.php  # Live metabox on the order screen
│   ├── Woo2Odoo_Sync_Status_Tab.php      # "Odoo Status" tab with Action Scheduler
│   ├── Woo2Odoo_Stock_Manager.php        # Stock sync from Odoo
│   ├── Woo2Odoo_Plugin_Admin.php         # Admin pages
│   ├── Woo2Odoo_Plugin_Settings.php      # Settings tabs
│   └── Woo2Odoo_CLI.php                  # WP-CLI commands
├── tests/
│   ├── bootstrap.php                     # wp-env bootstrap
│   └── wpunit-order-manager.php          # Integration tests
├── vendor/                               # Composer dependencies
├── CHANGELOG.md
└── readme.txt                            # WP.org-format readme
```

---

## Chile localization notes

- **RUT** is stored as `vat` on the Odoo partner. The `format_rut()` method normalizes the format (body without dots, dash separator, check digit preserving `K`)
- **Boleta Electrónica (type 39)** is the default document. If the customer requested an invoice at checkout, a **Factura Electrónica (type 33)** is issued instead
- **Chilean regions** are mapped to `res.country.state` in Odoo using ISO 3166-2:CL codes
- Requires the `l10n_cl` module to be installed in Odoo and the invoice journal configured for Chilean electronic documents

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full release history.

| Version | Summary |
|---------|---------|
| 1.5.1 | HPOS fix: `get_post_meta → wc_get_order()->get_meta()` in invoice creation; removed dead `create_invoice()` function |
| 1.5.0 | BACS payment sync to Odoo; Odoo errors surfaced as WC order notes; portable PHPUnit suite (wp-env); GitHub Actions CI |
| 1.4.0 | "Odoo Status" tab; background sync via Action Scheduler; global error notice |
| 1.3.x | Active SO guard; live metabox; WP-CLI; RUT-with-K fix; opt-in cache; guest order fix |
| 1.0.0 | Initial release: SO sync, partner management, draft invoice, refund sync, Chile l10n, JSON-RPC, PHP 8.3 |

---

## License

GPLv3 — see [LICENSE](https://www.gnu.org/licenses/gpl-3.0.html).
