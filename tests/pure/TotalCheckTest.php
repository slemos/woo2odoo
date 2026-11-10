<?php
/**
 * Pure unit tests for the Woo vs Odoo total warning: tolerance, the decision between
 * "new / changed / unchanged / resolved" (which controls when a note is written), and the
 * note text. No WordPress or WooCommerce needed.
 *
 * Run: vendor/bin/phpunit -c phpunit-pure.xml
 */
namespace Woo2Odoo;

use PHPUnit\Framework\TestCase;

/**
 * @covers \Woo2Odoo\Woo2Odoo_Total_Check
 */
class TotalCheckTest extends TestCase {

	private const TOL = 2.0;

	/**
	 * @dataProvider tolerance_cases
	 */
	public function test_differs_uses_a_strict_tolerance( float $woo, float $odoo, bool $expected ): void {
		$this->assertSame( $expected, Woo2Odoo_Total_Check::differs( $woo, $odoo, self::TOL ) );
	}

	public static function tolerance_cases(): array {
		return array(
			'equal'                         => array( 35980.0, 35980.0, false ),
			'one peso of rounding'          => array( 35980.0, 35981.0, false ),
			'exactly the tolerance'         => array( 35980.0, 35982.0, false ),
			'just above the tolerance'      => array( 35980.0, 35983.0, true ),
			'woo higher, gift kept its VAT' => array( 29990.0, 24991.0, true ),
			'odoo higher, shipping added'   => array( 20000.0, 23990.0, true ),
			'float noise'                   => array( 0.1 + 0.2, 0.3, false ),
		);
	}

	public function test_diff_key_is_stable_and_has_two_decimals(): void {
		$this->assertSame( '35980.00|35983.00', Woo2Odoo_Total_Check::diff_key( 35980, 35983.0 ) );
		$this->assertSame( Woo2Odoo_Total_Check::diff_key( 10.0, 20.0 ), Woo2Odoo_Total_Check::diff_key( 10, 20 ) );
	}

	public function test_decide_match_with_nothing_stored_does_nothing(): void {
		$this->assertSame( 'none', Woo2Odoo_Total_Check::decide( '', 1000.0, 1001.0, self::TOL ) );
	}

	public function test_decide_first_difference_is_new(): void {
		$this->assertSame( 'new', Woo2Odoo_Total_Check::decide( '', 1000.0, 1500.0, self::TOL ) );
	}

	public function test_decide_same_difference_again_is_unchanged_so_no_duplicate_note(): void {
		$key = Woo2Odoo_Total_Check::diff_key( 1000.0, 1500.0 );
		$this->assertSame( 'unchanged', Woo2Odoo_Total_Check::decide( $key, 1000.0, 1500.0, self::TOL ) );
	}

	public function test_decide_different_amounts_is_changed(): void {
		$key = Woo2Odoo_Total_Check::diff_key( 1000.0, 1500.0 );
		$this->assertSame( 'changed', Woo2Odoo_Total_Check::decide( $key, 1200.0, 1500.0, self::TOL ) );
		$this->assertSame( 'changed', Woo2Odoo_Total_Check::decide( $key, 1000.0, 1800.0, self::TOL ) );
	}

	public function test_decide_match_after_a_stored_difference_is_resolved(): void {
		$key = Woo2Odoo_Total_Check::diff_key( 1000.0, 1500.0 );
		$this->assertSame( 'resolved', Woo2Odoo_Total_Check::decide( $key, 1500.0, 1500.0, self::TOL ) );
	}

	public function test_note_text_shows_both_totals_the_sale_order_and_the_signed_difference(): void {
		$note = Woo2Odoo_Total_Check::note_text( 'new', 29990.0, 24991.0, 'S12345' );
		$this->assertStringContainsString( '$29.990', $note );
		$this->assertStringContainsString( '$24.991', $note );
		$this->assertStringContainsString( 'S12345', $note );
		$this->assertStringContainsString( '+$4.999', $note );
		$this->assertStringContainsString( 'no se corrigió nada', $note );

		$neg = Woo2Odoo_Total_Check::note_text( 'changed', 20000.0, 23990.0, 'S1' );
		$this->assertStringContainsString( '-$3.990', $neg );
	}

	public function test_resolved_note_says_the_totals_now_match(): void {
		$note = Woo2Odoo_Total_Check::note_text( 'resolved', 1500.0, 1500.0, 'S1' );
		$this->assertStringContainsString( 'se resolvió', $note );
		$this->assertStringNotContainsString( 'no se corrigió', $note );
	}

	public function test_check_statuses_are_the_ones_live_in_odoo(): void {
		$this->assertSame( array( 'on-hold', 'processing', 'completed', 'en-despacho' ), Woo2Odoo_Total_Check::CHECK_STATUSES );
	}
}
