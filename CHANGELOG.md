# Changelog

All notable changes to Woo2Odoo are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Release notes are also published as [GitHub Releases](https://github.com/slemos/woo2odoo/releases).

---

## [Unreleased]

## [1.3.3] - 2026-07-05

### Fixed
- **`format_rut()` corrupted RUTs ending in K.** The formatter stripped every non-digit, so a valid `K` check digit (~9% of Chilean RUTs, e.g. `14501736-K`) was removed and the RUT became a different, wrong number (`1450173-6`). It now preserves the `K` check digit and only strips separators from the body.
- **Opaque sync failures on customer creation.** When Odoo rejected the partner (e.g. an invalid RUT), `order_sync()` reported the generic `Customer data unavailable`, indistinguishable from an auth failure. The real Odoo error is now captured (`Woo2Odoo_Client::get_last_error()`) and surfaced in `_woo2odoo_sync_error` and the order note (e.g. "RUT [14501736-7] does not seem to be valid").

## [1.3.2] - 2026-07-05

### Fixed
- **Stale Redis cache served phantom Sale Orders** (critical). `Woo2Odoo_Client::search_read()` cached every result in Redis for an hour. The `order_sync()` guard used that cached wrapper to check whether a SO already existed, so a since-deleted QA test SO was still returned from cache — the sync linked a phantom SO (`_odoo_sale_order_id` pointing to a non-existent record) instead of creating a real one, and the `state != cancel` filter was silently bypassed because the query never reached Odoo.
- **DivisionByZeroError on corrupt line items.** An order line with `quantity = 0` (external data corruption, e.g. a 3PL REST `PUT` that zeroed quantities) caused `add_order_line_items()` to fatal on `total / quantity`, aborting mid-sync and leaving an orphan empty SO in Odoo.

### Changed
- **`search_read()` caching is now opt-in.** Default is no cache; callers pass `'cache' => true` only for immutable/slow-changing lookups (SKU→id maps via `get_odoo_skus`, `res.country`/`res.country.state`). All mutable-state reads (sale.order, account.move/payment, res.partner, stock `free_qty`) are always fresh. Removed the now-unnecessary cache-flush workaround in `Woo2Odoo_Stock_Manager`.
- **`order_sync()` pre-flight validation.** Orders with any line `quantity <= 0` now fail cleanly (status `failed` + order note) before any Odoo record is created, instead of fataling.
- `order_sync()` / `refund_sync()` now catch `\Throwable` (not just `\Exception`) so PHP `Error`s mid-sync set status `failed` instead of leaving partial Odoo records.

## [1.3.1] - 2026-07-04

### Changed
- Sync guard: when an active SO is found in Odoo for the same WC order, the plugin now **links** the existing Odoo records to WC order meta (`_odoo_sale_order_id`, `_woo2odoo_invoice_id`, `_woo2odoo_payment_id`) and marks the order as `synced`, instead of blocking with a `failed` status. The `invoice_ids` field was added to the SO `search_read` to support invoice linking.

### Fixed
- MercadoPago payments: `_paid_date` (written by WC core, not the MP plugin) was missing on some orders, causing `get_payment_info_from_wc_order()` to return `false` silently and skip `account.payment` creation in Odoo. The sync now falls back to `$order->get_date_paid()` when `_paid_date` is empty.

---

## [1.3.0] - 2026-07-03

### Added
- **Sync guard**: `order_sync()` now searches for existing non-cancelled SOs (`state != cancel`) in Odoo before creating a new one. If an active SO is found, the sync is blocked and an order note is added explaining the reason. Cancelled SOs are ignored — a new SO is created normally.
- **Live admin metabox**: "Sincronización Odoo" now fetches real-time status from Odoo on render (cached 5 min via transient). Shows the SO name (`S02487`), direct `↗` links to the SO and Invoice in Odoo, coloured state badges (SO: sale/sent/cancel — Invoice: posted/draft/cancel — Payment: paid/in_payment/not_paid), and the WC sync status with date. `?woo2odoo_refresh=1` invalidates the cache. Falls back gracefully when Odoo is unavailable.
- **WP-CLI command** `wp woo2odoo sync`: batch re-sync from the command line. Flags: `--status` (pending/failed/never/all), `--wc-status`, `--limit`, `--dry-run`. Single-order mode (`wp woo2odoo sync <id>`) shows full detail including the resulting Odoo SO ID on success.
- `set_sync_status()` private helper in `Woo2Odoo_Order_Manager`: consistently writes `_woo2odoo_sync_status`, `_woo2odoo_sync_date`, and `_woo2odoo_sync_error` to WC order meta at each stage of the sync flow (pending → synced/failed).

### Changed
- Metabox "⟳ Refrescar desde Odoo" link is now shown even when the order has no sync meta yet (previously hidden on early-exit path).

---

## [1.2.0] - 2026-06-29

### Fixed
- **Guest orders** (critical): orders placed without a WP account never synced to Odoo. `get_customer_data()` passed `false` to `create_or_update_customer()` when no WP user existed — partner was created empty or the sync aborted silently. New `create_customer_from_order()` builds the Odoo partner from the order's billing address and `_billing_rut` meta. ([PR #4](https://github.com/slemos/woo2odoo/pull/4))
- `stdClass` array access: fixed `Cannot use object of type stdClass as array` when `order_sync()` found an existing SO — `search_read` with `single: true` returns a `stdClass`, not an array. ([PR #4](https://github.com/slemos/woo2odoo/pull/4))

---

## [1.1.0] - 2026-06-18

### Added
- **Payment reconciliation**: `create_outstanding_payment()` automatically creates an `account.payment` in Odoo (state `in_process`, configurable Bank journal via `payment_journal_id`) when an order is paid via Transbank WebpayPlus or MercadoPago Basic. The invoice stays in DRAFT for manual confirmation by the accountant. ([PR #1](https://github.com/slemos/woo2odoo/pull/1))
- **Stock sync** (Odoo → WC): `Woo2Odoo_Stock_Manager` imports product stock from Odoo `free_qty` on a configurable schedule (hourly / twicedaily / daily). ([PR #3](https://github.com/slemos/woo2odoo/pull/3))
- **Order action button**: "Sincronizar con Odoo" in the WC order actions dropdown triggers a manual re-sync from the order edit screen. ([PR #3](https://github.com/slemos/woo2odoo/pull/3))
- **Admin metabox** (initial): read-only panel in the WC order edit screen showing Odoo SO, invoice, and payment IDs. HPOS and classic storage compatible. ([PR #3](https://github.com/slemos/woo2odoo/pull/3))
- New admin settings: `payment_journal_id` (Bank journal ID in Odoo for incoming payments) and `invoice_terms_url` (T&C URL embedded in invoice narration). ([PR #1](https://github.com/slemos/woo2odoo/pull/1))

### Fixed
- Invoice document type: defaults to (39) Boleta Electrónica; switches to (33) Factura Electrónica only when `_billing_invoice_type=1` on the WC order. ([PR #1](https://github.com/slemos/woo2odoo/pull/1))
- Delivery address: `get_or_create_address()` now updates an existing Odoo partner address instead of skipping with stale data. ([PR #1](https://github.com/slemos/woo2odoo/pull/1))
- `payment_reference` is set to `WC#NNNNN` before `action_post` so Odoo does not overwrite it with the invoice document name. ([PR #1](https://github.com/slemos/woo2odoo/pull/1))
- Settings persistence: corrected option key to `Woo2Odoo-plugin` to align `register_setting` with `get_option` — settings were saving to a different key than they were read from. ([PR #3](https://github.com/slemos/woo2odoo/pull/3))
- Redis `alloptions` bloat: `fix_options_autoload` prevents plugin options from polluting the shared `alloptions` blob. ([PR #3](https://github.com/slemos/woo2odoo/pull/3))

---

## [1.0.0] - 2026-06-10

### Added
- Initial release.
- WC order → Odoo `sale.order` sync triggered on `woocommerce_order_status_processing`.
- Partner management: find-or-create `res.partner` (contact, invoice address, delivery address) from WC order billing/shipping data.
- Invoice creation: `create_invoice_for_so()` creates a draft `account.move` (Boleta/Factura Electrónica) in Odoo, linked to the SO lines.
- Shipping line sync: maps WC shipping total to a configurable Odoo product.
- Refund sync: `refund_sync()` creates a credit note (`out_refund`) in Odoo when a WC refund is registered.
- Admin settings UI: Connection tab (URL, DB, user, API key) and Export tab (journal, document type, payment journal, terms URL).
- PHP 8.3 compatible via JSON-RPC 2.0 (`winternet-studio/odoo-jsonrpc-client` / Guzzle), replacing the XML-RPC dependency that was removed in PHP 8.
- Chile l10n: `l10n_latam_document_type_id` mapping, RUT formatting, `res.country.state` region codes.
