<?php
/**
 * Pure unit tests for the Test / Debug skip-list: pattern parsing, safety rules and
 * email matching. No WordPress or WooCommerce needed.
 *
 * Run: vendor/bin/phpunit --bootstrap vendor/autoload.php tests/test-test-orders-patterns.php
 */
namespace Woo2Odoo;

use PHPUnit\Framework\TestCase;

/**
 * @covers \Woo2Odoo\Woo2Odoo_Test_Orders
 */
class Test_Orders_Patterns_Test extends TestCase {

	public function test_parse_trims_lowercases_and_ignores_blanks_and_comments(): void {
		$raw = "  QA@Example.com \r\n\r\n# a comment\n   \n qa+*@example.com\r\n#another\n";
		$this->assertSame(
			array( 'qa@example.com', 'qa+*@example.com' ),
			Woo2Odoo_Test_Orders::parse_patterns( $raw )
		);
	}

	public function test_parse_removes_duplicates_keeping_first_order(): void {
		$raw = "b@x.cl\na@x.cl\nB@X.CL\n";
		$this->assertSame( array( 'b@x.cl', 'a@x.cl' ), Woo2Odoo_Test_Orders::parse_patterns( $raw ) );
	}

	public function test_parse_empty_input(): void {
		$this->assertSame( array(), Woo2Odoo_Test_Orders::parse_patterns( '' ) );
		$this->assertSame( array(), Woo2Odoo_Test_Orders::parse_patterns( "\n \n#only comment" ) );
	}

	/**
	 * @dataProvider valid_patterns
	 */
	public function test_valid_patterns_are_accepted( string $pattern ): void {
		$this->assertSame( '', Woo2Odoo_Test_Orders::pattern_problem( $pattern ) );
	}

	public static function valid_patterns(): array {
		return array(
			'exact email'                    => array( 'qa@example.com' ),
			'plus alias wildcard'            => array( 'qa+*@example.com' ),
			'own company domain wildcard'    => array( '*@pink-mask.cl' ),
			'single char wildcard'           => array( 'qa?@example.com' ),
			'exact on public provider'       => array( 'sebastian@gmail.com' ),
			'prefix wildcard on public host' => array( 'sebastian+*@gmail.com' ),
		);
	}

	/**
	 * @dataProvider rejected_patterns
	 */
	public function test_dangerous_or_malformed_patterns_are_rejected( string $pattern, string $reason ): void {
		$this->assertSame( $reason, Woo2Odoo_Test_Orders::pattern_problem( $pattern ) );
	}

	public static function rejected_patterns(): array {
		return array(
			'everything'                  => array( '*@*', 'broad_domain' ),
			'wildcard in domain'          => array( 'qa@*.com', 'broad_domain' ),
			'wildcard domain label'       => array( 'qa@exam*.com', 'broad_domain' ),
			'whole gmail'                 => array( '*@gmail.com', 'broad_public' ),
			'whole hotmail via ?*'        => array( '?*@hotmail.com', 'broad_public' ),
			'whole outlook'               => array( '*@outlook.com', 'broad_public' ),
			'no at sign'                  => array( 'qa.example.com', 'no_at' ),
			'two at signs'                => array( 'a@b@example.com', 'no_at' ),
			'space inside'                => array( 'qa @example.com', 'syntax' ),
			'brackets (glob class)'       => array( '[a-z]@example.com', 'syntax' ),
			'empty local part'            => array( '@example.com', 'syntax' ),
			'empty domain'                => array( 'qa@', 'syntax' ),
			'bare wildcard'               => array( '*', 'no_at' ),
		);
	}

	public function test_sanitize_list_splits_valid_and_rejected_and_lowercases(): void {
		$result = Woo2Odoo_Test_Orders::sanitize_list( "QA@Example.com\n*@GMAIL.com\nqa+*@example.com\n*@*\n" );
		$this->assertSame( array( 'qa@example.com', 'qa+*@example.com' ), $result['valid'] );
		$this->assertSame(
			array( '*@gmail.com' => 'broad_public', '*@*' => 'broad_domain' ),
			$result['rejected']
		);
	}

	public function test_exact_match_is_case_insensitive(): void {
		$this->assertSame( 'qa@example.com', Woo2Odoo_Test_Orders::matching_pattern( 'QA@Example.COM', array( 'qa@example.com' ) ) );
	}

	public function test_exact_pattern_does_not_match_lookalikes(): void {
		$p = array( 'qa@example.com' );
		$this->assertSame( '', Woo2Odoo_Test_Orders::matching_pattern( 'xqa@example.com', $p ) );
		$this->assertSame( '', Woo2Odoo_Test_Orders::matching_pattern( 'qa@example.com.evil.io', $p ) );
		$this->assertSame( '', Woo2Odoo_Test_Orders::matching_pattern( 'qa@sub.example.com', $p ) );
	}

	public function test_plus_alias_wildcard_requires_the_plus_part(): void {
		$p = array( 'qa+*@example.com' );
		$this->assertSame( 'qa+*@example.com', Woo2Odoo_Test_Orders::matching_pattern( 'qa+order1@example.com', $p ) );
		$this->assertSame( '', Woo2Odoo_Test_Orders::matching_pattern( 'qa@example.com', $p ) );
		$this->assertSame( '', Woo2Odoo_Test_Orders::matching_pattern( 'other+order1@example.com', $p ) );
	}

	public function test_company_domain_wildcard_matches_only_that_domain(): void {
		$p = array( '*@pink-mask.cl' );
		$this->assertSame( '*@pink-mask.cl', Woo2Odoo_Test_Orders::matching_pattern( 'anyone@pink-mask.cl', $p ) );
		$this->assertSame( '', Woo2Odoo_Test_Orders::matching_pattern( 'anyone@pink-mask.cl.fake.io', $p ) );
		$this->assertSame( '', Woo2Odoo_Test_Orders::matching_pattern( 'anyone@notpink-mask.cl', $p ) );
	}

	public function test_question_mark_matches_exactly_one_char(): void {
		$p = array( 'qa?@example.com' );
		$this->assertSame( 'qa?@example.com', Woo2Odoo_Test_Orders::matching_pattern( 'qa1@example.com', $p ) );
		$this->assertSame( '', Woo2Odoo_Test_Orders::matching_pattern( 'qa@example.com', $p ) );
		$this->assertSame( '', Woo2Odoo_Test_Orders::matching_pattern( 'qa12@example.com', $p ) );
	}

	public function test_empty_email_or_empty_list_never_matches(): void {
		$this->assertSame( '', Woo2Odoo_Test_Orders::matching_pattern( '', array( '*@pink-mask.cl' ) ) );
		$this->assertSame( '', Woo2Odoo_Test_Orders::matching_pattern( '   ', array( '*@pink-mask.cl' ) ) );
		$this->assertSame( '', Woo2Odoo_Test_Orders::matching_pattern( 'a@b.cl', array() ) );
	}

	public function test_first_matching_pattern_wins(): void {
		$p = array( 'qa+*@example.com', '*@example.com' );
		$this->assertSame( 'qa+*@example.com', Woo2Odoo_Test_Orders::matching_pattern( 'qa+x@example.com', $p ) );
	}
}
