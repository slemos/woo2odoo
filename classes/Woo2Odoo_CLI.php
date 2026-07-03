<?php
/**
 * Woo2Odoo WP-CLI Commands
 *
 * @package Woo2Odoo
 */
namespace Woo2Odoo;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Gestiona la sincronización de pedidos WooCommerce → Odoo.
 */
class Woo2Odoo_CLI {

	/**
	 * Sincroniza pedidos con Odoo.
	 *
	 * Por defecto procesa pedidos con sync_status pending, failed o sin sincronizar.
	 * Pasa un <order_id> para sincronizar un pedido específico.
	 *
	 * ## OPTIONS
	 *
	 * [<order_id>]
	 * : ID del pedido WooCommerce a sincronizar.
	 *
	 * [--status=<sync_status>]
	 * : Estados de sync a procesar: pending, failed, never, all (separados por coma).
	 * ---
	 * default: pending,failed,never
	 * ---
	 *
	 * [--wc-status=<wc_status>]
	 * : Estados WooCommerce a incluir (separados por coma).
	 * ---
	 * default: processing,on-hold
	 * ---
	 *
	 * [--limit=<N>]
	 * : Máximo de pedidos a procesar.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--dry-run]
	 * : Lista pedidos sin sincronizar.
	 *
	 * ## EXAMPLES
	 *
	 *     # Sincronizar un pedido específico
	 *     wp woo2odoo sync 17790
	 *
	 *     # Listar pedidos fallidos sin sincronizar
	 *     wp woo2odoo sync --status=failed --dry-run
	 *
	 *     # Reintentar todos los pedidos pendientes y fallidos (máx 20)
	 *     wp woo2odoo sync --status=pending,failed --limit=20
	 *
	 *     # Sincronizar pedidos que nunca se sincronizaron
	 *     wp woo2odoo sync --status=never --wc-status=processing
	 *
	 *     # Procesar todos sin filtro de sync status
	 *     wp woo2odoo sync --status=all --wc-status=processing --limit=100
	 *
	 * @when after_wp_load
	 */
	public function sync( array $args, array $assoc_args ): void {
		$order_id   = isset( $args[0] ) ? (int) $args[0] : null;
		$dry_run    = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$limit      = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 50 );
		$status_raw = \WP_CLI\Utils\get_flag_value( $assoc_args, 'status', 'pending,failed,never' );
		$wc_status  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'wc-status', 'processing,on-hold' );

		if ( $order_id ) {
			$this->sync_single( $order_id, $dry_run );
			return;
		}

		$target_statuses = array_filter( array_map( 'trim', explode( ',', $status_raw ) ) );
		$wc_statuses     = array_filter( array_map( 'trim', explode( ',', $wc_status ) ) );

		$orders = $this->get_orders( $target_statuses, $wc_statuses, $limit );

		if ( empty( $orders ) ) {
			\WP_CLI::success( 'No se encontraron pedidos para sincronizar.' );
			return;
		}

		$count = count( $orders );
		\WP_CLI::log( sprintf(
			'Encontrados %d pedido(s) [status=%s, wc-status=%s]%s',
			$count,
			$status_raw,
			$wc_status,
			$dry_run ? ' — dry-run, no se sincronizará nada.' : ''
		) );

		if ( $dry_run ) {
			$rows = array();
			foreach ( $orders as $order ) {
				$rows[] = array(
					'ID'          => $order->get_id(),
					'Cliente'     => trim( $order->get_billing_last_name() . ' ' . $order->get_billing_first_name() ),
					'WC Status'   => $order->get_status(),
					'Sync Status' => $order->get_meta( '_woo2odoo_sync_status' ) ?: 'never',
					'Sync Date'   => $order->get_meta( '_woo2odoo_sync_date' ) ?: '—',
				);
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'ID', 'Cliente', 'WC Status', 'Sync Status', 'Sync Date' ) );
			return;
		}

		$synced = 0;
		$failed = 0;
		$bar    = \WP_CLI\Utils\make_progress_bar( 'Sincronizando', $count );

		foreach ( $orders as $order ) {
			$oid     = $order->get_id();
			$manager = new Woo2Odoo_Order_Manager();
			$result  = $manager->order_sync( $oid );

			if ( $result ) {
				\WP_CLI::log( sprintf( '  ✓ #%d sincronizado', $oid ) );
				$synced++;
			} else {
				$error = $order->get_meta( '_woo2odoo_sync_error' );
				\WP_CLI::log( sprintf( '  ✗ #%d falló%s', $oid, $error ? ": $error" : '' ) );
				$failed++;
			}

			$bar->tick();
		}

		$bar->finish();
		\WP_CLI::success( sprintf( '%d sincronizados, %d fallidos.', $synced, $failed ) );
	}

	private function sync_single( int $order_id, bool $dry_run ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			\WP_CLI::error( "Pedido #$order_id no encontrado." );
			return;
		}

		$sync_status = $order->get_meta( '_woo2odoo_sync_status' ) ?: 'never';
		$sync_error  = $order->get_meta( '_woo2odoo_sync_error' );

		\WP_CLI::log( sprintf(
			'Pedido #%d | Cliente: %s | WC: %s | Sync: %s%s',
			$order_id,
			trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			$order->get_status(),
			$sync_status,
			$sync_error ? " ($sync_error)" : ''
		) );

		if ( $dry_run ) {
			\WP_CLI::log( '(dry-run) No se ejecutó la sincronización.' );
			return;
		}

		$manager = new Woo2Odoo_Order_Manager();
		$result  = $manager->order_sync( $order_id );

		if ( $result ) {
			$order = wc_get_order( $order_id ); // reload to get updated meta
			$so_id = $order->get_meta( '_odoo_sale_order_id' );
			\WP_CLI::success( sprintf( '#%d sincronizado. SO Odoo ID: %s', $order_id, $so_id ?: '(ver log)' ) );
		} else {
			$order = wc_get_order( $order_id );
			$error = $order->get_meta( '_woo2odoo_sync_error' );
			\WP_CLI::warning( sprintf( '#%d falló%s', $order_id, $error ? ": $error" : '. Revisa WC > Estado > Logs > plugin-woo2odoo.' ) );
		}
	}

	private function get_orders( array $target_statuses, array $wc_statuses, int $limit ): array {
		$wc_statuses_prefixed = array_map(
			fn( $s ) => str_starts_with( $s, 'wc-' ) ? $s : 'wc-' . $s,
			$wc_statuses
		);

		if ( in_array( 'all', $target_statuses, true ) ) {
			return wc_get_orders( array(
				'limit'   => $limit,
				'status'  => $wc_statuses_prefixed,
				'orderby' => 'date',
				'order'   => 'DESC',
			) );
		}

		$orders   = array();
		$seen_ids = array();

		foreach ( $target_statuses as $status ) {
			if ( 'never' === $status ) {
				$meta_query = array(
					array(
						'key'     => '_woo2odoo_sync_status',
						'compare' => 'NOT EXISTS',
					),
				);
			} else {
				$meta_query = array(
					array(
						'key'   => '_woo2odoo_sync_status',
						'value' => $status,
					),
				);
			}

			$batch = wc_get_orders( array(
				'limit'      => $limit,
				'status'     => $wc_statuses_prefixed,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'meta_query' => $meta_query,
			) );

			foreach ( $batch as $order ) {
				$oid = $order->get_id();
				if ( ! isset( $seen_ids[ $oid ] ) ) {
					$orders[]        = $order;
					$seen_ids[ $oid ] = true;
				}
			}
		}

		return array_slice( $orders, 0, $limit );
	}
}
