<?php
/**
 * Tests for the multi-store (B2B) settings: master switch, origin prefix, fiscal
 * source, partner resolution, fee lines, shipping, lock, sale.order vals and
 * shadow mode. Odoo is the in-memory FakeOdooRpc; nothing leaves the process.
 *
 * @package Woo2Odoo
 */

// phpcs:ignoreFile

use Woo2Odoo\Woo2Odoo_Client;
use Woo2Odoo\Woo2Odoo_Config;
use Woo2Odoo\Woo2Odoo_Order_Manager;
use Woo2Odoo\Woo2Odoo_Plugin;
use Woo2Odoo\Woo2Odoo_Shadow_Diff;
use Woo2Odoo\Woo2Odoo_Shadow_Order;
use Woo2Odoo\Woo2Odoo_Sync_Lock;

/**
 * @covers \Woo2Odoo\Woo2Odoo_Order_Manager
 * @covers \Woo2Odoo\Woo2Odoo_Config
 * @covers \Woo2Odoo\Woo2Odoo_Client
 * @covers \Woo2Odoo\Woo2Odoo_Sync_Lock
 * @covers \Woo2Odoo\Woo2Odoo_Shadow_Diff
 */
class B2bReadinessTest extends Woo2Odoo_Pure_TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->set_export( array( 'payment_journal_id' => '14' ) );
	}

	private function sync( int $order_id, ?FakeOdooRpc $rpc = null, ?bool $shadow = null ): array {
		$rpc     = $rpc ?? new FakeOdooRpc();
		$client  = $this->client( $rpc, $shadow );
		$manager = new Woo2Odoo_Order_Manager( $client );
		$result  = $manager->order_sync( $order_id );
		return array( $result, $rpc, $client, $manager );
	}

	private function created( FakeOdooRpc $rpc, string $model ): array {
		return array_map( static fn( $c ) => $c[2], $rpc->calls_for( 'create', $model ) );
	}

	// ── 1. Master switch ─────────────────────────────────────────────────────

	public function test_master_switch_defaults_to_enabled_when_unset_or_old_checkbox_true(): void {
		$this->assertTrue( Woo2Odoo_Config::sync_enabled(), 'unset option' );
		$this->set_export( array( 'export_order' => 'true' ) );
		$this->assertTrue( Woo2Odoo_Config::sync_enabled(), 'old checkbox value' );
		$this->set_export( array( 'export_order' => '' ) );
		$this->assertTrue( Woo2Odoo_Config::sync_enabled(), 'empty value' );
		$this->set_export( array( 'export_order' => 'false' ) );
		$this->assertFalse( Woo2Odoo_Config::sync_enabled(), 'explicitly disabled' );
	}

	public function test_master_switch_disabled_order_sync_makes_no_odoo_call_and_touches_no_order(): void {
		$this->set_export( array( 'export_order' => 'false' ) );
		$order = $this->make_order( 601 );
		list( $result, $rpc ) = $this->sync( 601 );
		$this->assertFalse( $result );
		$this->assertSame( array(), $rpc->calls );
		$this->assertSame( 0, $order->saves );
		$this->assertArrayNotHasKey( '_woo2odoo_sync_status', $order->d['meta'] );
	}

	public function test_master_switch_disabled_blocks_cancel_and_refund_paths(): void {
		$this->set_export( array( 'export_order' => 'false' ) );
		$this->make_order( 602, array( 'meta' => array( '_odoo_sale_order_id' => 4000 ) ) );
		$rpc     = new FakeOdooRpc();
		$manager = new Woo2Odoo_Order_Manager( $this->client( $rpc ) );
		$this->assertFalse( $manager->cancel_sync( 602 ) );
		$this->assertFalse( $manager->refund_sync( 602, 9 ) );
		$this->assertSame( array(), $rpc->calls );
	}

	public function test_master_switch_disabled_hooks_return_before_touching_the_order(): void {
		$this->set_export( array( 'export_order' => 'false' ) );
		$this->make_order( 603 );
		$plugin = ( new ReflectionClass( Woo2Odoo_Plugin::class ) )->newInstanceWithoutConstructor();
		$plugin->auto_sync_order( 603 );
		$plugin->auto_refund_order( 603, 1 );
		$plugin->auto_cancel_order( 603 );
		$plugin->run_stock_sync();
		// Each hook would load the order first (skip-list check) if it went any further.
		$this->assertSame( 0, WP_Stub_State::$wc_get_order_calls );
		$this->assertFalse( Woo2Odoo_Plugin::hooks_may_sync() );
	}

	public function test_master_switch_constant_and_filter(): void {
		add_filter( 'woo2odoo_sync_enabled', static fn() => false );
		$this->assertFalse( Woo2Odoo_Config::sync_enabled() );
	}

	// ── 2. Origin prefix ─────────────────────────────────────────────────────

	public function test_prefix_is_used_for_origin_client_ref_and_references(): void {
		$this->set_export( array( 'origin_prefix' => 'B2B-' ) );
		$this->make_order( 610 );
		list( $result, $rpc ) = $this->sync( 610 );
		$this->assertTrue( $result );
		$so = $this->created( $rpc, 'sale.order' )[0];
		$this->assertSame( 'B2B-610', $so['origin'] );
		$this->assertSame( 'B2B-610', $so['client_order_ref'] );
		$this->assertStringEndsWith( 'B2B-610', $so['note'] );
		$this->assertSame( 'WC#B2B-610', $this->created( $rpc, 'account.move' )[0]['payment_reference'] );
		$this->assertSame( 'Pedido WC#B2B-610', $this->created( $rpc, 'account.payment' )[0]['memo'] );

		$lookup = $rpc->calls_for( 'searchRead', 'sale.order' )[0];
		$this->assertSame( array( 'origin', '=', 'B2B-610' ), $lookup[2]['where'][0] );
	}

	public function test_prefixed_site_never_matches_unprefixed_sale_order_with_same_id(): void {
		$this->set_export( array( 'origin_prefix' => 'B2B-' ) );
		$this->make_order( 611 );
		$rpc = new FakeOdooRpc();
		// B2C's sale order for ITS order 611.
		$rpc->seed( 'sale.order', array( 'id' => 4000, 'origin' => '611', 'state' => 'sale', 'name' => 'S4000', 'invoice_status' => 'invoiced', 'invoice_ids' => array() ) );
		list( $result, $rpc ) = $this->sync( 611, $rpc );
		$this->assertTrue( $result );
		$this->assertCount( 1, $this->created( $rpc, 'sale.order' ), 'must create its own SO, not link B2C\'s' );
		$this->assertNotSame( 4000, WP_Stub_State::$orders[611]->d['meta']['_odoo_sale_order_id'] );
	}

	public function test_unprefixed_site_never_matches_prefixed_sale_order(): void {
		$this->make_order( 612 );
		$rpc = new FakeOdooRpc();
		$rpc->seed( 'sale.order', array( 'id' => 4001, 'origin' => 'B2B-612', 'state' => 'sale', 'name' => 'S4001', 'invoice_ids' => array() ) );
		list( , $rpc ) = $this->sync( 612, $rpc );
		$this->assertCount( 1, $this->created( $rpc, 'sale.order' ) );
		$this->assertSame( 612, $this->created( $rpc, 'sale.order' )[0]['origin'], 'unprefixed origin keeps the raw id' );
		$this->assertArrayNotHasKey( 'client_order_ref', $this->created( $rpc, 'sale.order' )[0] );
	}

	public function test_prefixed_site_links_its_own_existing_sale_order_and_backfill_is_prefix_aware(): void {
		$this->set_export( array( 'origin_prefix' => 'B2B-' ) );
		$this->make_order( 613 );
		$rpc = new FakeOdooRpc();
		$rpc->seed( 'sale.order', array( 'id' => 4002, 'origin' => '613', 'state' => 'sale', 'name' => 'B2C', 'invoice_ids' => array() ) );
		$rpc->seed( 'sale.order', array( 'id' => 4003, 'origin' => 'B2B-613', 'state' => 'sale', 'name' => 'B2B', 'invoice_ids' => array(), 'amount_total' => 1 ) );
		$manager = new Woo2Odoo_Order_Manager( $this->client( $rpc ) );
		$res     = $manager->backfill_from_odoo( 613, true );
		$this->assertSame( 4003, $res['so'] );
		$this->assertTrue( $manager->order_sync( 613 ) );
		$this->assertSame( 4003, WP_Stub_State::$orders[613]->d['meta']['_odoo_sale_order_id'] );
		$this->assertSame( array(), $this->created( $rpc, 'sale.order' ) );
	}

	// ── 3. Fiscal data source ────────────────────────────────────────────────

	private function conflicting_user_order( int $id ): void {
		$user = $this->make_user( 90, 'buyer@example.com', array( 'billing_invoice_type' => '', 'billing_rut_personal' => '11.111.111-1', 'billing_company' => 'Perfil SpA', 'billing_giro' => 'Perfil' ) );
		$this->make_order(
			$id,
			array(
				'user'    => $user,
				'billing' => array_merge( $this->make_order( 1 )->d['billing'], array( 'company' => 'Pedido SpA' ) ),
				'meta'    => array_merge( $this->factura_meta(), array( '_wc_other/pinkmask/razon_social' => 'Razón del Pedido SpA' ) ),
			)
		);
	}

	public function test_fiscal_source_legacy_reads_user_meta_for_logged_in_buyers(): void {
		$this->conflicting_user_order( 620 );
		$data = ( new Woo2Odoo_Order_Manager( $this->client( new FakeOdooRpc() ) ) )->get_fiscal_data( WP_Stub_State::$orders[620], WP_Stub_State::$users[90] );
		$this->assertSame( '', $data['invoice_type'] );
		$this->assertSame( '11.111.111-1', $data['rut_personal'] );
		$this->assertSame( 'Perfil SpA', $data['razon_social'] );
	}

	public function test_fiscal_source_order_first_uses_order_meta_with_user_fallback(): void {
		$this->set_export( array( 'fiscal_source' => 'order_first' ) );
		$this->conflicting_user_order( 621 );
		unset( WP_Stub_State::$orders[621]->d['meta']['_wc_other/pinkmask/giro'] );
		list( $result, $rpc ) = $this->sync( 621 );
		$this->assertTrue( $result );
		$company = $this->created( $rpc, 'res.partner' )[0];
		$this->assertTrue( $company['is_company'] );
		$this->assertSame( '77777777-7', $company['vat'] );
		$this->assertSame( 'Razón del Pedido SpA', $company['name'] );
		$this->assertSame( 'Perfil', $company['l10n_cl_activity_description'], 'giro falls back to the profile' );
		$this->assertSame( 1, $this->created( $rpc, 'account.move' )[0]['l10n_latam_document_type_id'] );
	}

	public function test_fiscal_source_order_first_empty_order_invoice_type_means_boleta(): void {
		$this->set_export( array( 'fiscal_source' => 'order_first' ) );
		$user = $this->make_user( 91, 'b@example.com', array( 'billing_invoice_type' => '1', 'billing_rut_empresa' => '77.777.777-7' ) );
		$this->make_order( 622, array( 'user' => $user, 'meta' => array( '_billing_invoice_type' => '' ) ) );
		$data = ( new Woo2Odoo_Order_Manager( $this->client( new FakeOdooRpc() ) ) )->get_fiscal_data( WP_Stub_State::$orders[622], $user );
		$this->assertSame( '', $data['invoice_type'] );
		$this->assertSame( '77.777.777-7', $data['rut_empresa'] );
	}

	public function test_customer_data_filter_can_change_fiscal_data(): void {
		$this->make_order( 623 );
		add_filter(
			'woo2odoo_customer_data',
			static function ( $c ) {
				$c['rut_personal'] = '9.876.543-2';
				return $c;
			}
		);
		list( , $rpc ) = $this->sync( 623 );
		$this->assertSame( '9876543-2', $this->created( $rpc, 'res.partner' )[0]['vat'] );
	}

	// ── 4. Partner resolution ────────────────────────────────────────────────

	public function test_partner_id_filter_skips_search_and_creation(): void {
		$this->make_order( 630 );
		$seen = null;
		add_filter(
			'woo2odoo_partner_id',
			static function ( $id, $order, $customer ) use ( &$seen ) {
				$seen = $customer;
				return 777;
			},
			10,
			3
		);
		list( $result, $rpc ) = $this->sync( 630 );
		$this->assertTrue( $result );
		$this->assertSame( 'ana@example.com', $seen['email'] );
		$partner_searches = array_filter( $rpc->calls_for( 'searchRead', 'res.partner' ), static fn( $c ) => 'email' === $c[2]['where'][0][0] );
		$this->assertSame( array(), array_values( $partner_searches ) );
		$this->assertSame( 777, $this->created( $rpc, 'sale.order' )[0]['partner_id'] );
		foreach ( $this->created( $rpc, 'res.partner' ) as $p ) {
			$this->assertSame( 777, $p['parent_id'], 'only addresses are created' );
		}
	}

	public function test_rut_matching_is_off_by_default(): void {
		$this->make_order( 631 );
		list( , $rpc ) = $this->sync( 631 );
		foreach ( $rpc->calls_for( 'searchRead', 'res.partner' ) as $c ) {
			$this->assertNotSame( 'vat', $c[2]['where'][0][0] );
		}
	}

	public function test_rut_matching_finds_commercial_partner_before_email(): void {
		$this->set_export( array( 'partner_match_rut' => 'true' ) );
		$this->make_order( 632, array( 'meta' => $this->factura_meta() ) );
		$rpc = new FakeOdooRpc();
		$rpc->seed( 'res.partner', array( 'id' => 320, 'vat' => '77.777.777-7', 'is_company' => true, 'parent_id' => false, 'email' => 'other@example.com' ) );
		list( $result, $rpc ) = $this->sync( 632, $rpc );
		$this->assertTrue( $result );
		$this->assertSame( 320, $this->created( $rpc, 'sale.order' )[0]['partner_id'] );
		$first_partner_search = $rpc->calls_for( 'searchRead', 'res.partner' )[0];
		$this->assertSame( 'vat', $first_partner_search[2]['where'][0][0] );
		$this->assertContains( '77777777-7', $first_partner_search[2]['where'][0][2] );
	}

	public function test_rut_matching_falls_back_to_email_when_no_match(): void {
		$this->set_export( array( 'partner_match_rut' => 'true' ) );
		$this->make_order( 633 );
		$rpc = new FakeOdooRpc();
		$rpc->seed( 'res.partner', array( 'id' => 330, 'email' => 'ana@example.com', 'type' => 'contact', 'is_company' => false ) );
		list( , $rpc ) = $this->sync( 633, $rpc );
		$this->assertSame( 330, $this->created( $rpc, 'sale.order' )[0]['partner_id'] );
	}

	// ── 5. Fee lines ─────────────────────────────────────────────────────────

	private function order_with_fee( int $id ): void {
		$this->make_order( $id, array( 'fees' => array( array( 'name' => 'Recargo pago', 'total' => '840.34', 'tax' => '159.66' ) ) ) );
	}

	public function test_fees_are_ignored_when_no_fee_product_is_configured(): void {
		$this->order_with_fee( 640 );
		list( , $rpc ) = $this->sync( 640 );
		foreach ( $this->created( $rpc, 'sale.order.line' ) as $line ) {
			$this->assertNotSame( 'Recargo pago', $line['name'] ?? '' );
		}
	}

	public function test_fee_mapped_to_service_product_by_sku_with_net_price_and_iva(): void {
		$this->set_export( array( 'fee_product' => 'FEE-SVC' ) );
		$this->order_with_fee( 641 );
		list( , $rpc ) = $this->sync( 641 );
		$fee = array_values( array_filter( $this->created( $rpc, 'sale.order.line' ), static fn( $l ) => 99 === ( $l['product_id'] ?? null ) ) );
		$this->assertCount( 1, $fee );
		$this->assertSame( 840.34, $fee[0]['price_unit'] );
		$this->assertSame( 1, $fee[0]['product_uom_qty'] );
		$this->assertSame( array( array( 6, 0, array( 3 ) ) ), $fee[0]['tax_id'] );
		$this->assertSame( 'Recargo pago', $fee[0]['name'] );
	}

	public function test_untaxed_fee_by_product_id_gets_no_tax(): void {
		$this->set_export( array( 'fee_product' => '99' ) );
		$this->make_order( 642, array( 'fees' => array( array( 'name' => 'Cargo', 'total' => '500', 'tax' => '0' ) ) ) );
		list( , $rpc ) = $this->sync( 642 );
		$fee = array_values( array_filter( $this->created( $rpc, 'sale.order.line' ), static fn( $l ) => 99 === ( $l['product_id'] ?? null ) ) );
		$this->assertSame( array( array( 6, 0, array() ) ), $fee[0]['tax_id'] );
	}

	// ── 6. Shipping ──────────────────────────────────────────────────────────

	public function test_shipping_divides_by_iva_only_without_separate_shipping_tax(): void {
		$manager = new Woo2Odoo_Order_Manager( $this->client( new FakeOdooRpc() ) );
		$b2c     = $this->make_order( 650, array( 'shipping_total' => '3990', 'shipping_tax' => '0' ) );
		$b2b     = $this->make_order( 651, array( 'shipping_total' => '3352.94', 'shipping_tax' => '637.06' ) );
		$this->assertSame( 3352.94, $manager->shipping_net_amount( $b2c ) );
		$this->assertSame( 3352.94, $manager->shipping_net_amount( $b2b ) );
	}

	public function test_shipping_product_is_configurable(): void {
		$this->set_export( array( 'shipping_product_id' => '2001' ) );
		$this->make_order( 652 );
		list( , $rpc ) = $this->sync( 652 );
		$ids = array_map( static fn( $l ) => $l['product_id'] ?? null, $this->created( $rpc, 'sale.order.line' ) );
		$this->assertContains( 2001, $ids );
		$this->assertNotContains( 1229, $ids );
	}

	// ── 7. Lock and line update ──────────────────────────────────────────────

	public function test_lock_is_exclusive_until_released_and_stale_locks_expire(): void {
		$a = new Woo2Odoo_Sync_Lock();
		$b = new Woo2Odoo_Sync_Lock();
		$this->assertTrue( $a->acquire( 'order_1' ) );
		$this->assertFalse( $b->acquire( 'order_1' ) );
		$b->release( 'order_1' ); // not the holder: no effect
		$this->assertFalse( $b->acquire( 'order_1' ) );
		$a->release( 'order_1' );
		$this->assertTrue( $b->acquire( 'order_1' ) );

		$c = new Woo2Odoo_Sync_Lock();
		$this->assertTrue( $c->acquire( 'order_2', -10 ) ); // already expired
		$this->assertTrue( $b->acquire( 'order_2' ), 'stale lock is taken over' );
	}

	public function test_order_sync_does_nothing_while_another_sync_holds_the_lock(): void {
		$this->make_order( 660 );
		$held = new Woo2Odoo_Sync_Lock();
		$this->assertTrue( $held->acquire( 'order_660' ) );
		list( $result, $rpc ) = $this->sync( 660 );
		$this->assertFalse( $result );
		$this->assertSame( array(), $rpc->calls );
		$held->release( 'order_660' );

		list( $result ) = $this->sync( 660 );
		$this->assertTrue( $result );
		$this->assertSame( array(), $GLOBALS['wpdb']->rows, 'lock released after the sync' );
	}

	public function test_duplicate_sku_gets_its_own_line_and_never_writes_to_the_sale_order_id(): void {
		$this->make_order(
			661,
			array(
				'items' => array(
					array( 'sku' => 'SKU-A', 'qty' => 1, 'total' => '5033.61', 'tax' => '956.39' ),
					array( 'sku' => 'SKU-A', 'qty' => 2, 'total' => '10067.23', 'tax' => '1912.77' ),
				),
			)
		);
		list( , $rpc ) = $this->sync( 661 );
		$so_id = $rpc->calls_for( 'create', 'sale.order' ) ? WP_Stub_State::$orders[661]->d['meta']['_odoo_sale_order_id'] : 0;
		foreach ( $rpc->calls_for( 'update', 'sale.order.line' ) as $c ) {
			$this->assertNotSame( $so_id, $c[2], 'v1.6.1 bug: sale.order id used as line id' );
		}
		$lines = array_filter( $this->created( $rpc, 'sale.order.line' ), static fn( $l ) => 11 === ( $l['product_id'] ?? null ) );
		$this->assertCount( 2, $lines );
	}

	public function test_add_order_line_items_updates_an_existing_line_by_its_own_id(): void {
		$order = $this->make_order( 662, array( 'items' => array( array( 'sku' => 'SKU-A', 'qty' => 3, 'total' => '15100.84', 'tax' => '2869.16' ) ) ) );
		$rpc   = new FakeOdooRpc();
		$rpc->seed( 'sale.order.line', array( 'id' => 8800, 'order_id' => 4500, 'product_id' => 11 ) );
		( new Woo2Odoo_Order_Manager( $this->client( $rpc ) ) )->add_order_line_items( $order, 4500, 300 );
		$updates = $rpc->calls_for( 'update', 'sale.order.line' );
		$this->assertCount( 1, $updates );
		$this->assertSame( 8800, $updates[0][2] );
	}

	// ── 8. sale.order vals ───────────────────────────────────────────────────

	public function test_salesperson_team_and_vals_filter(): void {
		$this->set_export( array( 'salesperson_user_id' => '6', 'sales_team_id' => '3' ) );
		add_filter(
			'woo2odoo_sale_order_vals',
			static function ( $vals ) {
				$vals['warehouse_id'] = 2;
				return $vals;
			}
		);
		$this->make_order( 670 );
		list( , $rpc ) = $this->sync( 670 );
		$so = $this->created( $rpc, 'sale.order' )[0];
		$this->assertSame( 6, $so['user_id'] );
		$this->assertSame( 3, $so['team_id'] );
		$this->assertSame( 2, $so['warehouse_id'] );
	}

	public function test_no_salesperson_or_team_keys_by_default(): void {
		$this->make_order( 671 );
		list( , $rpc ) = $this->sync( 671 );
		$so = $this->created( $rpc, 'sale.order' )[0];
		$this->assertArrayNotHasKey( 'user_id', $so );
		$this->assertArrayNotHasKey( 'team_id', $so );
	}

	// ── 9. Shadow mode ───────────────────────────────────────────────────────

	public function test_shadow_client_fakes_writes_and_passes_reads(): void {
		$rpc    = new FakeOdooRpc();
		$client = $this->client( $rpc, true );
		$this->assertTrue( $client->is_shadow_mode() );
		$this->assertSame( 900000001, $client->create_record( 'res.partner', array( 'name' => 'x' ) ) );
		$this->assertSame( 900000002, $client->create_record( 'sale.order', array( 'origin' => '1' ) ) );
		$this->assertTrue( $client->update_record( 'res.partner', 5, array( 'name' => 'y' ) ) );
		$this->assertTrue( $client->execute( 'sale.order', 'action_confirm', array( array( 900000002 ) ) ) );
		$this->assertSame( array(), $rpc->writes(), 'no write reached Odoo' );

		$country = $client->search_read( 'res.country', array( array( 'code', '=', 'CL' ) ), array( 'id' ), null, 1, null, array( 'single' => true ) );
		$this->assertSame( 46, $country->id, 'reads still reach Odoo' );
		$this->assertSame( array(), $client->search_read( 'sale.order.line', array( array( 'order_id', '=', 900000002 ) ) ), 'fake ids answered locally' );
		$this->assertSame( array(), $client->execute( 'sale.order', 'read', array( array( 900000002 ), array( 'name' ) ) ) );
		$this->assertCount( 1, $rpc->calls, 'only the real read went out' );

		$log = $client->get_shadow_log();
		$this->assertCount( 4, $log );
		$this->assertSame( array( 'create', 'res.partner', 'create' ), array( $log[0]['op'], $log[0]['model'], $log[0]['method'] ) );
		$this->assertSame( 'woo2odoo-shadow', end( WP_Stub_State::$logs )['context']['source'] );
	}

	public function test_shadow_order_sync_writes_nothing_to_odoo_or_wc_even_when_sync_disabled(): void {
		$this->set_export( array( 'export_order' => 'false' ) );
		$this->make_order( 680 );
		list( $result, $rpc, $client ) = $this->sync( 680, null, true );
		$this->assertTrue( $result );
		$this->assertSame( array(), $rpc->writes() );
		$this->assertSame( 0, WP_Stub_State::$orders[680]->saves );
		$this->assertSame( array(), WP_Stub_State::$orders[680]->notes );
		$this->assertArrayNotHasKey( '_odoo_sale_order_id', WP_Stub_State::$orders[680]->d['meta'] );
		$models = array_map( static fn( $e ) => $e['model'], $client->get_shadow_log() );
		$this->assertContains( 'sale.order', $models );
		$this->assertContains( 'sale.order.line', $models );
	}

	public function test_shadow_payloads_equal_real_payloads_except_ids(): void {
		$this->make_order( 681 );
		list( , $real ) = $this->sync( 681 );
		WP_Stub_State::$orders = array();
		$this->make_order( 681 );
		list( , , $client ) = $this->sync( 681, null, true );
		$real_so   = $this->created( $real, 'sale.order' )[0];
		$shadow_so = array_values( array_filter( $client->get_shadow_log(), static fn( $e ) => 'sale.order' === $e['model'] ) )[0]['args'];
		unset( $real_so['partner_id'], $real_so['partner_invoice_id'], $real_so['partner_shipping_id'] );
		unset( $shadow_so['partner_id'], $shadow_so['partner_invoice_id'], $shadow_so['partner_shipping_id'] );
		$this->assertSame( $real_so, $shadow_so );
	}

	public function test_shadow_ignore_existing_and_diff_against_existing_sale_order(): void {
		$this->make_order( 682 );
		$rpc = new FakeOdooRpc();
		$rpc->seed( 'res.partner', array( 'id' => 300, 'email' => 'ana@example.com', 'type' => 'contact', 'is_company' => false ) );
		$rpc->seed( 'sale.order', array( 'id' => 4600, 'origin' => '682', 'state' => 'sale', 'name' => 'S4600', 'partner_id' => array( 300, 'Ana' ), 'payment_term_id' => array( 1, 'Immediate' ), 'amount_untaxed' => 14926.06, 'invoice_ids' => array() ) );
		$rpc->seed( 'sale.order.line', array( 'id' => 1, 'order_id' => 4600, 'product_id' => 11, 'product_uom_qty' => 2, 'price_unit' => 4530.25, 'display_type' => false ) );
		$rpc->seed( 'sale.order.line', array( 'id' => 2, 'order_id' => 4600, 'product_id' => 12, 'product_uom_qty' => 1, 'price_unit' => 2512.61, 'display_type' => false ) );
		$rpc->seed( 'sale.order.line', array( 'id' => 3, 'order_id' => 4600, 'product_id' => 1229, 'product_uom_qty' => 1, 'price_unit' => 3352.94, 'display_type' => false ) );

		$client                           = $this->client( $rpc, true );
		$manager                          = new Woo2Odoo_Order_Manager( $client );
		$manager->shadow_ignore_existing = true;
		$this->assertTrue( $manager->order_sync( 682 ) );
		$this->assertSame( array(), $rpc->writes() );

		$rows   = Woo2Odoo_Shadow_Diff::compare( $client, $client->get_shadow_log(), Woo2Odoo_Config::order_ref( 682 ) );
		$by     = array_column( $rows, null, 'field' );
		$this->assertSame( 'yes', $by['partner_id']['match'] );
		$this->assertSame( 'yes', $by['origin']['match'] );
		$this->assertSame( 'yes', $by['line product 11']['match'] );
		$this->assertSame( 'yes', $by['line product 1229']['match'] );
		$this->assertSame( 'yes', $by['amount_untaxed']['match'] );

		$this->assertNull( Woo2Odoo_Shadow_Diff::compare( $client, array(), 'B2B-682' ) );
	}

	public function test_shadow_cannot_be_turned_off_when_forced_by_setting(): void {
		$this->set_export( array( 'shadow_mode' => 'true' ) );
		$client = $this->client( new FakeOdooRpc() );
		$this->assertTrue( $client->is_shadow_mode() );
		$client->set_shadow_mode( false );
		$this->assertTrue( $client->is_shadow_mode() );
	}

	public function test_shadow_order_never_persists(): void {
		$this->make_order( 690 );
		$shadow = Woo2Odoo_Shadow_Order::from_order( WP_Stub_State::$orders[690] );
		$shadow->update_meta_data( '_x', 1 );
		$shadow->save();
		$shadow->add_order_note( 'n' );
		$this->assertSame( array( 'n' ), $shadow->shadow_notes );
		$this->assertSame( 0, WP_Stub_State::$orders[690]->saves );
		$this->assertArrayNotHasKey( '_x', WP_Stub_State::$orders[690]->d['meta'] );
	}
}
