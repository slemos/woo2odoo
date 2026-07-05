<?php
/**
 * Woo2Odoo_Sync_Status_Tab
 *
 * Adds a "Estado Odoo" tab to the plugin settings screen: a table of WooCommerce
 * orders with their Odoo sync meta (Sale Order, Invoice, Payment) and per-row +
 * bulk "Sincronizar" buttons (AJAX, no reload for single rows).
 *
 * Reads stored meta only (fast) — it does NOT hit Odoo on render. The per-row
 * button calls order_sync(), which is the same path as the WP-CLI command and the
 * automatic hook, so it benefits from all guard / cache / validation fixes.
 *
 * @package Woo2Odoo
 */
namespace Woo2Odoo;

class Woo2Odoo_Sync_Status_Tab {

	const PER_PAGE = 30;   // rows per page in the table

	// ── Registration ──────────────────────────────────────────────────────────

	public static function register(): void {
		add_action( 'wp_ajax_woo2odoo_sync_order', array( __CLASS__, 'ajax_sync_order' ) );
		add_action( 'wp_ajax_woo2odoo_enqueue_sync', array( __CLASS__, 'ajax_enqueue_sync' ) );
	}

	// ── Query helpers ─────────────────────────────────────────────────────────

	private static function filters(): array {
		return array(
			'failed'  => __( 'Con error', 'woo2odoo-plugin' ),
			'pending' => __( 'Pendientes', 'woo2odoo-plugin' ),
			'never'   => __( 'Sin intentar', 'woo2odoo-plugin' ),
			'synced'  => __( 'Sincronizados', 'woo2odoo-plugin' ),
			'all'     => __( 'Todos', 'woo2odoo-plugin' ),
		);
	}

	private static function wc_statuses(): array {
		return array( 'wc-processing', 'wc-on-hold', 'wc-completed' );
	}

	/** Build the meta_query fragment for a given sync-status filter. */
	private static function meta_query_for( string $filter ): array {
		switch ( $filter ) {
			case 'never':
				return array( array( 'key' => '_woo2odoo_sync_status', 'compare' => 'NOT EXISTS' ) );
			case 'failed':
			case 'pending':
			case 'synced':
				return array( array( 'key' => '_woo2odoo_sync_status', 'value' => $filter ) );
			default: // 'all'
				return array();
		}
	}

	/** Efficient count for a filter (uses paginate total, does not load all orders). */
	private static function count_for( string $filter ): int {
		$args = array( 'type' => 'shop_order', 'limit' => 1, 'paginate' => true, 'status' => self::wc_statuses(), 'return' => 'ids' );
		$mq   = self::meta_query_for( $filter );
		if ( $mq ) {
			$args['meta_query'] = $mq;
		}
		$res = wc_get_orders( $args );
		return (int) $res->total;
	}

	private static function odoo_url(): string {
		$settings = get_option( 'Woo2Odoo-plugin-connection', array() );
		return isset( $settings['odoo_url'] ) ? rtrim( $settings['odoo_url'], '/' ) : '';
	}

	// ── AJAX: single order ────────────────────────────────────────────────────

	public static function ajax_sync_order(): void {
		check_ajax_referer( 'woo2odoo_sync', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'msg' => 'forbidden' ), 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( array( 'msg' => 'ID de pedido inválido' ) );
		}

		$manager = new Woo2Odoo_Order_Manager();
		$ok      = (bool) $manager->order_sync( $order_id );

