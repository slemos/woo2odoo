<?php
/**
 * Woo2Odoo_Admin_Order_Metabox
 *
 * Adds a read-only meta box to the WC order edit screen showing Odoo sync state.
 * Compatible with both classic (post-based) and HPOS order storage.
 *
 * Features:
 *  - Direct "Ver en Odoo" links for Sale Order and Invoice.
 *  - Live status from Odoo (cached 5 min) with colored pill badges.
 *  - WC sync status (_woo2odoo_sync_status / _woo2odoo_sync_date).
 *  - Refresh button (no-AJAX: reloads page with ?woo2odoo_refresh=1).
 *
 * @package Woo2Odoo
 */
namespace Woo2Odoo;

class Woo2Odoo_Admin_Order_Metabox {

	// ── Badge colour map ──────────────────────────────────────────────────────

	private static $so_state_colors = array(
		'sale'   => '#46b450',
		'sent'   => '#ffb900',
		'cancel' => '#dc3232',
		'draft'  => '#999999',
	);

	private static $inv_state_colors = array(
		'posted' => '#46b450',
		'draft'  => '#ffb900',
		'cancel' => '#dc3232',
	);

	private static $pay_state_colors = array(
		'paid'       => '#46b450',
		'in_payment' => '#ffb900',
		'partial'    => '#ffb900',
		'not_paid'   => '#999999',
	);

	// ── Registration ──────────────────────────────────────────────────────────

