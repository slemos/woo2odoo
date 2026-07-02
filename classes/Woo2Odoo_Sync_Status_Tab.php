<?php
/**
 * Woo2Odoo_Sync_Status_Tab
 *
 * Tab "Estado de Sync" en la página de configuración del plugin.
 * Muestra una tabla de pedidos fallidos/pendientes con botón de reintento.
 * También registra el admin notice cuando hay errores pendientes.
 *
 * @package Woo2Odoo
 */
namespace Woo2Odoo;

class Woo2Odoo_Sync_Status_Tab {

	public static function register(): void {
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_notice' ) );
		add_action( 'wp_ajax_woo2odoo_retry_order', array( __CLASS__, 'ajax_retry_order' ) );
		add_action( 'wp_ajax_woo2odoo_retry_all', array( __CLASS__, 'ajax_retry_all' ) );
	}

	// ── Admin notice ─────────────────────────────────────────────────────────

	public static function maybe_show_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$dismissed_until = get_transient( 'woo2odoo_notice_dismissed_' . get_current_user_id() );
		if ( $dismissed_until ) {
			return;
		}

		$count = self::count_failed_orders();
		if ( $count === 0 ) {
			return;
		}

		$tab_url = admin_url( 'options-general.php?page=woo2odoo-plugin&tab=sync-status' );
		$tab_url = wp_nonce_url( $tab_url, 'woo2odoo_plugin_switch_settings_tab', 'woo2odoo_plugin_switch_settings_tab' );
		$dismiss_nonce = wp_create_nonce( 'woo2odoo_dismiss_notice' );

