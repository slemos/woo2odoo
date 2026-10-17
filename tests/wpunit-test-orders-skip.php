<?php
/**
 * Integration tests for the Test / Debug skip-list (needs WordPress + WooCommerce).
 *
 * Runs with the portable suite (wp-env) or the container suite; the Odoo client is
 * mocked, no Odoo connection is needed. Real WC orders are created and registered in
 * the namespaced wc_get_order() stub of wpunit-order-manager.php (same technique,
 * which is why that file must load first: the loader sorts files alphabetically).
 *
 * @package Woo2Odoo
 */
namespace Woo2Odoo;

use PHPUnit\Framework\TestCase;

/**
 * @covers \Woo2Odoo\Woo2Odoo_Test_Orders
 * @covers \Woo2Odoo\Woo2Odoo_Order_Manager
 */
class WPUnit_Test_Orders_Skip_Test extends TestCase {

	private const OPTION = 'Woo2Odoo-plugin-testing';

	/** @var Woo2Odoo_Client&\PHPUnit\Framework\MockObject\MockObject */
	private $mock_client;
	private Woo2Odoo_Order_Manager $manager;
	/** @var int[] */
	private array $order_ids = array();

	protected function setUp(): void {
		if ( ! class_exists( WPUnit_Order_Manager_Test::class ) ) {
			$this->markTestSkipped( 'wpunit-order-manager.php (wc_get_order stub) must be loaded first.' );
		}
		$this->mock_client = $this->createMock( Woo2Odoo_Client::class );
		$this->manager     = new Woo2Odoo_Order_Manager( $this->mock_client );
		delete_option( self::OPTION );
	}

	protected function tearDown(): void {
		delete_option( self::OPTION );
		WPUnit_Order_Manager_Test::$mock_orders = array();
		foreach ( $this->order_ids as $id ) {
			$o = \wc_get_order( $id );
			if ( $o ) {
				$o->delete( true );
			}
		}
	}

	private function settings( array $over = array() ): void {
		update_option(
			self::OPTION,
			array_merge(
				array(
					'skip_enabled'  => 'true',
					'skip_emails'   => "qa@example.com\nqa+*@example.com",
					'skip_orders'   => 'true',
					'skip_refunds'  => 'true',
					'skip_note'     => 'true',
				),
				$over
			)
		);
	}

	private function order( string $email, array $meta = array() ): \WC_Order {
		$order = \wc_create_order();
		$order->set_billing_email( $email );
		foreach ( $meta as $k => $v ) {
			$order->update_meta_data( $k, $v );
		}
		$order->save();
		$this->order_ids[]                                       = $order->get_id();
		WPUnit_Order_Manager_Test::$mock_orders[ $order->get_id() ] = $order;
		return $order;
	}

	// ── Decision ──────────────────────────────────────────────────────────────

	public function test_nothing_is_skipped_when_the_master_switch_is_off(): void {
		$this->settings( array( 'skip_enabled' => 'false' ) );
		$this->assertSame( '', Woo2Odoo_Test_Orders::should_skip( $this->order( 'qa@example.com' ), 'orders' ) );
	}

	public function test_nothing_is_skipped_when_the_list_is_empty(): void {
		$this->settings( array( 'skip_emails' => '' ) );
		$this->assertSame( '', Woo2Odoo_Test_Orders::should_skip( $this->order( 'qa@example.com' ), 'orders' ) );
	}

	public function test_billing_email_match_is_skipped_and_other_customers_are_not(): void {
		$this->settings();
		$this->assertSame( 'qa@example.com', Woo2Odoo_Test_Orders::should_skip( $this->order( 'QA@Example.com' ), 'orders' ) );
		$this->assertSame( 'qa+*@example.com', Woo2Odoo_Test_Orders::should_skip( $this->order( 'qa+t1@example.com' ), 'orders' ) );
		$this->assertSame( '', Woo2Odoo_Test_Orders::should_skip( $this->order( 'real.customer@gmail.com' ), 'orders' ) );
	}

