<?php
/**
 * Bootstrap for the pure PHPUnit suite (phpunit-pure.xml).
 *
 * Runs WITHOUT WordPress, WooCommerce or Odoo: it defines the handful of WP/WC
 * functions the plugin classes call, small fake WC_Order / item / user classes,
 * a fake $wpdb for the sync lock, and an in-memory fake Odoo JSON-RPC client
 * (tests/pure/FakeOdooRpc.php). Nothing here can reach a network.
 *
 * Run: vendor/bin/phpunit -c phpunit-pure.xml
 *
 * @package Woo2Odoo
 */

// phpcs:ignoreFile

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

/**
 * Global state shared by the stubs; reset by Woo2Odoo_Pure_TestCase::setUp().
 */
final class WP_Stub_State {
	/** @var array<string,mixed> */
	public static $options = array();
	/** @var array<string,array<int,callable[]>> */
	public static $filters = array();
	/** @var array<int,array<string,mixed>> user id => meta */
	public static $user_meta = array();
	/** @var array<int,WP_User> */
	public static $users = array();
	/** @var array<int,WC_Order> */
	public static $orders = array();
	/** @var int */
	public static $wc_get_order_calls = 0;
	/** @var array<int,array> */
	public static $logs = array();
	/** @var array<string,mixed> */
	public static $cache = array();

	public static function reset(): void {
		self::$options            = array();
		self::$filters            = array();
		self::$user_meta          = array();
		self::$users              = array();
		self::$orders             = array();
		self::$wc_get_order_calls = 0;
		self::$logs               = array();
		self::$cache              = array();
		$GLOBALS['wpdb']          = new Fake_WPDB();
	}
}

// ── WordPress function stubs ────────────────────────────────────────────────

function get_option( $name, $default = false ) {
	return array_key_exists( $name, WP_Stub_State::$options ) ? WP_Stub_State::$options[ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	WP_Stub_State::$options[ $name ] = $value;
	return true;
}
function add_filter( $hook, $cb, $priority = 10, $accepted_args = 1 ) {
	WP_Stub_State::$filters[ $hook ][ $priority ][] = $cb;
	return true;
}
function add_action( $hook, $cb, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $hook, $cb, $priority, $accepted_args );
}
function apply_filters( $hook, $value, ...$args ) {
	if ( empty( WP_Stub_State::$filters[ $hook ] ) ) {
		return $value;
	}
	ksort( WP_Stub_State::$filters[ $hook ] );
	foreach ( WP_Stub_State::$filters[ $hook ] as $callbacks ) {
		foreach ( $callbacks as $cb ) {
			$value = $cb( $value, ...$args );
		}
	}
	return $value;
}
function get_user_meta( $user_id, $key = '', $single = false ) {
	$meta = WP_Stub_State::$user_meta[ $user_id ] ?? array();
	if ( '' === $key ) {
		$out = array();
		foreach ( $meta as $k => $v ) {
			$out[ $k ] = array( $v );
		}
		return $out;
	}
	if ( ! array_key_exists( $key, $meta ) ) {
		return $single ? '' : array();
	}
	return $single ? $meta[ $key ] : array( $meta[ $key ] );
}
function get_userdata( $user_id ) {
	return WP_Stub_State::$users[ $user_id ] ?? false;
}
function wc_get_order( $order_id ) {
	++WP_Stub_State::$wc_get_order_calls;
	return WP_Stub_State::$orders[ (int) $order_id ] ?? false;
}
function wc_get_logger() {
	static $logger = null;
	if ( null === $logger ) {
		$logger = new class() {
			public function __call( $level, $args ) {
				WP_Stub_State::$logs[] = array( 'level' => $level, 'message' => $args[0] ?? '', 'context' => $args[1] ?? array() );
			}
		};
	}
	return $logger;
}
function __( $text, $domain = 'default' ) {
	return $text;
}
function esc_url( $url ) {
	return (string) $url;
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function current_time( $type ) {
	return '2026-10-24 12:00:00';
}
function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}
function wp_generate_password( $length = 12, $special = true ) {
	return substr( bin2hex( random_bytes( $length ) ), 0, $length );
}
function wp_cache_get( $key, $group = '' ) {
	return WP_Stub_State::$cache[ $group . ':' . $key ] ?? false;
}
function wp_cache_set( $key, $value, $group = '', $ttl = 0 ) {
	WP_Stub_State::$cache[ $group . ':' . $key ] = $value;
	return true;
}

/**
 * Fake $wpdb implementing just the options-table statements used by Woo2Odoo_Sync_Lock.
 * prepare() returns a structured object instead of SQL so query()/get_var() can interpret it.
 */
class Fake_WPDB {
	public $options = 'wp_options';
	/** @var array<string,string> option_name => option_value */
	public $rows = array();

	public function prepare( $sql, ...$args ) {
		return (object) array( 'sql' => $sql, 'args' => $args );
	}
	public function query( $q ) {
		$sql = $q->sql;
		$a   = $q->args;
		if ( 0 === strpos( $sql, 'INSERT IGNORE' ) ) {
			if ( isset( $this->rows[ $a[0] ] ) ) {
				return 0;
			}
			$this->rows[ $a[0] ] = $a[1];
			return 1;
		}
		if ( 0 === strpos( $sql, 'UPDATE' ) ) {
			if ( isset( $this->rows[ $a[1] ] ) && $this->rows[ $a[1] ] === $a[2] ) {
				$this->rows[ $a[1] ] = $a[0];
				return 1;
			}
			return 0;
		}
		if ( 0 === strpos( $sql, 'DELETE' ) ) {
			if ( isset( $this->rows[ $a[0] ] ) && $this->rows[ $a[0] ] === $a[1] ) {
				unset( $this->rows[ $a[0] ] );
				return 1;
			}
			return 0;
		}
		throw new RuntimeException( 'Fake_WPDB: unsupported query ' . $sql );
	}
	public function get_var( $q ) {
		return $this->rows[ $q->args[0] ] ?? null;
	}
}