		$order = wc_get_order( $order_id );
		wp_send_json_success( array(
			'ok'      => $ok,
			'status'  => $order ? ( $order->get_meta( '_woo2odoo_sync_status' ) ?: 'never' ) : 'never',
			'so'      => $order ? $order->get_meta( '_odoo_sale_order_id' ) : '',
			'invoice' => $order ? $order->get_meta( '_woo2odoo_invoice_id' ) : '',
			'payment' => $order ? $order->get_meta( '_woo2odoo_payment_id' ) : '',
			'error'   => $order ? $order->get_meta( '_woo2odoo_sync_error' ) : '',
		) );
	}

	// ── AJAX: enqueue selected orders to Action Scheduler (background) ─────────

	public static function ajax_enqueue_sync(): void {
		check_ajax_referer( 'woo2odoo_sync', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'msg' => 'forbidden' ), 403 );
		}

		$ids = isset( $_POST['order_ids'] ) ? (array) wp_unslash( $_POST['order_ids'] ) : array();
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'msg' => 'No hay pedidos seleccionados.' ) );
		}
		// Guard against runaway payloads.
		$ids = array_slice( $ids, 0, 500 );

		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			wp_send_json_error( array( 'msg' => 'Action Scheduler no está disponible (WooCommerce).' ) );
		}

		$queued = 0;
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			// Reflect "en cola" immediately so the row updates without waiting for the job.
			$order->update_meta_data( '_woo2odoo_sync_status', 'pending' );
			$order->delete_meta_data( '_woo2odoo_sync_error' );
			$order->save();

			// Async background job. Identical pending actions are de-duplicated by AS,
			// so double-clicking will not enqueue the same order twice.
			as_enqueue_async_action( 'woo2odoo_sync_single', array( $id ), 'woo2odoo' );
			$queued++;
		}

		wp_send_json_success( array( 'queued' => $queued ) );
	}

	// ── Tab renderer ──────────────────────────────────────────────────────────

	public static function render_tab(): void {
		$filter = isset( $_GET['sync_filter'] ) ? sanitize_key( wp_unslash( $_GET['sync_filter'] ) ) : 'failed'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! array_key_exists( $filter, self::filters() ) ) {
			$filter = 'failed';
		}
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification

		$args = array(
			'type'     => 'shop_order',
			'limit'    => self::PER_PAGE,
			'paged'    => $paged,
			'status'   => self::wc_statuses(),
			'orderby'  => 'date',
			'order'    => 'DESC',
			'paginate' => true,
		);
		$mq = self::meta_query_for( $filter );
		if ( $mq ) {
			$args['meta_query'] = $mq;
		}
		$result   = wc_get_orders( $args );
		$orders   = $result->orders;
		$max_page = (int) $result->max_num_pages;

		$odoo_url    = self::odoo_url();
		$nonce       = wp_create_nonce( 'woo2odoo_sync' );
		$base_url    = wp_nonce_url(
			admin_url( 'options-general.php?page=woo2odoo-plugin&tab=sync-status' ),
			'woo2odoo_plugin_switch_settings_tab',
			'woo2odoo_plugin_switch_settings_tab'
		);
		self::print_styles();
		?>
		<div id="woo2odoo-sync-tab" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<h2><?php esc_html_e( 'Estado de sincronización con Odoo', 'woo2odoo-plugin' ); ?></h2>

			<div class="woo2odoo-filterbar">
				<?php foreach ( self::filters() as $key => $label ) : ?>
					<?php $count = self::count_for( $key ); ?>
					<a class="chip <?php echo $filter === $key ? 'active' : ''; ?> f-<?php echo esc_attr( $key ); ?>"
						href="<?php echo esc_url( add_query_arg( array( 'sync_filter' => $key, 'paged' => 1 ), $base_url ) ); ?>">
						<?php echo esc_html( $label ); ?> <span class="n"><?php echo (int) $count; ?></span>
					</a>
				<?php endforeach; ?>

				<button type="button" class="button button-primary woo2odoo-sync-selected" disabled>
					<?php esc_html_e( 'Sincronizar seleccionados', 'woo2odoo-plugin' ); ?> (<span class="sel-count">0</span>)
				</button>
			</div>
			<p class="description woo2odoo-bg-note">
				<?php esc_html_e( 'Los seleccionados se encolan y se sincronizan en segundo plano (Action Scheduler). Actualizá la página para ver el avance.', 'woo2odoo-plugin' ); ?>
			</p>

			<table id="woo2odoo-sync-table" class="widefat striped">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" class="woo2odoo-check-all" title="<?php esc_attr_e( 'Seleccionar todos', 'woo2odoo-plugin' ); ?>"></td>
						<th><?php esc_html_e( 'Pedido', 'woo2odoo-plugin' ); ?></th>
						<th><?php esc_html_e( 'Monto', 'woo2odoo-plugin' ); ?></th>
						<th><?php esc_html_e( 'Sale Order', 'woo2odoo-plugin' ); ?></th>
						<th><?php esc_html_e( 'Boleta', 'woo2odoo-plugin' ); ?></th>
						<th><?php esc_html_e( 'Pago', 'woo2odoo-plugin' ); ?></th>
						<th><?php esc_html_e( 'Estado', 'woo2odoo-plugin' ); ?></th>
						<th><?php esc_html_e( 'Acción', 'woo2odoo-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $orders ) ) : ?>
						<tr><td colspan="8" class="woo2odoo-empty"><?php esc_html_e( 'No hay pedidos en este filtro.', 'woo2odoo-plugin' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $orders as $order ) : ?>
							<?php self::render_row( $order, $odoo_url ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $max_page > 1 ) : ?>
				<div class="woo2odoo-pagination">
					<?php
					for ( $p = 1; $p <= $max_page; $p++ ) {
						$url = esc_url( add_query_arg( array( 'sync_filter' => $filter, 'paged' => $p ), $base_url ) );
						if ( $p === $paged ) {
							echo '<span class="page current">' . (int) $p . '</span>';
						} else {
							echo '<a class="page" href="' . $url . '">' . (int) $p . '</a>';
						}
					}
					?>
				</div>
			<?php endif; ?>

			<div id="woo2odoo-bulk-progress" style="display:none;"></div>
		</div>
		<?php
		self::print_script();
	}

	// ── Row renderer ──────────────────────────────────────────────────────────

	private static function render_row( $order, string $odoo_url ): void {
		// Defensive: refunds (WC_Order_Refund) share some order queries but are not
		// syncable and lack these methods — skip anything that isn't a real order.
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$id       = $order->get_id();
		$so       = $order->get_meta( '_odoo_sale_order_id' );
		$invoice  = $order->get_meta( '_woo2odoo_invoice_id' );
		$payment  = $order->get_meta( '_woo2odoo_payment_id' );
		$status   = $order->get_meta( '_woo2odoo_sync_status' ) ?: 'never';
		$error    = $order->get_meta( '_woo2odoo_sync_error' );
		$customer = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$edit_url = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $id );

		$so_cell  = $so ? esc_html( $so ) . self::odoo_link( $odoo_url, 'sales', $so ) : '—';
		$inv_cell = $invoice ? esc_html( $invoice ) . self::odoo_link( $odoo_url, 'invoice', $invoice ) : '—';
		$pay_cell = $payment ? esc_html( $payment ) : '—';
		?>
		<tr id="woo2odoo-row-<?php echo (int) $id; ?>" data-order-id="<?php echo (int) $id; ?>">
			<th scope="row" class="check-column">
				<input type="checkbox" class="woo2odoo-check" value="<?php echo (int) $id; ?>">
			</th>
			<td>
				<a href="<?php echo esc_url( $edit_url ); ?>"><strong>#<?php echo (int) $id; ?></strong></a>
				<?php if ( $customer ) : ?><div class="muted"><?php echo esc_html( $customer ); ?></div><?php endif; ?>
			</td>
			<td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
			<td class="cell-so"><?php echo wp_kses_post( $so_cell ); ?></td>
			<td class="cell-inv"><?php echo wp_kses_post( $inv_cell ); ?></td>
			<td class="cell-pay"><?php echo esc_html( $pay_cell ); ?></td>
			<td class="cell-status"><?php echo self::badge_html( $status, $error ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
			<td class="cell-action">
				<button type="button" class="button woo2odoo-sync-one" data-order-id="<?php echo (int) $id; ?>">
					<?php echo 'synced' === $status ? esc_html__( 'Re-sincronizar', 'woo2odoo-plugin' ) : esc_html__( 'Sincronizar', 'woo2odoo-plugin' ); ?>
				</button>
			</td>
		</tr>
		<?php
	}

	private static function odoo_link( string $odoo_url, string $type, $id ): string {
		if ( ! $odoo_url ) {
			return '';
		}
		$path = 'invoice' === $type
			? '/odoo/accounting/customer-invoices/' . rawurlencode( $id )
			: '/odoo/sales/' . rawurlencode( $id );
		return ' <a href="' . esc_url( $odoo_url . $path ) . '" target="_blank" rel="noopener noreferrer" title="Ver en Odoo" style="text-decoration:none;">&#8599;</a>';
	}

	private static function badge_html( string $status, string $error = '' ): string {
		$labels = array(
			'synced'  => 'synced',
			'pending' => 'pending',
			'failed'  => 'failed',
			'never'   => 'sin intentar',
		);
		$label = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
		$html  = '<span class="woo2odoo-badge b-' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
		if ( 'failed' === $status && $error ) {
			$html .= '<div class="woo2odoo-err" title="' . esc_attr( $error ) . '">' . esc_html( mb_strimwidth( $error, 0, 70, '…' ) ) . '</div>';
		}
		return $html;
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	private static function print_styles(): void {
		?>
		<style>
			#woo2odoo-sync-tab { max-width: 1100px; }
			#woo2odoo-sync-tab .woo2odoo-filterbar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:14px 0; }
			#woo2odoo-sync-tab .chip { text-decoration:none; padding:4px 12px; border:1px solid #c3c4c7; border-radius:14px; font-size:13px; color:#2c3338; background:#fff; }
			#woo2odoo-sync-tab .chip.active { background:#2271b1; color:#fff; border-color:#2271b1; }
			#woo2odoo-sync-tab .chip .n { font-weight:700; margin-left:2px; }
			#woo2odoo-sync-tab .woo2odoo-sync-selected { margin-left:auto; }
			#woo2odoo-sync-tab .woo2odoo-bg-note { color:#787c82; margin:0 0 10px; }
			#woo2odoo-sync-table .check-column { width:2.2em; text-align:center; padding-left:8px; }
			#woo2odoo-sync-table td.check-column { vertical-align:middle; }
			#woo2odoo-sync-table td, #woo2odoo-sync-table th { vertical-align:middle; }
			#woo2odoo-sync-tab .muted { color:#787c82; font-size:12px; }
			#woo2odoo-sync-tab .woo2odoo-empty { text-align:center; color:#787c82; padding:24px; }
			#woo2odoo-sync-tab .woo2odoo-badge { display:inline-block; padding:2px 9px; border-radius:10px; font-size:11px; font-weight:600; color:#fff; text-transform:uppercase; letter-spacing:.3px; }
			#woo2odoo-sync-tab .b-synced { background:#46b450; }
			#woo2odoo-sync-tab .b-pending { background:#ffb900; color:#3c2c00; }
			#woo2odoo-sync-tab .b-failed { background:#dc3232; }
			#woo2odoo-sync-tab .b-never { background:#999; }
			#woo2odoo-sync-tab .woo2odoo-err { color:#b32d2e; font-size:11px; margin-top:3px; max-width:260px; }
			#woo2odoo-sync-tab .woo2odoo-pagination { margin:14px 0; display:flex; gap:4px; flex-wrap:wrap; }
			#woo2odoo-sync-tab .woo2odoo-pagination .page { padding:3px 9px; border:1px solid #c3c4c7; border-radius:3px; text-decoration:none; font-size:13px; background:#fff; }
			#woo2odoo-sync-tab .woo2odoo-pagination .page.current { background:#2271b1; color:#fff; border-color:#2271b1; }
			#woo2odoo-sync-tab #woo2odoo-bulk-progress { margin:12px 0; padding:10px 14px; background:#fff; border-left:4px solid #2271b1; font-size:13px; }
			#woo2odoo-sync-tab .row-busy { opacity:.5; }
		</style>
		<?php
	}

	private static function print_script(): void {
		?>
		<script>
		( function () {
			var wrap  = document.getElementById( 'woo2odoo-sync-tab' );
			if ( ! wrap ) { return; }
			var nonce = wrap.getAttribute( 'data-nonce' );
			var ajaxurl = window.ajaxurl || '/wp-admin/admin-ajax.php';

			function post( data ) {
				var body = new URLSearchParams( data );
				body.append( 'nonce', nonce );
				return fetch( ajaxurl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				} ).then( function ( r ) { return r.json(); } );
			}

			function badge( status ) {
				var label = status === 'never' ? 'sin intentar' : status;
				return '<span class="woo2odoo-badge b-' + status + '">' + label + '</span>';
			}

			// Single-row sync (updates the row in place).
			wrap.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.woo2odoo-sync-one' );
				if ( ! btn ) { return; }
				var id  = btn.getAttribute( 'data-order-id' );
				var row = document.getElementById( 'woo2odoo-row-' + id );
				btn.disabled = true;
				var orig = btn.textContent;
				btn.textContent = 'Sincronizando…';
				row.classList.add( 'row-busy' );

				post( { action: 'woo2odoo_sync_order', order_id: id } ).then( function ( res ) {
					row.classList.remove( 'row-busy' );
					btn.disabled = false;
					btn.textContent = orig;
					if ( ! res || ! res.success ) {
						alert( 'Error de red o permisos.' );
						return;
					}
					var d = res.data;
					row.querySelector( '.cell-so' ).textContent  = d.so || '—';
					row.querySelector( '.cell-inv' ).textContent = d.invoice || '—';
					row.querySelector( '.cell-pay' ).textContent = d.payment || '—';
					var statusCell = row.querySelector( '.cell-status' );
					statusCell.innerHTML = badge( d.status );
					if ( d.status === 'failed' && d.error ) {
						var err = document.createElement( 'div' );
						err.className = 'woo2odoo-err';
						err.title = d.error;
						err.textContent = d.error;
						statusCell.appendChild( err );
					}
				} ).catch( function () {
					row.classList.remove( 'row-busy' );
					btn.disabled = false;
					btn.textContent = orig;
					alert( 'Error de red.' );
				} );
			} );

			// ── Selection + background enqueue ──
			var selBtn   = wrap.querySelector( '.woo2odoo-sync-selected' );
			var selCount = wrap.querySelector( '.sel-count' );
			var checkAll = wrap.querySelector( '.woo2odoo-check-all' );

			function rowChecks() {
				return Array.prototype.slice.call( wrap.querySelectorAll( '.woo2odoo-check' ) );
			}
			function selectedIds() {
				return rowChecks().filter( function ( c ) { return c.checked; } ).map( function ( c ) { return c.value; } );
			}
			function refreshSelection() {
				var ids = selectedIds();
				selCount.textContent = ids.length;
				selBtn.disabled = ids.length === 0;
				if ( checkAll ) {
					var all = rowChecks();
					checkAll.checked = all.length > 0 && ids.length === all.length;
					checkAll.indeterminate = ids.length > 0 && ids.length < all.length;
				}
			}

			if ( checkAll ) {
				checkAll.addEventListener( 'change', function () {
					rowChecks().forEach( function ( c ) { c.checked = checkAll.checked; } );
					refreshSelection();
				} );
			}
			wrap.addEventListener( 'change', function ( e ) {
				if ( e.target.classList.contains( 'woo2odoo-check' ) ) { refreshSelection(); }
			} );

			if ( selBtn ) {
				selBtn.addEventListener( 'click', function () {
					var ids = selectedIds();
					if ( ! ids.length ) { return; }
					var prog = document.getElementById( 'woo2odoo-bulk-progress' );
					selBtn.disabled = true;
					prog.style.display = 'block';
					prog.textContent = 'Encolando ' + ids.length + ' pedido(s)…';

					var body = new URLSearchParams();
					body.append( 'action', 'woo2odoo_enqueue_sync' );
					body.append( 'nonce', nonce );
					ids.forEach( function ( id ) { body.append( 'order_ids[]', id ); } );

					fetch( ajaxurl, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: body.toString()
					} ).then( function ( r ) { return r.json(); } ).then( function ( res ) {
						if ( ! res || ! res.success ) {
							prog.textContent = ( res && res.data && res.data.msg ) ? res.data.msg : 'Error de red o permisos.';
							refreshSelection();
							return;
						}
						// Reflect "en cola" on the selected rows immediately.
						ids.forEach( function ( id ) {
							var row = document.getElementById( 'woo2odoo-row-' + id );
							if ( ! row ) { return; }
							row.querySelector( '.cell-status' ).innerHTML = badge( 'pending' );
							var chk = row.querySelector( '.woo2odoo-check' );
							if ( chk ) { chk.checked = false; }
						} );
						refreshSelection();
						prog.textContent = res.data.queued + ' pedido(s) encolados. Se sincronizan en segundo plano — actualizá la página para ver el avance.';
					} ).catch( function () {
						prog.textContent = 'Error de red.';
						refreshSelection();
					} );
				} );
			}
		} )();
		</script>
		<?php
	}
}
