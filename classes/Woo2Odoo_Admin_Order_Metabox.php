<?php
/**
 * Woo2Odoo_Admin_Order_Metabox
 *
 * Adds a read-only meta box to the WC order edit screen showing Odoo sync state.
 * Compatible with both classic (post-based) and HPOS order storage.
 *
 * @package Woo2Odoo
 */
namespace Woo2Odoo;

class Woo2Odoo_Admin_Order_Metabox {

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

	public static function render( $post_or_order ): void {
		$order = ( $post_or_order instanceof \WP_Post )
			? wc_get_order( $post_or_order->ID )
			: $post_or_order;

		if ( ! $order ) {
			return;
		}

		$so_id      = $order->get_meta( '_odoo_sale_order_id' );
		$invoice_id = $order->get_meta( '_woo2odoo_invoice_id' );
		$payment_id = $order->get_meta( '_woo2odoo_payment_id' );

		echo '<style>
			#woo2odoo-sync-status .woo2odoo-row {
				display: flex;
				justify-content: space-between;
				align-items: center;
				padding: 5px 0;
				border-bottom: 1px solid #f0f0f0;
				font-size: 12px;
			}
			#woo2odoo-sync-status .woo2odoo-row:last-child { border-bottom: none; }
			#woo2odoo-sync-status .woo2odoo-label { color: #757575; }
			#woo2odoo-sync-status .woo2odoo-value { font-weight: 600; color: #2c3338; font-family: monospace; }
			#woo2odoo-sync-status .woo2odoo-none { color: #999; font-style: italic; font-size: 12px; }
		</style>';

		if ( ! $so_id && ! $invoice_id && ! $payment_id ) {
			echo '<p class="woo2odoo-none">No sincronizado con Odoo.</p>';
			return;
		}

		if ( $so_id ) {
			echo '<div class="woo2odoo-row">'
				. '<span class="woo2odoo-label">Sale Order</span>'
				. '<span class="woo2odoo-value">ID ' . esc_html( $so_id ) . '</span>'
				. '</div>';
		}

		if ( $invoice_id ) {
			echo '<div class="woo2odoo-row">'
				. '<span class="woo2odoo-label">Boleta</span>'
				. '<span class="woo2odoo-value">ID ' . esc_html( $invoice_id ) . '</span>'
				. '</div>';
		}

		if ( $payment_id ) {
			echo '<div class="woo2odoo-row">'
				. '<span class="woo2odoo-label">Pago</span>'
				. '<span class="woo2odoo-value">ID ' . esc_html( $payment_id ) . '</span>'
				. '</div>';
		}
	}
}