	public function test_customer_account_email_also_counts(): void {
		$this->settings();
		$uid = wp_insert_user(
			array(
				'user_login' => 'w2o_skip_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'qa@example.com',
			)
		);
		$this->assertIsInt( $uid );
		$order = $this->order( 'different.billing@gmail.com' );
		$order->set_customer_id( $uid );
		$order->save();
		$this->assertSame( 'qa@example.com', Woo2Odoo_Test_Orders::should_skip( $order, 'orders' ) );
		wp_delete_user( $uid );
	}

	public function test_an_order_already_exported_to_odoo_is_never_skipped(): void {
		$this->settings();
		$order = $this->order( 'qa@example.com', array( '_odoo_sale_order_id' => 555 ) );
		$this->assertSame( '', Woo2Odoo_Test_Orders::should_skip( $order, 'orders' ) );
		$this->assertSame( '', Woo2Odoo_Test_Orders::should_skip( $order, 'refunds' ) );
	}

	public function test_each_context_can_be_switched_off_independently(): void {
		$this->settings( array( 'skip_refunds' => 'false' ) );
		$order = $this->order( 'qa@example.com' );
		$this->assertSame( 'qa@example.com', Woo2Odoo_Test_Orders::should_skip( $order, 'orders' ) );
		$this->assertSame( '', Woo2Odoo_Test_Orders::should_skip( $order, 'refunds' ) );
	}

	public function test_filter_can_override_the_decision(): void {
		$this->settings();
		$cb = static function () {
			return '';
		};
		add_filter( 'woo2odoo_skip_test_order', $cb );
		$this->assertSame( '', Woo2Odoo_Test_Orders::should_skip( $this->order( 'qa@example.com' ), 'orders' ) );
		remove_filter( 'woo2odoo_skip_test_order', $cb );
	}

	// ── Bookkeeping ───────────────────────────────────────────────────────────

	public function test_mark_skipped_sets_status_reason_and_one_note_only(): void {
		$this->settings();
		$order = $this->order( 'qa@example.com', array( '_woo2odoo_sync_error' => 'old error' ) );

		Woo2Odoo_Test_Orders::mark_skipped( $order, 'orders', 'qa@example.com' );
		Woo2Odoo_Test_Orders::mark_skipped( $order, 'orders', 'qa@example.com' ); // second call must not duplicate the note

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( 'skipped', $fresh->get_meta( '_woo2odoo_sync_status' ) );
		$this->assertSame( 'qa@example.com', $fresh->get_meta( Woo2Odoo_Test_Orders::META_REASON ) );
		$this->assertSame( '', $fresh->get_meta( '_woo2odoo_sync_error' ) );
		$notes = array_filter(
			\wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static function ( $n ) {
				return false !== strpos( $n->content, 'omitido' );
			}
		);
		$this->assertCount( 1, $notes );
		$this->assertTrue( Woo2Odoo_Test_Orders::is_skipped( $fresh ) );
	}

	public function test_note_can_be_turned_off(): void {
		$this->settings( array( 'skip_note' => 'false' ) );
		$order = $this->order( 'qa@example.com' );
		Woo2Odoo_Test_Orders::mark_skipped( $order, 'orders', 'qa@example.com' );
		$notes = array_filter(
			\wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static function ( $n ) {
				return false !== strpos( $n->content, 'omitido' );
			}
		);
		$this->assertCount( 0, $notes );
	}

