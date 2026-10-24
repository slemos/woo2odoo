<?php
/**
 * B2C regression: with default settings, the current Order Manager must send
 * EXACTLY the same calls and payloads to Odoo as the frozen v1.6.1 copy
 * (tests/fixtures/Legacy_Order_Manager_1_6_1.php), and leave the same WC meta.
 *
 * @package Woo2Odoo
 */

// phpcs:ignoreFile

use Woo2Odoo\Legacy\Woo2Odoo_Order_Manager as Legacy_Order_Manager;
use Woo2Odoo\Woo2Odoo_Order_Manager;

/**
 * @covers \Woo2Odoo\Woo2Odoo_Order_Manager
 */
class RegressionPayloadTest extends Woo2Odoo_Pure_TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Payment journal configured, as on B2C, so the payment path is exercised.
		$this->set_export( array( 'payment_journal_id' => '14' ) );
	}

	/**
	 * Run the same scenario through v1.6.1 and the current code, each against a
	 * fresh fake Odoo and a fresh copy of the order. Returns both results.
	 *
	 * @param callable $setup  fn(): void registers order/users (called before each run).
	 * @param callable $seed   fn(FakeOdooRpc): void seeds Odoo records.
	 * @param callable $action fn(object $manager): mixed runs the operation.
	 */
	private function run_both( callable $setup, callable $seed, callable $action ): array {
		$out = array();
		foreach ( array( 'legacy', 'current' ) as $which ) {
			WP_Stub_State::$orders = array();
			WP_Stub_State::$cache  = array();
			$setup();
			$rpc = new FakeOdooRpc();
			$seed( $rpc );
			$client  = $this->client( $rpc );
			$manager = 'legacy' === $which ? new Legacy_Order_Manager( $client ) : new Woo2Odoo_Order_Manager( $client );
			$result  = $action( $manager );
			$orders  = array();
			foreach ( WP_Stub_State::$orders as $id => $order ) {
				$meta = $order->d['meta'];
				unset( $meta['_woo2odoo_sync_date'] );
				$orders[ $id ] = array( 'meta' => $meta, 'notes' => $order->notes );
			}
			$out[ $which ] = array( 'result' => $result, 'calls' => $rpc->calls, 'orders' => $orders );
		}
		return $out;
	}

	private function assert_identical( array $both ): void {
		$this->assertNotEmpty( $both['legacy']['calls'], 'scenario made no Odoo calls' );
		$this->assertSame( $both['legacy']['result'], $both['current']['result'], 'return value differs' );
		$this->assertSame(
			json_encode( $both['legacy']['calls'], JSON_PRETTY_PRINT ),
			json_encode( $both['current']['calls'], JSON_PRETTY_PRINT ),
			'Odoo calls/payloads differ from v1.6.1'
		);
		$this->assertSame( $both['legacy']['orders'], $both['current']['orders'], 'WC order meta/notes differ from v1.6.1' );
	}

	private function no_seed(): callable {
		return static function ( FakeOdooRpc $rpc ) {};
	}

	public function test_guest_boleta_with_iva_shipping_and_coupon_is_identical(): void {
		$both = $this->run_both(
			fn() => $this->make_order( 501 ),
			$this->no_seed(),
			static fn( $m ) => $m->order_sync( 501 )
		);
		$this->assert_identical( $both );
		$this->assertTrue( $both['current']['result'] );

		// Sanity: the scenario really covers what it claims.
		$creates = array_values( array_filter( $both['current']['calls'], static fn( $c ) => 'create' === $c[0] ) );
		$models  = array_map( static fn( $c ) => $c[1], $creates );
		$this->assertContains( 'sale.order', $models );
		$this->assertContains( 'account.move', $models );
		$this->assertContains( 'account.payment', $models );
		$shipping = array_values( array_filter( $creates, static fn( $c ) => 'sale.order.line' === $c[1] && 1229 === $c[2]['product_id'] ) );
		$this->assertCount( 1, $shipping );
		$this->assertSame( round( 3990 / 1.19, 2 ), $shipping[0][2]['price_unit'] );
	}

	public function test_guest_factura_is_identical(): void {
		$both = $this->run_both(
			fn() => $this->make_order( 502, array( 'meta' => $this->factura_meta(), 'billing' => array_merge( $this->make_order( 1, array() )->d['billing'], array( 'company' => 'Insumos SpA' ) ) ) ),
			$this->no_seed(),
			static fn( $m ) => $m->order_sync( 502 )
		);
		$this->assert_identical( $both );
		$move = array_values( array_filter( $both['current']['calls'], static fn( $c ) => 'create' === $c[0] && 'account.move' === $c[1] ) );
		$this->assertSame( 1, $move[0][2]['l10n_latam_document_type_id'], 'factura document type' );
	}

	public function test_logged_in_boleta_from_user_meta_is_identical(): void {
		$both = $this->run_both(
			function () {
				$user = $this->make_user( 77, 'ana.account@example.com', array( 'billing_invoice_type' => '', 'billing_rut_personal' => '11.111.111-1', 'first_name' => 'Ana', 'last_name' => 'Pérez', 'billing_state' => 'Región Metropolitana de Santiago', 'billing_country' => 'CL' ) );
				$this->make_order( 503, array( 'user' => $user ) );
			},
			$this->no_seed(),
			static fn( $m ) => $m->order_sync( 503 )
		);
		$this->assert_identical( $both );
	}

	public function test_logged_in_factura_with_conflicting_order_meta_keeps_legacy_precedence(): void {
		// User profile says factura, this order says boleta: v1.6.1 builds a company
		// partner from the profile but issues a boleta (doc type from the order).
		$both = $this->run_both(
			function () {
				$user = $this->make_user( 78, 'empresa@example.com', array( 'billing_invoice_type' => '1', 'billing_rut_empresa' => '77.777.777-7', 'billing_company' => 'Empresa Ltda', 'billing_giro' => 'Comercio', 'billing_rut_personal' => '12.345.678-5' ) );
				$this->make_order( 504, array( 'user' => $user ) );
			},
			$this->no_seed(),
			static fn( $m ) => $m->order_sync( 504 )
		);
		$this->assert_identical( $both );
	}

	public function test_existing_partner_by_email_customer_note_and_mercadopago_is_identical(): void {
		$both = $this->run_both(
			fn() => $this->make_order(
				505,
				array(
					'payment_method' => 'woo-mercado-pago-basic',
					'customer_note'  => 'Dejar en conserjería',
					'meta'           => array( '_Mercado_Pago_Payment_IDs' => '123', '_paid_date' => '2026-10-20 10:10:00' ),
				)
			),
			static function ( FakeOdooRpc $rpc ) {
				$rpc->seed( 'res.partner', array( 'id' => 300, 'email' => 'ana@example.com', 'type' => 'contact', 'is_company' => false ) );
			},
			static fn( $m ) => $m->order_sync( 505 )
		);
		$this->assert_identical( $both );
	}

	public function test_existing_sale_order_link_path_bacs_is_identical(): void {
		$both = $this->run_both(
			fn() => $this->make_order( 506, array( 'payment_method' => 'bacs', 'date_paid' => '2026-10-21 09:00:00' ) ),
			static function ( FakeOdooRpc $rpc ) {
				$rpc->seed( 'sale.order', array( 'id' => 4000, 'origin' => '506', 'state' => 'sent', 'name' => 'S4000', 'amount_total' => 17762, 'invoice_status' => 'to invoice', 'invoice_ids' => array( 4100 ) ) );
				$rpc->seed( 'account.move', array( 'id' => 4100, 'state' => 'draft' ) );
			},
			static fn( $m ) => $m->order_sync( 506 )
		);
		$this->assert_identical( $both );
	}

	public function test_cancel_sync_is_identical(): void {
		$both = $this->run_both(
			fn() => $this->make_order( 507, array( 'status' => 'cancelled', 'meta' => array( '_odoo_sale_order_id' => 4000, '_woo2odoo_invoice_id' => 4100 ) ) ),
			static function ( FakeOdooRpc $rpc ) {
				$rpc->seed( 'sale.order', array( 'id' => 4000, 'origin' => '507', 'state' => 'sale', 'name' => 'S4000' ) );
				$rpc->seed( 'account.move', array( 'id' => 4100, 'state' => 'posted', 'name' => 'BEL 1' ) );
			},
			static fn( $m ) => $m->cancel_sync( 507 )
		);
		$this->assert_identical( $both );
	}

	public function test_backfill_lookup_is_identical(): void {
		$both = $this->run_both(
			fn() => $this->make_order( 508 ),
			static function ( FakeOdooRpc $rpc ) {
				$rpc->seed( 'sale.order', array( 'id' => 4200, 'origin' => '508', 'state' => 'sale', 'name' => 'S4200', 'invoice_ids' => array( 4300 ), 'amount_total' => 1 ) );
			},
			static fn( $m ) => $m->backfill_from_odoo( 508, false )
		);
		$this->assert_identical( $both );
	}

	public function test_explicit_old_checkbox_value_true_still_syncs_identically(): void {
		// B2C stored the old checkbox as 'true' (or never stored it): both mean enabled.
		$this->set_export( array( 'export_order' => 'true', 'payment_journal_id' => '14' ) );
		$both = $this->run_both(
			fn() => $this->make_order( 509 ),
			$this->no_seed(),
			static fn( $m ) => $m->order_sync( 509 )
		);
		$this->assert_identical( $both );
	}
}
