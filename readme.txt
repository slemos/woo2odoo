=== Woo2Odoo ===
Contributors: slemos
Tags: odoo, woocommerce, erp, integration, chile
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.4.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

WooCommerce to Odoo integration plugin. Syncs orders, partners, invoices, and payments via JSON-RPC.

== Description ==

Woo2Odoo syncs WooCommerce orders to Odoo automatically. When an order reaches "processing" status, the plugin:

* Creates a **Sale Order** in Odoo with all line items, shipping, and customer notes
* Finds or creates the Odoo **partner** from WC customer data (or billing address for guest orders)
* Creates a draft **invoice** (Boleta or Factura Electrónica for Chile l10n)
* Registers the **payment** as an `account.payment` in Odoo (Transbank WebpayPlus and MercadoPago Basic)
* Stores Odoo record IDs as WC order meta for full traceability

= Features =

* **Automatic sync** on order status change to processing or on-hold
* **Guest checkout support** — builds the Odoo partner from billing address when no WP user exists
* **Sync guard** — detects existing active Sale Orders; links them to the WC order instead of creating duplicates
* **Live admin metabox** — shows real-time Odoo status (SO name, invoice state, payment state) with direct links to Odoo
* **"Estado Odoo" admin tab** — order list with Sale Order / Invoice / Payment meta and per-row + bulk sync buttons
* **Stock sync** — imports stock quantities from Odoo `free_qty` on a configurable cron schedule
* **Refund sync** — creates credit notes in Odoo when a WC refund is registered
* **WP-CLI command** — `wp woo2odoo sync` for batch re-sync with filtering and dry-run support
* **Chile l10n** — Boleta (type 39) / Factura Electrónica (type 33), RUT formatting, region codes for `res.country.state`
* **HPOS compatible** — works with both WooCommerce High-Performance Order Storage and classic post-based storage

= Requirements =

* WooCommerce 8.0+
* Odoo 16 or later (tested on Odoo SaaS)
* PHP 8.0+ (8.3 recommended)
* Composer dependencies included in `vendor/`

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/woo2odoo/`
2. Activate the plugin through the WordPress Plugins screen
3. Go to **WooCommerce > Settings > Woo2Odoo Plugin** and configure the Connection and Export tabs

= Required settings =

* **Odoo URL** — root URL of your Odoo instance, e.g. `https://your-instance.odoo.com`
* **Database** — Odoo database name
* **Username** — Odoo user email
* **API Key** — Odoo API key (generated in Odoo Settings > Technical > API Keys — this is not your web login password)
* **Invoice Journal ID** — numeric ID of the Odoo journal used for customer invoices
* **Payment Journal ID** — numeric ID of the Odoo Bank journal used for incoming payments

== WP-CLI Usage ==

  # Sync a specific order
  wp woo2odoo sync 12345

  # Preview unsynced orders without syncing
  wp woo2odoo sync --status=never --wc-status=processing --dry-run

  # Retry all failed orders (max 20)
  wp woo2odoo sync --status=failed --limit=20

  # Re-sync all processing orders regardless of sync status
  wp woo2odoo sync --status=all --wc-status=processing --limit=100

== Frequently Asked Questions ==

= The plugin uses an API key — is that different from my Odoo password? =

Yes. The plugin authenticates with an Odoo API key, not the web login password. Generate one in Odoo under Settings > Technical > API Keys. Changing your Odoo web password does not affect the plugin.

= An order shows "sync blocked — active SO exists". What does that mean? =

The plugin found an existing non-cancelled Sale Order in Odoo with the same WC order ID as origin. It linked that SO to the WC order meta instead of creating a duplicate. If the existing SO is wrong, cancel it in Odoo and re-sync.

= Stock sync is not running. How do I check the schedule? =

Run `wp cron event list | grep odoo` to inspect the scheduled cron event. You can also re-activate stock sync by saving the Export settings.

== Changelog ==

See CHANGELOG.md for detailed release notes.

= 1.4.0 =
* New: "Estado Odoo" settings tab — order list with Sale Order / Invoice / Payment meta and sync status, filters and pagination
* New: per-row "Sync" button (AJAX) + row selection with "Sync selected" that enqueues background jobs via Action Scheduler
* New: global admin notice when orders fail to sync, with a link to the "Estado Odoo" tab (dismissible 12h)

= 1.3.3 =
* Fix: format_rut() corrupted RUTs ending in K (~9% of Chilean RUTs)
* Fix: surface the real Odoo error (e.g. invalid RUT) instead of generic "Customer data unavailable"

= 1.3.2 =
* Fix: stale Redis cache served phantom Sale Orders (search_read caching is now opt-in, default fresh)
* Fix: DivisionByZeroError on line items with quantity 0; pre-flight validation fails such orders cleanly; catch \Throwable

= 1.3.1 =
* Guard links existing Odoo SO/invoice/payment to WC meta instead of blocking with failed status
* Fix: MercadoPago payment date fallback to get_date_paid() when _paid_date meta is empty

= 1.3.0 =
* Sync guard (prevents duplicate SOs), live admin metabox with Odoo links and state badges, WP-CLI sync command, set_sync_status() tracking helper

= 1.2.0 =
* Fix: guest orders (no WP account) now sync correctly to Odoo

= 1.1.0 =
* Automatic payment reconciliation (Transbank / MercadoPago), stock sync from Odoo, order action button, admin settings fixes

= 1.0.0 =
* Initial release: SO sync, partner management, draft invoice, refund sync, Chile l10n, PHP 8.3 / JSON-RPC