	public static function register(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ) );
	}

	public static function add(): void {
		foreach ( array( 'shop_order', 'woocommerce_page_wc-orders' ) as $screen ) {
			add_meta_box(
				'woo2odoo-sync-status',
				'Sincronización Odoo',
				array( __CLASS__, 'render' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	// ── Main render ───────────────────────────────────────────────────────────

	public static function render( $post_or_order ): void {
		$order = ( $post_or_order instanceof \WP_Post )
			? wc_get_order( $post_or_order->ID )
			: $post_or_order;

		if ( ! $order ) {
			return;
		}

		// WC meta.
		$so_id       = $order->get_meta( '_odoo_sale_order_id' );
		$invoice_id  = $order->get_meta( '_woo2odoo_invoice_id' );
		$payment_id  = $order->get_meta( '_woo2odoo_payment_id' );
		$sync_status = $order->get_meta( '_woo2odoo_sync_status' );
		$sync_date   = $order->get_meta( '_woo2odoo_sync_date' );

		// Odoo base URL from plugin settings.
		$settings = get_option( 'Woo2Odoo-plugin-connection', array() );
		$odoo_url = isset( $settings['odoo_url'] ) ? rtrim( $settings['odoo_url'], '/' ) : '';

		// Live data from Odoo (cached).
		$live       = null;
		$odoo_error = false;

		if ( $so_id ) {
			$cache_key = 'woo2odoo_live_status_' . $order->get_id();

			// Honour manual refresh request.
			if ( isset( $_GET['woo2odoo_refresh'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				delete_transient( $cache_key );
			}

			$live = get_transient( $cache_key );

			if ( false === $live ) {
				$live = self::fetch_live_status( (int) $so_id );
				if ( null !== $live ) {
					set_transient( $cache_key, $live, 5 * MINUTE_IN_SECONDS );
				} else {
					$odoo_error = true;
					$live       = null;
				}
			}
		}

		// ── CSS ───────────────────────────────────────────────────────────────

		echo '<style>
			#woo2odoo-sync-status .woo2odoo-row {
				display: flex;
				justify-content: space-between;
				align-items: flex-start;
				padding: 6px 0;
				border-bottom: 1px solid #f0f0f0;
				font-size: 12px;
				gap: 6px;
			}
			#woo2odoo-sync-status .woo2odoo-row:last-child { border-bottom: none; }
			#woo2odoo-sync-status .woo2odoo-label {
				color: #757575;
				white-space: nowrap;
				min-width: 70px;
			}
			#woo2odoo-sync-status .woo2odoo-value {
				font-weight: 600;
				color: #2c3338;
				font-family: monospace;
				display: flex;
				align-items: center;
				flex-wrap: wrap;
				gap: 4px;
			}
			#woo2odoo-sync-status .woo2odoo-none {
				color: #999;
				font-style: italic;
				font-size: 12px;
			}
			#woo2odoo-sync-status .woo2odoo-badge {
				display: inline-block;
				padding: 1px 6px;
				border-radius: 10px;
				font-size: 10px;
				font-weight: 600;
				color: #fff;
				font-family: sans-serif;
				line-height: 1.6;
				white-space: nowrap;
			}
			#woo2odoo-sync-status .woo2odoo-link {
				text-decoration: none;
				font-size: 11px;
				opacity: 0.7;
			}
			#woo2odoo-sync-status .woo2odoo-link:hover { opacity: 1; }
			#woo2odoo-sync-status .woo2odoo-sep {
				border: none;
				border-top: 1px solid #ddd;
				margin: 6px 0;
			}
			#woo2odoo-sync-status .woo2odoo-refresh {
				display: block;
				margin-top: 8px;
				text-align: center;
				font-size: 11px;
				text-decoration: none;
				color: #2271b1;
			}
			#woo2odoo-sync-status .woo2odoo-refresh:hover { text-decoration: underline; }
			#woo2odoo-sync-status .woo2odoo-notice {
				font-size: 11px;
				color: #999;
				font-style: italic;
				margin: 4px 0 0;
			}
		</style>';

		// ── Early exit when nothing synced ────────────────────────────────────

		if ( ! $so_id && ! $invoice_id && ! $payment_id ) {
			echo '<p class="woo2odoo-none">No sincronizado con Odoo.</p>';
			$refresh_url = esc_url( add_query_arg( 'woo2odoo_refresh', '1' ) );
			echo '<a href="' . $refresh_url . '" class="woo2odoo-refresh">&#10227; Refrescar desde Odoo</a>';
			return;
		}

		// ── Sale Order row ────────────────────────────────────────────────────

		if ( $so_id ) {
			// Prefer SO name from live data, fall back to "#<numeric-id>".
			$so_display = ( $live && ! empty( $live['so_name'] ) )
				? esc_html( $live['so_name'] )
				: '#' . esc_html( $so_id );

			$so_link = '';
			if ( $odoo_url ) {
				$so_href = esc_url( $odoo_url . '/odoo/sales/' . rawurlencode( $so_id ) );
				$so_link = ' <a href="' . $so_href . '" target="_blank" rel="noopener noreferrer"'
					. ' class="woo2odoo-link" title="Ver en Odoo">&#8599;</a>';
			}

			$so_badge = '';
			if ( $live && ! empty( $live['so_state'] ) ) {
				$so_badge = ' ' . self::badge( $live['so_state'], self::$so_state_colors );
			}

			echo '<div class="woo2odoo-row">'
				. '<span class="woo2odoo-label">Sale Order</span>'
				. '<span class="woo2odoo-value">' . $so_display . $so_link . $so_badge . '</span>'
				. '</div>';
		}

		// ── Invoice row ───────────────────────────────────────────────────────

		if ( $invoice_id ) {
			// Use the numeric ID from WC meta (or live data) for the URL.
			$inv_id_for_url = ( $live && ! empty( $live['inv_id'] ) )
				? $live['inv_id']
				: $invoice_id;

			// Prefer the Odoo document name (e.g. "FACT/2024/001") if available.
			$inv_display = ( $live && ! empty( $live['inv_name'] ) )
				? esc_html( $live['inv_name'] )
				: '#' . esc_html( $inv_id_for_url );

			$inv_link = '';
			if ( $odoo_url ) {
				$inv_href = esc_url( $odoo_url . '/odoo/accounting/customer-invoices/' . rawurlencode( $inv_id_for_url ) );
				$inv_link = ' <a href="' . $inv_href . '" target="_blank" rel="noopener noreferrer"'
					. ' class="woo2odoo-link" title="Ver en Odoo">&#8599;</a>';
			}

			$inv_badges = '';
			if ( $live && ! empty( $live['inv_state'] ) ) {
				$inv_badges .= ' ' . self::badge( $live['inv_state'], self::$inv_state_colors );
			}
			if ( $live && ! empty( $live['pay_state'] ) ) {
				$inv_badges .= ' ' . self::badge( $live['pay_state'], self::$pay_state_colors );
			}

			echo '<div class="woo2odoo-row">'
				. '<span class="woo2odoo-label">Boleta</span>'
				. '<span class="woo2odoo-value">' . $inv_display . $inv_link . $inv_badges . '</span>'
				. '</div>';
		}

		// ── Payment row ───────────────────────────────────────────────────────

		if ( $payment_id ) {
			$pay_badge = '';
			if ( $live && ! empty( $live['pay_state'] ) ) {
				$pay_badge = ' ' . self::badge( $live['pay_state'], self::$pay_state_colors );
			}

			echo '<div class="woo2odoo-row">'
				. '<span class="woo2odoo-label">Pago</span>'
				. '<span class="woo2odoo-value">#' . esc_html( $payment_id ) . $pay_badge . '</span>'
				. '</div>';
		}

		// ── Odoo unavailability notice ────────────────────────────────────────

		if ( $odoo_error ) {
			echo '<p class="woo2odoo-notice">(Odoo no disponible)</p>';
		}

		// ── WC sync status section ────────────────────────────────────────────

		echo '<hr class="woo2odoo-sep">';

		$wc_badge = '';
		$wc_date  = '';
		if ( $sync_status ) {
			$wc_colors = array(
				'synced'  => '#46b450',
				'pending' => '#ffb900',
				'failed'  => '#dc3232',
			);
			$wc_badge = self::badge( $sync_status, $wc_colors );
			if ( $sync_date ) {
				$wc_date = '<br><span style="font-weight:normal;font-family:sans-serif;color:#757575;">'
					. esc_html( $sync_date ) . '</span>';
			}
		} else {
			$wc_badge = '<span class="woo2odoo-none">No sincronizado</span>';
		}

		echo '<div class="woo2odoo-row">'
			. '<span class="woo2odoo-label">WC Status</span>'
			. '<span class="woo2odoo-value">' . $wc_badge . $wc_date . '</span>'
			. '</div>';

		// ── Refresh link ──────────────────────────────────────────────────────

		if ( $so_id ) {
			$refresh_url = esc_url( add_query_arg( 'woo2odoo_refresh', '1' ) );
			echo '<a href="' . $refresh_url . '" class="woo2odoo-refresh">&#10227; Refrescar desde Odoo</a>';
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Build a coloured pill badge <span>.
	 *
	 * @param string $value  State value, e.g. 'sale', 'posted'.
	 * @param array  $colors Map of state => hex colour string.
	 * @return string HTML.
	 */
	private static function badge( string $value, array $colors ): string {
		$color = isset( $colors[ $value ] ) ? $colors[ $value ] : '#999999';
		return '<span class="woo2odoo-badge" style="background-color:' . esc_attr( $color ) . ';">'
			. esc_html( $value )
			. '</span>';
	}

	/**
	 * Fetch live Sale Order + Invoice status from Odoo.
	 *
	 * Returns an associative array on success, null on any failure.
	 *
	 * Returned keys:
	 *   so_name   — SO document name (e.g. 'S02487')
	 *   so_state  — SO state string  (e.g. 'sale')
	 *   inv_id    — Invoice numeric ID as string
	 *   inv_name  — Invoice document name (e.g. 'INV/2024/0031')
	 *   inv_state — Invoice state string
	 *   pay_state — Invoice payment_state string
	 *
	 * @param int $so_id Odoo sale.order numeric ID.
	 * @return array|null
	 */
	private static function fetch_live_status( int $so_id ): ?array {
		try {
			$client = new Woo2Odoo_Client();

			if ( ! $client->authenticate() ) {
				return null;
			}

			$so_records = $client->execute(
				'sale.order',
				'search_read',
				array(
					array( array( 'id', '=', $so_id ) ),
					array( 'id', 'name', 'state', 'invoice_ids' ),
				)
			);

			if ( empty( $so_records ) || ! isset( $so_records[0] ) ) {
				return null;
			}

			$so = $so_records[0];

			$data = array(
				'so_name'   => isset( $so->name )  ? (string) $so->name  : '',
				'so_state'  => isset( $so->state ) ? (string) $so->state : '',
				'inv_id'    => '',
				'inv_name'  => '',
				'inv_state' => '',
				'pay_state' => '',
			);

			// Fetch first linked invoice when available.
			$invoice_ids = isset( $so->invoice_ids ) ? (array) $so->invoice_ids : array();

			if ( ! empty( $invoice_ids ) ) {
				$inv_records = $client->execute(
					'account.move',
					'search_read',
					array(
						array( array( 'id', '=', (int) $invoice_ids[0] ) ),
						array( 'id', 'name', 'state', 'payment_state' ),
					)
				);

				if ( ! empty( $inv_records ) && isset( $inv_records[0] ) ) {
					$inv = $inv_records[0];
					$data['inv_id']    = isset( $inv->id )            ? (string) $inv->id            : '';
					$data['inv_name']  = isset( $inv->name )          ? (string) $inv->name          : '';
					$data['inv_state'] = isset( $inv->state )         ? (string) $inv->state         : '';
					$data['pay_state'] = isset( $inv->payment_state ) ? (string) $inv->payment_state : '';
				}
			}

			return $data;

		} catch ( \Exception $e ) {
			// Return null so caller can display fallback and "(Odoo no disponible)".
			return null;
		}
	}
}
