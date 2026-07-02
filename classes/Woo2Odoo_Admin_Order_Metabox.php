<?php
/**
 * Woo2Odoo_Admin_Order_Metabox
 *
 * Adds a meta box to the WC order edit screen showing Odoo sync state,
 * status badge and a one-click retry button for failed syncs.
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

		$order_id    = $order->get_id();
		$so_id       = $order->get_meta( '_odoo_sale_order_id' );
		$invoice_id  = $order->get_meta( '_woo2odoo_invoice_id' );
		$payment_id  = $order->get_meta( '_woo2odoo_payment_id' );
		$sync_status = $order->get_meta( '_woo2odoo_sync_status' ) ?: 'none';
		$sync_error  = $order->get_meta( '_woo2odoo_sync_error' );
		$sync_date   = $order->get_meta( '_woo2odoo_sync_date' );
		$refund_status = $order->get_meta( '_woo2odoo_refund_sync_status' );
		$refund_error  = $order->get_meta( '_woo2odoo_refund_sync_error' );

		$badge_config = array(
			'synced'  => array( 'label' => 'Sincronizado', 'color' => '#00834c', 'bg' => '#e8f5e9' ),
			'failed'  => array( 'label' => 'Error',         'color' => '#b32d2e', 'bg' => '#fce8e8' ),
			'pending' => array( 'label' => 'Pendiente',     'color' => '#996600', 'bg' => '#fff3cd' ),
			'none'    => array( 'label' => 'Sin intentar',  'color' => '#666',    'bg' => '#f0f0f0' ),
		);
		$badge = $badge_config[ $sync_status ] ?? $badge_config['none'];

		$retry_nonce = wp_create_nonce( 'woo2odoo_retry' );
		?>
		<style>
			#woo2odoo-sync-status .w2o-row {
				display:flex; justify-content:space-between; align-items:center;
				padding:5px 0; border-bottom:1px solid #f0f0f0; font-size:12px;
			}
			#woo2odoo-sync-status .w2o-row:last-child { border-bottom:none; }
			#woo2odoo-sync-status .w2o-label { color:#757575; }
			#woo2odoo-sync-status .w2o-value { font-weight:600; color:#2c3338; font-family:monospace; }
			#woo2odoo-sync-status .w2o-badge {
				display:inline-block; padding:2px 8px; border-radius:3px;
				font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.3px;
			}
			#woo2odoo-sync-status .w2o-error { color:#b32d2e; font-size:11px; margin:4px 0 6px; word-break:break-word; }
			#woo2odoo-sync-status .w2o-date  { color:#999; font-size:11px; margin-bottom:8px; }
			#woo2odoo-sync-status .w2o-retry { margin-top:6px; width:100%; }
			#woo2odoo-sync-status .w2o-spinner { display:none; width:16px; vertical-align:middle; margin-left:4px; }
			#woo2odoo-sync-status .w2o-result { font-size:12px; display:block; margin-top:4px; }
			#woo2odoo-sync-status .w2o-result.ok { color:#00834c; }
			#woo2odoo-sync-status .w2o-result.err { color:#b32d2e; }
			#woo2odoo-sync-status .w2o-divider { border:none; border-top:1px solid #f0f0f0; margin:10px 0; }
		</style>

		<div>
			<span class="w2o-badge" style="color:<?php echo esc_attr( $badge['color'] ); ?>;background:<?php echo esc_attr( $badge['bg'] ); ?>">
				<?php echo esc_html( $badge['label'] ); ?>
			</span>

			<?php if ( $sync_date ) : ?>
				<div class="w2o-date"><?php echo esc_html( wp_date( 'd M Y, H:i', strtotime( $sync_date ) ) ); ?></div>
			<?php endif; ?>

			<?php if ( $sync_error ) : ?>
				<div class="w2o-error"><?php echo esc_html( $sync_error ); ?></div>
			<?php endif; ?>

			<?php if ( $sync_status !== 'synced' ) : ?>
				<button type="button" class="button button-secondary w2o-retry"
					data-order-id="<?php echo (int) $order_id; ?>"
					data-nonce="<?php echo esc_attr( $retry_nonce ); ?>">
					Reintentar sync
				</button>
				<img src="<?php echo esc_url( admin_url( 'images/spinner.gif' ) ); ?>" class="w2o-spinner" alt="">
				<span class="w2o-result"></span>
			<?php endif; ?>

			<?php if ( $so_id || $invoice_id || $payment_id ) : ?>
				<hr class="w2o-divider">
				<?php if ( $so_id ) : ?>
				<div class="w2o-row">
					<span class="w2o-label">Sale Order</span>
					<span class="w2o-value">ID <?php echo esc_html( $so_id ); ?></span>
				</div>
				<?php endif; ?>
				<?php if ( $invoice_id ) : ?>
				<div class="w2o-row">
					<span class="w2o-label">Boleta</span>
					<span class="w2o-value">ID <?php echo esc_html( $invoice_id ); ?></span>
				</div>
				<?php endif; ?>
				<?php if ( $payment_id ) : ?>
				<div class="w2o-row">
					<span class="w2o-label">Pago</span>
					<span class="w2o-value">ID <?php echo esc_html( $payment_id ); ?></span>
				</div>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( $refund_status === 'failed' ) : ?>
				<hr class="w2o-divider">
				<span class="w2o-badge" style="color:#b32d2e;background:#fce8e8;">Refund: Error</span>
				<?php if ( $refund_error ) : ?>
					<div class="w2o-error"><?php echo esc_html( $refund_error ); ?></div>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<script>
		(function($){
			$('#woo2odoo-sync-status').on('click', '.w2o-retry', function(){
				var $btn    = $(this);
				var $spin   = $btn.siblings('.w2o-spinner');
				var $result = $btn.siblings('.w2o-result');

				$btn.prop('disabled', true);
				$spin.show();
				$result.text('').removeClass('ok err');

				$.post(ajaxurl, {
					action:   'woo2odoo_retry_order',
					order_id: $btn.data('order-id'),
					nonce:    $btn.data('nonce')
				}, function(response){
					$spin.hide();
					$btn.prop('disabled', false);
					if (response.success) {
						$result.addClass('ok').text('✓ Sincronizado');
						$btn.closest('#woo2odoo-sync-status')
							.find('.w2o-badge')
							.css({color:'#00834c', background:'#e8f5e9'})
							.text('Sincronizado');
						$btn.hide();
					} else {
						$result.addClass('err').text('✗ ' + (response.data || 'Error'));
					}
				}).fail(function(){
					$spin.hide();
					$btn.prop('disabled', false);
					$result.addClass('err').text('✗ Error de red');
				});
			});
		})(jQuery);
		</script>
		<?php
	}
}