// ── WooCommerce / WordPress object fakes ────────────────────────────────────

class WP_User {
	public $ID;
	public $user_email;
	public $display_name;
	public $user_login;
	public function __construct( int $id, string $email, string $display_name = '' ) {
		$this->ID           = $id;
		$this->user_email   = $email;
		$this->display_name = $display_name;
		$this->user_login   = 'user' . $id;
	}
}

class Fake_Product {
	private $sku;
	public function __construct( string $sku ) {
		$this->sku = $sku;
	}
	public function get_sku() {
		return $this->sku;
	}
}

class Fake_Line_Item {
	private $d;
	public function __construct( array $d ) {
		$this->d = $d;
	}
	public function get_product() {
		return new Fake_Product( $this->d['sku'] );
	}
	public function get_quantity() {
		return $this->d['qty'];
	}
	public function get_total() {
		return $this->d['total'];
	}
	public function get_total_tax() {
		return $this->d['tax'] ?? 0;
	}
	public function get_name() {
		return $this->d['name'] ?? $this->d['sku'];
	}
}

class Fake_Fee_Item {
	private $d;
	public function __construct( array $d ) {
		$this->d = $d;
	}
	public function get_total() {
		return $this->d['total'];
	}
	public function get_total_tax() {
		return $this->d['tax'] ?? 0;
	}
	public function get_name() {
		return $this->d['name'];
	}
}

/**
 * Minimal WC_Order. `new WC_Order( $id )` loads the registered order's data (as the
 * real class loads from the data store), which Woo2Odoo_Shadow_Order relies on.
 */
class WC_Order {
	/** @var array */
	public $d;
	/** @var string[] */
	public $notes = array();
	/** @var int */
	public $saves = 0;

	public function __construct( $data = 0 ) {
		if ( is_array( $data ) ) {
			$this->d = $data;
		} elseif ( isset( WP_Stub_State::$orders[ (int) $data ] ) ) {
			$this->d = WP_Stub_State::$orders[ (int) $data ]->d; // copy, like a fresh read
		} else {
			$this->d = array( 'id' => (int) $data );
		}
		$this->d += array(
			'status'         => 'processing',
			'items'          => array(),
			'fees'           => array(),
			'meta'           => array(),
			'shipping_total' => '0',
			'shipping_tax'   => '0',
			'billing'        => array(),
			'shipping'       => array(),
			'user'           => false,
			'customer_note'  => '',
			'payment_method' => '',
			'total'          => '0',
			'date_created'   => '2026-10-20 10:00:00',
			'date_paid'      => null,
		);
	}
	public function get_id() {
		return $this->d['id'];
	}
	public function get_status() {
		return $this->d['status'];
	}
	public function get_items() {
		return array_map( static fn( $i ) => new Fake_Line_Item( $i ), $this->d['items'] );
	}
	public function get_fees() {
		return array_map( static fn( $f ) => new Fake_Fee_Item( $f ), $this->d['fees'] );
	}
	public function get_meta( $key = '', $single = true, $context = 'view' ) {
		return $this->d['meta'][ $key ] ?? '';
	}
	public function meta_exists( $key = '' ) {
		return array_key_exists( $key, $this->d['meta'] );
	}
	public function update_meta_data( $key, $value, $meta_id = 0 ) {
		$this->d['meta'][ $key ] = $value;
	}
	public function delete_meta_data( $key ) {
		unset( $this->d['meta'][ $key ] );
	}
	public function save() {
		++$this->saves;
		return $this->get_id();
	}
	public function add_order_note( $note, $is_customer_note = 0, $added_by_user = false, $meta_data = array() ) {
		$this->notes[] = $note;
		return count( $this->notes );
	}
	public function get_address( $type = 'billing' ) {
		$defaults = array(
			'first_name' => '', 'last_name' => '', 'company' => '', 'address_1' => '', 'address_2' => '',
			'city' => '', 'state' => '', 'postcode' => '', 'country' => '', 'email' => '', 'phone' => '',
		);
		return array_merge( $defaults, $this->d[ $type ] );
	}
	public function get_user() {
		return $this->d['user'];
	}
	public function get_customer_id() {
		return $this->d['user'] ? $this->d['user']->ID : 0;
	}
	public function get_billing_email() {
		return $this->d['billing']['email'] ?? '';
	}
	public function get_billing_company() {
		return $this->d['billing']['company'] ?? '';
	}
	public function get_customer_note() {
		return $this->d['customer_note'];
	}
	public function get_shipping_total( $context = 'view' ) {
		return $this->d['shipping_total'];
	}
	public function get_shipping_tax( $context = 'view' ) {
		return $this->d['shipping_tax'];
	}
	public function get_payment_method() {
		return $this->d['payment_method'];
	}
	public function get_total() {
		return $this->d['total'];
	}
	public function get_date_paid() {
		return $this->d['date_paid'] ? new DateTime( $this->d['date_paid'] ) : null;
	}
	public function get_date_created() {
		return new DateTime( $this->d['date_created'] );
	}
}

require_once __DIR__ . '/FakeOdooRpc.php';
require_once __DIR__ . '/Woo2Odoo_Pure_TestCase.php';
require_once dirname( __DIR__ ) . '/fixtures/Legacy_Order_Manager_1_6_1.php';
