<?php
/**
 * Tests portables para Woo2Odoo_Order_Manager.
 *
 * Corren via wp-env (WordPress + WooCommerce disponibles).
 * El cliente Odoo se mockea — NO se requiere conexión a Odoo.
 *
 * Ejecutar:
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/woo2odoo vendor/bin/phpunit
 */
namespace Woo2Odoo;

use PHPUnit\Framework\TestCase;

/**
 * Namespaced wc_get_order() stub.
 *
 * Woo2Odoo_Order_Manager (namespace Woo2Odoo) calls the unqualified
 * wc_get_order(); PHP resolves that against Woo2Odoo\wc_get_order() before
 * falling back to WooCommerce's global one — same technique already used by
 * tests/test-ordermanager.php. Only cancel_sync() tests register a mock
 * order here; every other test in this file is unaffected (returns false).
 */
function wc_get_order( $order_id ) {
	return WPUnit_Order_Manager_Test::$mock_orders[ $order_id ] ?? false;
}

/**
 * @covers Woo2Odoo\Woo2Odoo_Order_Manager
 */
class WPUnit_Order_Manager_Test extends TestCase {

	private Woo2Odoo_Order_Manager $manager;
	private $mock_client;

	/** @var \WC_Order[] Registry consumed by the namespaced wc_get_order() stub above. */
	public static array $mock_orders = array();

	protected function setUp(): void {
		$this->mock_client = $this->createMock( Woo2Odoo_Client::class );
		$this->manager      = new Woo2Odoo_Order_Manager( $this->mock_client );
		self::$mock_orders  = array();
	}

	protected function tearDown(): void {
		self::$mock_orders = array();
	}

	// =========================================================================
	// format_rut
	// =========================================================================

	public function test_format_rut_standard_dash(): void {
		$this->assertSame( '12345678-9', $this->manager->format_rut( '12345678-9' ) );
	}

	public function test_format_rut_strips_dots_and_keeps_dash(): void {
		$this->assertSame( '12345678-9', $this->manager->format_rut( '12.345.678-9' ) );
	}

	/** El bug original: format_rut eliminaba la K, produciendo un RUT incorrecto. */
	public function test_format_rut_preserves_uppercase_k(): void {
		$this->assertSame( '14501736-K', $this->manager->format_rut( '14501736-K' ) );
	}

	public function test_format_rut_uppercases_lowercase_k(): void {
		$this->assertSame( '14501736-K', $this->manager->format_rut( '14501736-k' ) );
	}

	public function test_format_rut_strips_dots_and_preserves_k(): void {
		$this->assertSame( '14501736-K', $this->manager->format_rut( '14.501.736-K' ) );
	}

	public function test_format_rut_empty_returns_empty(): void {
		$this->assertSame( '', $this->manager->format_rut( '' ) );
	}

	public function test_format_rut_single_char_returns_as_is(): void {
		// String de 1 char no tiene cuerpo separable del dígito verificador.
		$this->assertSame( '9', $this->manager->format_rut( '9' ) );
	}

	// =========================================================================
	// get_payment_info_from_wc_order (método privado — acceso via reflexión)
	// =========================================================================

	/**
	 * Invoca el método privado get_payment_info_from_wc_order.
	 *
	 * @return array|false
	 */
	private function call_payment_info( \WC_Order $order ) {
		$ref = new \ReflectionMethod( $this->manager, 'get_payment_info_from_wc_order' );
		$ref->setAccessible( true );
		return $ref->invoke( $this->manager, $order );
	}

	/**
	 * Crea un mock de WC_Order con los parámetros dados.
	 *
	 * @param array{
	 *   payment_method?: string,
	 *   total?:          float,
	 *   id?:             int,
	 *   meta?:           array<string,string>,
	 *   date_paid?:      \WC_DateTime|null,
	 * } $config
	 */
	private function mock_order( array $config ): \WC_Order {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_payment_method' )->willReturn( $config['payment_method'] ?? '' );
		$order->method( 'get_total' )->willReturn( (float) ( $config['total'] ?? 0.0 ) );
		$order->method( 'get_id' )->willReturn( (int) ( $config['id'] ?? 1 ) );
		$order->method( 'get_status' )->willReturn( $config['status'] ?? 'processing' );

		$meta = $config['meta'] ?? [];
		$order->method( 'get_meta' )->willReturnCallback(
			static fn( string $key ) => $meta[ $key ] ?? ''
		);

		$order->method( 'get_date_paid' )->willReturn( $config['date_paid'] ?? null );

		return $order;
	}