	public function test_skipped_refund_does_not_change_the_order_sync_status(): void {
		$this->settings();
		$order = $this->order( 'qa@example.com', array( '_woo2odoo_sync_status' => 'pending' ) );
		Woo2Odoo_Test_Orders::mark_skipped( $order, 'refunds', 'qa@example.com' );
		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_meta( '_woo2odoo_sync_status' ) );
	}

	// ── Order manager guards: Odoo must not even be contacted ────────────────

	public function test_order_sync_skips_before_authenticating_with_odoo(): void {
		$this->settings();
		$order = $this->order( 'qa@example.com' );

		$this->mock_client->expects( $this->never() )->method( 'authenticate' );

		$this->assertFalse( $this->manager->order_sync( $order->get_id() ) );
		$this->assertTrue( Woo2Odoo_Test_Orders::is_skipped( \wc_get_order( $order->get_id() ) ) );
	}

	public function test_order_sync_still_authenticates_for_a_normal_customer(): void {
		$this->settings();
		$order = $this->order( 'real.customer@gmail.com' );

		// authenticate() fails -> order_sync returns false, but it MUST have been called.
		$this->mock_client->expects( $this->once() )->method( 'authenticate' )->willReturn( false );

		$this->assertFalse( $this->manager->order_sync( $order->get_id() ) );
		$this->assertFalse( Woo2Odoo_Test_Orders::is_skipped( \wc_get_order( $order->get_id() ) ) );
	}

	public function test_refund_sync_skips_before_authenticating_with_odoo(): void {
		$this->settings();
		$order = $this->order( 'qa@example.com' );

		$this->mock_client->expects( $this->never() )->method( 'authenticate' );

		$this->assertFalse( $this->manager->refund_sync( $order->get_id(), 999999 ) );
	}

	// ── Automatic hooks: decided BEFORE the Odoo client is even built ─────────
	// Woo2Odoo_Client's constructor talks to Odoo and throws when it is down, so the
	// guard inside order_sync() is unreachable in that case. These call the real hook
	// callbacks (which would build the real client) and must not throw or contact Odoo.

	public function test_maybe_skip_marks_a_matching_order_and_ignores_others(): void {
		$this->settings();
		$match = $this->order( 'qa@example.com' );
		$other = $this->order( 'real.customer@gmail.com' );

		$this->assertTrue( Woo2Odoo_Test_Orders::maybe_skip( $match->get_id(), 'orders' ) );
		$this->assertTrue( Woo2Odoo_Test_Orders::is_skipped( \wc_get_order( $match->get_id() ) ) );

		$this->assertFalse( Woo2Odoo_Test_Orders::maybe_skip( $other->get_id(), 'orders' ) );
		$this->assertFalse( Woo2Odoo_Test_Orders::is_skipped( \wc_get_order( $other->get_id() ) ) );
	}

	public function test_auto_sync_hook_skips_without_building_the_odoo_client(): void {
		$this->settings();
		$order = $this->order( 'qa+hook@example.com' );

		Woo2Odoo_Plugin::instance()->auto_sync_order( $order->get_id() );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertTrue( Woo2Odoo_Test_Orders::is_skipped( $fresh ) );
		$this->assertSame( '', (string) $fresh->get_meta( '_woo2odoo_sync_error' ) );
	}

	public function test_auto_refund_hook_skips_without_building_the_odoo_client(): void {
		$this->settings();
		$order = $this->order( 'qa@example.com' );

		Woo2Odoo_Plugin::instance()->auto_refund_order( $order->get_id(), 999999 );

		$notes = array_filter(
			\wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static function ( $n ) {
				return false !== strpos( $n->content, 'reembolso omitido' );
			}
		);
		$this->assertCount( 1, $notes );
	}

	public function test_auto_cancel_hook_ignores_a_skipped_unexported_order(): void {
		$this->settings();
		$order = $this->order( 'qa@example.com' );
		Woo2Odoo_Test_Orders::maybe_skip( $order->get_id(), 'orders' );

		$this->assertTrue( Woo2Odoo_Test_Orders::is_skipped_and_unexported( $order->get_id() ) );
		Woo2Odoo_Plugin::instance()->auto_cancel_order( $order->get_id() ); // must not throw nor contact Odoo
		$this->assertTrue( Woo2Odoo_Test_Orders::is_skipped( \wc_get_order( $order->get_id() ) ) );
	}

	public function test_a_skipped_order_that_is_already_in_odoo_is_not_treated_as_unexported(): void {
		$this->settings();
		$order = $this->order( 'qa@example.com', array( '_woo2odoo_sync_status' => 'skipped', '_odoo_sale_order_id' => 77 ) );
		$this->assertFalse( Woo2Odoo_Test_Orders::is_skipped_and_unexported( $order->get_id() ) );
	}
}