		printf(
			'<div class="notice notice-warning is-dismissible" id="woo2odoo-sync-notice">
				<p>
					<strong>Woo2Odoo:</strong> %s
					<a href="%s" style="margin-left:8px;">%s &rarr;</a>
				</p>
				<button type="button" class="notice-dismiss" onclick="woo2odooDismissNotice(\'%s\')">
					<span class="screen-reader-text">%s</span>
				</button>
			</div>
			<script>
			function woo2odooDismissNotice(nonce) {
				fetch(ajaxurl, {
					method: "POST",
					headers: {"Content-Type":"application/x-www-form-urlencoded"},
					body: "action=woo2odoo_retry_order&dismiss=1&nonce=" + nonce
				});
			}
			</script>',
			sprintf(
				_n(
					'%d pedido no pudo sincronizarse con Odoo.',
					'%d pedidos no pudieron sincronizarse con Odoo.',
					$count,
					'woo2odoo-plugin'
				),
				$count
			),
			esc_url( $tab_url ),
			esc_html__( 'Ver y reintentar', 'woo2odoo-plugin' ),
			esc_js( $dismiss_nonce ),
			esc_html__( 'Cerrar este aviso', 'woo2odoo-plugin' )
		);
	}

	// ── Tab renderer ─────────────────────────────────────────────────────────

	public static function render_tab(): void {
		$filter  = isset( $_GET['sync_filter'] ) ? sanitize_key( $_GET['sync_filter'] ) : 'failed';
		$filters = array(
			'failed'  => __( 'Con error', 'woo2odoo-plugin' ),
			'pending' => __( 'Pendientes', 'woo2odoo-plugin' ),
			'synced'  => __( 'Sincronizados', 'woo2odoo-plugin' ),
			'none'    => __( 'Sin intentar', 'woo2odoo-plugin' ),
			'all'     => __( 'Todos', 'woo2odoo-plugin' ),
		);

		$counts = self::count_by_status();
		$orders = self::get_orders_by_status( $filter );
		$retry_nonce = wp_create_nonce( 'woo2odoo_retry' );
		$tab_base = admin_url( 'options-general.php?page=woo2odoo-plugin&tab=sync-status' );
		$tab_base = wp_nonce_url( $tab_base, 'woo2odoo_plugin_switch_settings_tab', 'woo2odoo_plugin_switch_settings_tab' );

		?>
		<div id="woo2odoo-sync-status-tab">
		<style>
			#woo2odoo-sync-status-tab { max-width: 980px; }
			.woo2odoo-sync-summary { display:flex; gap:12px; margin:16px 0; flex-wrap:wrap; }
			.woo2odoo-sync-count { background:#fff; border:1px solid #ddd; border-radius:4px; padding:10px 18px; text-align:center; min-width:100px; }
			.woo2odoo-sync-count .count { font-size:28px; font-weight:700; line-height:1.1; display:block; }
			.woo2odoo-sync-count .label { font-size:12px; color:#666; display:block; margin-top:2px; }
			.woo2odoo-sync-count.error .count { color:#b32d2e; }
			.woo2odoo-sync-count.warning .count { color:#996600; }
			.woo2odoo-sync-count.success .count { color:#00834c; }
			.woo2odoo-filter-bar { margin:12px 0; display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
			.woo2odoo-filter-bar a { text-decoration:none; padding:4px 12px; border:1px solid #c3c4c7; border-radius:3px; font-size:13px; color:#2c3338; background:#fff; }
			.woo2odoo-filter-bar a.active { background:#2271b1; color:#fff; border-color:#2271b1; }
			.woo2odoo-retry-all { margin-left:auto; }
			#woo2odoo-sync-table { width:100%; border-collapse:collapse; margin-top:8px; }
			#woo2odoo-sync-table th { text-align:left; padding:8px 10px; background:#f6f7f7; border-bottom:2px solid #ddd; font-size:13px; }
			#woo2odoo-sync-table td { padding:8px 10px; border-bottom:1px solid #f0f0f0; font-size:13px; vertical-align:middle; }
			#woo2odoo-sync-table tr:hover td { background:#fafafa; }
			.woo2odoo-status-badge { display:inline-block; padding:2px 8px; border-radius:3px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.3px; }
			.woo2odoo-status-badge.failed { background:#fce8e8; color:#b32d2e; }
			.woo2odoo-status-badge.pending { background:#fff3cd; color:#996600; }
			.woo2odoo-status-badge.synced { background:#e8f5e9; color:#00834c; }
			.woo2odoo-status-badge.none { background:#f0f0f0; color:#666; }
			.woo2odoo-error-text { color:#b32d2e; font-size:12px; margin-top:2px; }
			.woo2odoo-retry-btn { font-size:12px; }
			.woo2odoo-spinner { display:none; width:16px; height:16px; vertical-align:middle; margin-left:4px; }
			.woo2odoo-result { font-size:12px; margin-left:6px; }
			.woo2odoo-result.ok { color:#00834c; }
			.woo2odoo-result.err { color:#b32d2e; }
		</style>

		<div class="woo2odoo-sync-summary">
			<div class="woo2odoo-sync-count error">
				<span class="count"><?php echo (int) ( $counts['failed'] ?? 0 ); ?></span>
				<span class="label">Con error</span>
			</div>
			<div class="woo2odoo-sync-count warning">
				<span class="count"><?php echo (int) ( $counts['pending'] ?? 0 ); ?></span>
				<span class="label">Pendientes</span>
			</div>
			<div class="woo2odoo-sync-count success">
				<span class="count"><?php echo (int) ( $counts['synced'] ?? 0 ); ?></span>
				<span class="label">Sincronizados</span>
			</div>
			<div class="woo2odoo-sync-count">
				<span class="count"><?php echo (int) ( $counts['none'] ?? 0 ); ?></span>
				<span class="label">Sin intentar</span>
			</div>
		</div>

		<div class="woo2odoo-filter-bar">
			<?php foreach ( $filters as $key => $label ) :
				$count_badge = '';
				if ( isset( $counts[ $key ] ) && $counts[ $key ] > 0 ) {
					$count_badge = ' (' . (int) $counts[ $key ] . ')';
				}
				$url = add_query_arg( 'sync_filter', $key, $tab_base );
				$active = ( $filter === $key ) ? ' active' : '';
			?>
			<a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( trim( $active ) ); ?>"><?php echo esc_html( $label . $count_badge ); ?></a>
			<?php endforeach; ?>

			<?php if ( ( $counts['failed'] ?? 0 ) > 0 ) : ?>
			<button type="button" class="button button-primary woo2odoo-retry-all" data-nonce="<?php echo esc_attr( $retry_nonce ); ?>">
				Reintentar todos los errores (<?php echo (int) $counts['failed']; ?>)
			</button>
			<?php endif; ?>
		</div>

		<?php if ( empty( $orders ) ) : ?>
			<p style="color:#666;margin-top:16px;">
				<?php esc_html_e( 'No hay pedidos en este estado.', 'woo2odoo-plugin' ); ?>
			</p>
		<?php else : ?>
		<table id="woo2odoo-sync-table">
			<thead>
				<tr>
					<th>Pedido</th>
					<th>Cliente</th>
					<th>Estado sync</th>
					<th>Error / Info</th>
					<th>Último intento</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $orders as $order ) :
				$order_id    = $order->get_id();
				$status      = $order->get_meta( '_woo2odoo_sync_status' ) ?: 'none';
				$error       = $order->get_meta( '_woo2odoo_sync_error' );
				$date        = $order->get_meta( '_woo2odoo_sync_date' );
				$customer    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
				$order_url   = get_edit_post_link( $order_id ) ?: admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
				$badge_label = array(
					'synced'  => 'Sincronizado',
					'failed'  => 'Error',
					'pending' => 'Pendiente',
					'none'    => 'Sin intentar',
				);
			?>
				<tr id="woo2odoo-row-<?php echo (int) $order_id; ?>">
					<td><a href="<?php echo esc_url( $order_url ); ?>">#<?php echo (int) $order_id; ?></a></td>
					<td><?php echo esc_html( $customer ?: $order->get_billing_email() ); ?></td>
					<td>
						<span class="woo2odoo-status-badge <?php echo esc_attr( $status ); ?>">
							<?php echo esc_html( $badge_label[ $status ] ?? $status ); ?>
						</span>
					</td>
					<td>
						<?php if ( $error ) : ?>
							<span class="woo2odoo-error-text"><?php echo esc_html( $error ); ?></span>
						<?php elseif ( $status === 'synced' ) :
							$so_id = $order->get_meta( '_odoo_sale_order_id' );
							if ( $so_id ) echo esc_html( "SO ID {$so_id}" );
						endif; ?>
					</td>
					<td><?php echo $date ? esc_html( wp_date( 'd M, H:i', strtotime( $date ) ) ) : '—'; ?></td>
					<td>
						<?php if ( $status !== 'synced' ) : ?>
						<button type="button"
							class="button button-secondary woo2odoo-retry-btn"
							data-order-id="<?php echo (int) $order_id; ?>"
							data-nonce="<?php echo esc_attr( $retry_nonce ); ?>">
							Reintentar
						</button>
						<img src="<?php echo esc_url( admin_url( 'images/spinner.gif' ) ); ?>" class="woo2odoo-spinner" alt="">
						<span class="woo2odoo-result"></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
		</div>

		<script>
		(function($) {
			// Retry individual
			$(document).on('click', '.woo2odoo-retry-btn', function() {
				var $btn    = $(this);
				var $row    = $btn.closest('tr');
				var $spin   = $row.find('.woo2odoo-spinner');
				var $result = $row.find('.woo2odoo-result');
				var orderId = $btn.data('order-id');
				var nonce   = $btn.data('nonce');

				$btn.prop('disabled', true);
				$spin.show();
				$result.text('').removeClass('ok err');

				$.post(ajaxurl, {
					action:   'woo2odoo_retry_order',
					order_id: orderId,
					nonce:    nonce
				}, function(response) {
					$spin.hide();
					$btn.prop('disabled', false);
					if (response.success) {
						$result.addClass('ok').text('✓ Sincronizado');
						$row.find('.woo2odoo-status-badge')
							.removeClass('failed pending none')
							.addClass('synced')
							.text('Sincronizado');
						$btn.remove();
					} else {
						$result.addClass('err').text('✗ ' + (response.data || 'Error'));
					}
				}).fail(function() {
					$spin.hide();
					$btn.prop('disabled', false);
					$result.addClass('err').text('✗ Error de red');
				});
			});

			// Retry all failed
			$(document).on('click', '.woo2odoo-retry-all', function() {
				var $btn   = $(this);
				var nonce  = $btn.data('nonce');
				var orig   = $btn.text();

				$btn.prop('disabled', true).text('Reintentando...');

				$.post(ajaxurl, {
					action: 'woo2odoo_retry_all',
					nonce:  nonce
				}, function(response) {
					$btn.prop('disabled', false);
					if (response.success) {
						$btn.text(orig);
						alert('Completado: ' + response.data.synced + ' sincronizados, ' + response.data.failed + ' con error.');
						location.reload();
					} else {
						$btn.text(orig);
						alert('Error: ' + (response.data || 'desconocido'));
					}
				}).fail(function() {
					$btn.prop('disabled', false).text(orig);
					alert('Error de red.');
				});
			});
		})(jQuery);
		</script>
		<?php
	}

	// ── AJAX handlers ────────────────────────────────────────────────────────

	public static function ajax_retry_order(): void {
		// Handle notice dismiss
		if ( ! empty( $_POST['dismiss'] ) ) {
			check_ajax_referer( 'woo2odoo_dismiss_notice', 'nonce' );
			set_transient( 'woo2odoo_notice_dismissed_' . get_current_user_id(), 1, DAY_IN_SECONDS );
			wp_send_json_success();
		}

		check_ajax_referer( 'woo2odoo_retry', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Sin permisos' );
		}

		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		if ( ! $order_id ) {
			wp_send_json_error( 'Order ID inválido' );
		}

		$manager = new Woo2Odoo_Order_Manager();
		$ok      = $manager->order_sync( $order_id );

		if ( $ok ) {
			wp_send_json_success();
		} else {
			$order = wc_get_order( $order_id );
			$error = $order ? $order->get_meta( '_woo2odoo_sync_error' ) : 'Sync failed';
			wp_send_json_error( $error ?: 'Sync failed' );
		}
	}

	public static function ajax_retry_all(): void {
		check_ajax_referer( 'woo2odoo_retry', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Sin permisos' );
		}

		$order_ids = wc_get_orders( array(
			'limit'      => 100,
			'return'     => 'ids',
			'meta_query' => array(
				array(
					'key'   => '_woo2odoo_sync_status',
					'value' => 'failed',
				),
			),
		) );

		$synced = 0;
		$failed = 0;
		$manager = new Woo2Odoo_Order_Manager();

		foreach ( $order_ids as $id ) {
			if ( $manager->order_sync( (int) $id ) ) {
				$synced++;
			} else {
				$failed++;
			}
		}

		wp_send_json_success( array( 'synced' => $synced, 'failed' => $failed ) );
	}

	// ── Query helpers ────────────────────────────────────────────────────────

	private static function count_failed_orders(): int {
		return (int) count( wc_get_orders( array(
			'limit'      => -1,
			'return'     => 'ids',
			'meta_query' => array(
				array(
					'key'   => '_woo2odoo_sync_status',
					'value' => 'failed',
				),
			),
		) ) );
	}

	private static function count_by_status(): array {
		$statuses = array( 'failed', 'pending', 'synced' );
		$counts   = array();

		foreach ( $statuses as $s ) {
			$counts[ $s ] = count( wc_get_orders( array(
				'limit'      => -1,
				'return'     => 'ids',
				'meta_query' => array(
					array( 'key' => '_woo2odoo_sync_status', 'value' => $s ),
				),
			) ) );
		}

		// "none" = orders with processing/on-hold status but no sync meta
		$counts['none'] = count( wc_get_orders( array(
			'limit'      => -1,
			'return'     => 'ids',
			'status'     => array( 'processing', 'on-hold' ),
			'meta_query' => array(
				array(
					'key'     => '_woo2odoo_sync_status',
					'compare' => 'NOT EXISTS',
				),
			),
		) ) );

		return $counts;
	}

	private static function get_orders_by_status( string $filter ): array {
		$args = array( 'limit' => 50, 'orderby' => 'date', 'order' => 'DESC' );

		if ( $filter === 'none' ) {
			$args['status']     = array( 'processing', 'on-hold' );
			$args['meta_query'] = array(
				array( 'key' => '_woo2odoo_sync_status', 'compare' => 'NOT EXISTS' ),
			);
		} elseif ( $filter === 'all' ) {
			// no meta filter
		} else {
			$args['meta_query'] = array(
				array( 'key' => '_woo2odoo_sync_status', 'value' => $filter ),
			);
		}

		return wc_get_orders( $args );
	}
}