	// --- BACS (Transferencia bancaria) ---

	public function test_bacs_returns_payment_array(): void {
		$order  = $this->mock_order( [ 'payment_method' => 'bacs', 'total' => 15990.0, 'id' => 42 ] );
		$result = $this->call_payment_info( $order );

		$this->assertIsArray( $result );
		$this->assertSame( 15990.0, $result['amount'] );
		$this->assertStringContainsString( '42', $result['memo'] );
		$this->assertStringContainsString( 'Transferencia', $result['memo'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $result['date'] );
	}

	public function test_bacs_uses_date_paid_when_available(): void {
		$wc_date = $this->createMock( \WC_DateTime::class );
		$wc_date->method( 'format' )->willReturn( '2026-07-06' );

		$order  = $this->mock_order( [
			'payment_method' => 'bacs',
			'total'          => 9990.0,
			'id'             => 1,
			'date_paid'      => $wc_date,
		] );
		$result = $this->call_payment_info( $order );

		$this->assertSame( '2026-07-06', $result['date'] );
	}

	public function test_bacs_falls_back_to_today_when_no_date_paid(): void {
		$order  = $this->mock_order( [ 'payment_method' => 'bacs', 'total' => 1000.0, 'id' => 2 ] );
		$result = $this->call_payment_info( $order );

		$this->assertSame( date( 'Y-m-d' ), $result['date'] );
	}

	public function test_bacs_on_hold_returns_false(): void {
		$order = $this->mock_order( [
			'payment_method' => 'bacs',
			'total'          => 15990.0,
			'status'         => 'on-hold',
		] );

		$this->assertFalse( $this->call_payment_info( $order ), 'BACS en on-hold NO debe generar payment (transferencia aún no confirmada).' );
	}

	// --- Transbank WebPay Plus ---

	public function test_transbank_authorized_returns_payment_array(): void {
		$order  = $this->mock_order( [
			'payment_method' => 'transbank_webpay_plus_rest',
			'total'          => 29990.0,
			'id'             => 99,
			'meta'           => [
				'transactionStatus' => 'Autorizada',
				'amount'            => '29990',
				'transactionDate'   => '04-07-2026 10:00:00 -04:00',
			],
		] );
		$result = $this->call_payment_info( $order );

		$this->assertIsArray( $result );
		$this->assertSame( 29990.0, $result['amount'] );
		$this->assertSame( '2026-07-04', $result['date'] );
		$this->assertStringContainsString( '99', $result['memo'] );
	}

	public function test_transbank_uses_order_total_when_amount_meta_empty(): void {
		$order  = $this->mock_order( [
			'payment_method' => 'transbank_webpay_plus_rest',
			'total'          => 12990.0,
			'meta'           => [
				'transactionStatus' => 'Autorizada',
				'amount'            => '',
			],
		] );
		$result = $this->call_payment_info( $order );

		$this->assertSame( 12990.0, $result['amount'] );
	}

	public function test_transbank_not_authorized_returns_false(): void {
		$order = $this->mock_order( [
			'payment_method' => 'transbank_webpay_plus_rest',
			'meta'           => [ 'transactionStatus' => 'Fallida' ],
		] );

		$this->assertFalse( $this->call_payment_info( $order ) );
	}

	public function test_transbank_empty_status_returns_false(): void {
		$order = $this->mock_order( [
			'payment_method' => 'transbank_webpay_plus_rest',
			'meta'           => [],
		] );

		$this->assertFalse( $this->call_payment_info( $order ) );
	}

	// --- MercadoPago ---

	public function test_mercadopago_with_payment_ids_returns_array(): void {
		$order  = $this->mock_order( [
			'payment_method' => 'woo-mercado-pago-basic',
			'total'          => 8990.0,
			'id'             => 7,
			'meta'           => [
				'_Mercado_Pago_Payment_IDs' => '12345',
				'_paid_date'               => '2026-07-06 12:00:00',
			],
		] );
		$result = $this->call_payment_info( $order );

		$this->assertIsArray( $result );
		$this->assertSame( 8990.0, $result['amount'] );
		$this->assertSame( '2026-07-06', $result['date'] );
	}

	public function test_mercadopago_falls_back_to_get_date_paid_when_meta_empty(): void {
		$wc_date = $this->createMock( \WC_DateTime::class );
		$wc_date->method( 'format' )->willReturn( '2026-07-06 09:00:00' );

		$order  = $this->mock_order( [
			'payment_method' => 'woo-mercado-pago-basic',
			'total'          => 5000.0,
			'id'             => 8,
			'meta'           => [ '_Mercado_Pago_Payment_IDs' => 'abc', '_paid_date' => '' ],
			'date_paid'      => $wc_date,
		] );
		$result = $this->call_payment_info( $order );

		$this->assertIsArray( $result );
		$this->assertSame( '2026-07-06', $result['date'] );
	}

	public function test_mercadopago_without_payment_ids_returns_false(): void {
		$order = $this->mock_order( [
			'payment_method' => 'woo-mercado-pago-basic',
			'meta'           => [ '_Mercado_Pago_Payment_IDs' => '' ],
		] );

		$this->assertFalse( $this->call_payment_info( $order ) );
	}

	// --- Métodos de pago no soportados ---

	public function test_cod_returns_false(): void {
		$order = $this->mock_order( [ 'payment_method' => 'cod' ] );
		$this->assertFalse( $this->call_payment_info( $order ) );
	}

	public function test_unknown_gateway_returns_false(): void {
		$order = $this->mock_order( [ 'payment_method' => 'my_custom_gateway' ] );
		$this->assertFalse( $this->call_payment_info( $order ) );
	}

	// =========================================================================
	// Registro de hooks
	// =========================================================================

	public function test_processing_hook_is_registered(): void {
		$this->assertGreaterThan(
			0,
			has_action( 'woocommerce_order_status_processing' ),
			'El hook woocommerce_order_status_processing debe estar registrado.'
		);
	}

	public function test_on_hold_hook_is_registered(): void {
		$this->assertGreaterThan(
			0,
			has_action( 'woocommerce_order_status_on-hold' ),
			'El hook woocommerce_order_status_on-hold debe estar registrado.'
		);
	}

	public function test_refund_hook_is_registered(): void {
		$this->assertGreaterThan(
			0,
			has_action( 'woocommerce_order_refunded' ),
			'El hook woocommerce_order_refunded debe estar registrado.'
		);
	}

	public function test_cancelled_hook_is_registered(): void {
		$this->assertGreaterThan(
			0,
			has_action( 'woocommerce_order_status_cancelled' ),
			'El hook woocommerce_order_status_cancelled debe estar registrado.'
		);
	}

	// =========================================================================
	// cancel_sync
	// =========================================================================

	/**
	 * Builds a mock \WC_Order for cancel_sync() tests and registers it so the
	 * namespaced wc_get_order() stub above can return it, and captures every
	 * order note added into $notes (by reference) for assertions.
	 *
	 * @param int        $order_id WC order ID.
	 * @param array      $meta     Meta key => value map (get_meta()).
	 * @param string[]   $notes    Captured by reference — every add_order_note() call lands here.
	 */
	private function mock_cancel_order( int $order_id, array $meta, array &$notes ): \WC_Order {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( $order_id );
		$order->method( 'get_meta' )->willReturnCallback(
			static fn( string $key ) => $meta[ $key ] ?? ''
		);
		$order->method( 'add_order_note' )->willReturnCallback(
			function ( $note ) use ( &$notes ) {
				$notes[] = $note;
				return 1;
			}
		);
		// update_meta_data / save / delete_meta_data are no-ops on the mock — cancel_sync
		// only needs them not to fatal; their effect isn't observable through \WC_Order here.

		self::$mock_orders[ $order_id ] = $order;
		return $order;
	}

	public function test_cancel_sync_noop_when_never_synced(): void {
		$notes = [];
		$this->mock_cancel_order( 501, [ '_odoo_sale_order_id' => '' ], $notes );

		// No Odoo call at all — the order was never synced, so there is nothing to cancel.
		$this->mock_client->expects( $this->never() )->method( 'authenticate' );
		$this->mock_client->expects( $this->never() )->method( 'search_read' );
		$this->mock_client->expects( $this->never() )->method( 'execute' );

		$this->assertTrue( $this->manager->cancel_sync( 501 ) );
		$this->assertSame( [], $notes, 'No debería agregarse ninguna nota cuando el pedido nunca se sincronizó.' );
	}

	public function test_cancel_sync_is_idempotent(): void {
		$notes = [];
		$this->mock_cancel_order( 502, [
			'_odoo_sale_order_id'      => '123',
			'_woo2odoo_cancel_synced'  => 'yes',
		], $notes );

		// Already processed — a second run must not touch Odoo again.
		$this->mock_client->expects( $this->never() )->method( 'authenticate' );

		$this->assertTrue( $this->manager->cancel_sync( 502 ) );
	}

	public function test_cancel_sync_cancels_so_and_draft_invoice(): void {
		$notes = [];
		$this->mock_cancel_order( 503, [
			'_odoo_sale_order_id' => '123',
			'_woo2odoo_invoice_id' => '456',
		], $notes );

		$this->mock_client->method( 'authenticate' )->willReturn( true );
		$this->mock_client->method( 'search_read' )->willReturnCallback(
			function ( $model ) {
				if ( 'sale.order' === $model ) {
					return (object) [ 'id' => 123, 'name' => 'S00123', 'state' => 'sale' ];
				}
				if ( 'account.move' === $model ) {
					return (object) [ 'id' => 456, 'name' => 'INV456', 'state' => 'draft' ];
				}
				return null;
			}
		);

		$executed = [];
		$this->mock_client->method( 'execute' )->willReturnCallback(
			function ( $model, $method ) use ( &$executed ) {
				$executed[] = "{$model}::{$method}";
				return true;
			}
		);

		$this->assertTrue( $this->manager->cancel_sync( 503 ) );
		$this->assertContains( 'sale.order::action_cancel', $executed );
		$this->assertContains( 'account.move::button_cancel', $executed );
		$joined = implode( "\n", $notes );
		$this->assertStringContainsString( 'cancelado en Odoo', $joined );
	}

	public function test_cancel_sync_leaves_posted_invoice_untouched(): void {
		$notes = [];
		$this->mock_cancel_order( 504, [
			'_odoo_sale_order_id'  => '123',
			'_woo2odoo_invoice_id' => '456',
		], $notes );

		$this->mock_client->method( 'authenticate' )->willReturn( true );
		$this->mock_client->method( 'search_read' )->willReturnCallback(
			function ( $model ) {
				if ( 'sale.order' === $model ) {
					return (object) [ 'id' => 123, 'name' => 'S00123', 'state' => 'sale' ];
				}
				if ( 'account.move' === $model ) {
					return (object) [ 'id' => 456, 'name' => 'INV456', 'state' => 'posted' ];
				}
				return null;
			}
		);

		$executed = [];
		$this->mock_client->method( 'execute' )->willReturnCallback(
			function ( $model, $method ) use ( &$executed ) {
				$executed[] = "{$model}::{$method}";
				// A posted account.move must NEVER receive button_cancel (or any write).
				$this->assertNotSame( 'account.move', $model, "El invoice contabilizado no debe recibir la llamada '{$method}'." );
				return true;
			}
		);

		$this->assertTrue( $this->manager->cancel_sync( 504 ), 'La cancelación del SO igual debe reportarse como éxito.' );
		$this->assertContains( 'sale.order::action_cancel', $executed );
		$joined = implode( "\n", $notes );
		$this->assertStringContainsString( 'NO se canceló automáticamente', $joined );
		$this->assertStringContainsString( 'revisar manualmente', strtolower( $joined ) );
	}

	public function test_cancel_sync_degrades_gracefully_when_so_not_found(): void {
		$notes = [];
		$this->mock_cancel_order( 505, [ '_odoo_sale_order_id' => '999' ], $notes );

		$this->mock_client->method( 'authenticate' )->willReturn( true );
		// Simulate the SO having disappeared from Odoo (or an Odoo-side read error
		// already swallowed by Woo2Odoo_Client, which returns null/false, never throws).
		$this->mock_client->method( 'search_read' )->willReturn( null );
		$this->mock_client->expects( $this->never() )->method( 'execute' );

		$this->assertFalse( $this->manager->cancel_sync( 505 ) );
		$joined = implode( "\n", $notes );
		$this->assertStringContainsString( 'No se encontró el pedido de venta', $joined );
	}

	public function test_cancel_sync_returns_false_when_order_missing(): void {
		// No mock registered for 999 — the namespaced wc_get_order() stub returns false.
		$this->assertFalse( $this->manager->cancel_sync( 999 ) );
	}
}
