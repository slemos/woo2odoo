<?php
/**
 * Woo2Odoo_Client Class File
 *
 * This file contains the OdooClient class, which is a wrapper for the Odoo JSON-RPC client.
 * It includes methods for handling exceptions and logging to WooCommerce logs.
 *
 * @package Woo2Odoo
 */
namespace Woo2Odoo;

use Woo2Odoo\Woo2Odoo_ClientFactory;
use Exception;


/**
 * Class Woo2odoo_Client
 */
class Woo2Odoo_Client {

	/**
	 * @var bool Indicates whether the client is authenticated
	 *
	 */
	private $is_authenticated = false;

	/**
	 * @var string The session ID returned by the Odoo server
	 */
	private $session_id;


	/**
	 * @var \winternet\odoo\JsonRpcClient
	 */
	private $client;

	/**
	 * @var string Last Odoo error message captured by log_exception(). Lets callers
	 *             surface the real cause (e.g. "RUT inválido") instead of a generic message.
	 */
	private $last_error = '';

	/**
	 * First fake id handed out in shadow mode. Far above any real record id, so a fake
	 * id can be told apart from a real one in logs and is never sent back to Odoo.
	 */
	public const SHADOW_FAKE_ID_BASE = 900000000;

	/**
	 * Odoo methods that only read. In shadow mode these still reach Odoo; any other
	 * method called through execute() is treated as a write and faked.
	 */
	public const READ_METHODS = array( 'read', 'search_read', 'search', 'search_count', 'name_search', 'name_get', 'fields_get', 'read_group', 'default_get', 'check_access_rights' );

	/** @var bool Shadow mode: writes are logged and faked, reads hit Odoo. */
	private $shadow = false;

	/** @var int Number of fake ids handed out so far (shadow mode). */
	private $shadow_seq = 0;

	/** @var array<int,bool> Fake ids handed out (shadow mode). */
	private $shadow_ids = array();

	/** @var array<int,array> Recorded write calls (shadow mode). */
	private $shadow_log = array();

	/**
	 * Constructor
	 *
	 * @param \winternet\odoo\JsonRpcClient|null $rpc    JSON-RPC client to use (tests); default from the factory.
	 * @param bool|null                         $shadow Force shadow mode; null = settings/constant.
	 */
	public function __construct( $rpc = null, ?bool $shadow = null ) {
		$this->client = $rpc ?? Woo2Odoo_ClientFactory::instance()->get_client();
		$this->shadow = null === $shadow ? Woo2Odoo_Config::shadow_mode() : ( $shadow || Woo2Odoo_Config::shadow_mode() );
	}

	/**
	 * Whether Odoo writes are faked (shadow mode).
	 *
	 * @return bool
	 */
	public function is_shadow_mode() {
		return $this->shadow;
	}

	/**
	 * Turn shadow mode on (or off, unless forced on by the setting/constant).
	 *
	 * @param bool $on Shadow mode wanted.
	 */
	public function set_shadow_mode( bool $on ): void {
		$this->shadow = $on || Woo2Odoo_Config::shadow_mode();
	}

	/**
	 * Write calls recorded in shadow mode, in call order.
	 * Each entry: seq, op (create|write|execute), model, method, args, result.
	 *
	 * @return array<int,array>
	 */
	public function get_shadow_log(): array {
		return $this->shadow_log;
	}

	/**
	 * Record a faked write and log it to the WC logger (source woo2odoo-shadow).
	 * Payloads are model values only; connection credentials are never part of them.
	 *
	 * @param string $op     create|write|execute.
	 * @param string $model  Odoo model.
	 * @param string $method Odoo method.
	 * @param mixed  $args   Values/arguments of the call.
	 * @param mixed  $result Fake result returned to the caller.
	 */
	private function shadow_record( string $op, string $model, string $method, $args, $result ): void {
		$entry              = array(
			'seq'    => count( $this->shadow_log ) + 1,
			'op'     => $op,
			'model'  => $model,
			'method' => $method,
			'args'   => $args,
			'result' => $result,
		);
		$this->shadow_log[] = $entry;
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info(
				sprintf( 'SHADOW %s %s.%s', $op, $model, $method ),
				array(
					'source'  => 'woo2odoo-shadow',
					'payload' => wp_json_encode( $entry ),
				)
			);
		}
	}

	/**
	 * Next deterministic fake id (shadow mode): 900000001, 900000002, ...
	 *
	 * @return int
	 */
	private function shadow_next_id(): int {
		++$this->shadow_seq;
		$id                      = self::SHADOW_FAKE_ID_BASE + $this->shadow_seq;
		$this->shadow_ids[ $id ] = true;
		return $id;
	}

	/**
	 * Whether a value (domain, ids, args...) references a fake id handed out in this run.
	 * Such reads are answered locally: Odoo has no such record.
	 *
	 * @param mixed $value Anything.
	 * @return bool
	 */
	private function references_fake_id( $value ): bool {
		if ( empty( $this->shadow_ids ) ) {
			return false;
		}
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			return isset( $this->shadow_ids[ (int) $value ] );
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			foreach ( (array) $value as $item ) {
				if ( $this->references_fake_id( $item ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Get the Odoo client
	 *
	 * @return \winternet\odoo\JsonRpcClient
	 */
	private function get_client() {
		if ( !$this->client ) {
			$this->client = Woo2Odoo_ClientFactory::instance()->get_client();
		}
		return $this->client;
	}

	/**
	 * Call to function search_read in Odoo
	 *
	 * @param array $args : Available keys:
	 *  - `where` : Filter/conditions (called "domain" in Odoo terms). Eg.: `[
	 *      [
	 *          'move_type',
	 *          '=',    // docs: https://stackoverflow.com/questions/29442993/which-are-the-available-domain-operators-in-openerp-odoo
	 *          'out_invoice',
	 *      ],
	 *  ]`
	 *  - `fields` : array of fields to return, eg. `['date', 'sequence_number']`
	 *  - `offset` : numeric, eg. `0`
	 *  - `limit` : numeric, eg. `20`
	 *  - `order` : Eg. `'name'` or `'name DESC'`
	 *
	 * @param array $options : Available options:
	 *   - `cache` : set true to cache the result for HOUR_IN_SECONDS. OPT-IN — default is no cache. Only use for immutable/slow-changing data (SKU→id maps, res.country/state). Never for mutable state (sale.order, account.move/payment, res.partner, stock).
	 *   - `indexBy` : field name to index the returned array by
	 *   - `single` : set true to return a single record, or null if nothing found. Or set string 'require' to throw Exception if nothing found
	 *   - `expandFields` : Expand a field with an array of record IDs into a new property called `_expanded`. Eg. `['invoice_line_ids' => ['model' => 'account.move.line']]�
	 */
	public function search_read( $model, $where = array(), $fields = array(), $offset = null, $limit = null, $order = null, $options = array() ) {

		if ( $this->shadow && $this->references_fake_id( $where ) ) {
			// Shadow mode: the record only exists in this run, Odoo cannot know it.
			return empty( $options['single'] ) ? array() : null;
		}

		if ( !$this->authenticate() ) {
			return false;
		}

		// Caching is OPT-IN, not the default. Odoo records that carry mutable state
		// (sale.order, account.move/payment, res.partner, stock levels…) must never be
		// served from a stale cache — doing so once made the order_sync guard link a
		// phantom SO that had already been deleted in Odoo. Callers pass `'cache' => true`
		// only for immutable/slow-changing lookups (SKU→id maps, res.country/state).
		// `cache` is a woo2odoo option, not an Odoo one, so strip it before forwarding.
		$use_cache = ! empty( $options['cache'] );
		unset( $options['cache'] );

		// Generate a unique cache key
		$cache_key = 'odoo_search_read_' . md5( wp_json_encode( array( $model, $where, $fields, $offset, $limit, $order, $options ) ) );

		// Check if the cache exists
		if ( $use_cache ) {
			$cached_result = wp_cache_get( $cache_key, 'woo2odoo' );
			if ( false !== $cached_result ) {
				return $cached_result;
			}
		}

		try {
			$args = array(
				'where'  => $where,
				'fields' => $fields,
				'offset' => $offset,
				'limit'  => $limit,
				'order'  => $order,
			);

			// Perform the search_read operation
			$result = $this->get_client()->searchRead( $model, $args, $options );

			// Store the result in cache if not null (only when caching was requested)
			if ( $result && $use_cache ) {
				wp_cache_set( $cache_key, $result, 'woo2odoo', HOUR_IN_SECONDS );
			}
			return $result;
		} catch ( Exception $e ) {
			$this->log_exception( 'Odoo search_read failed', $e );
			return false;
		}
	}

	/**
	 * Authenticate with Odoo
	 *
	 * @return bool
	 */
	public function authenticate() {
		try {
			if ( !$this->is_authenticated ) {
				$this->session_id       = $this->get_client()->authenticate();
				$this->is_authenticated = (bool) $this->session_id;
				if ( !$this->is_authenticated ) {
					$this->log_warning( 'Odoo authentication failed', array( 'session_id' => $this->session_id ) );
				}
			}
			return $this->is_authenticated;
		} catch ( Exception $e ) {
			$this->log_exception( 'Odoo authentication failed', $e );
			return false;
		}
	}

	/**
	 * Update record in Odoo by ID
	 * @param string $model Model name.
	 * @param int    $id Record ID.
	 * @param array  $data Data to update.
	 *
	 * @return array|false Updated record or false if error.
	 */
	public function update_record( $model, $id, $data ) {
		if ( $this->shadow ) {
			$this->shadow_record(
				'write',
				(string) $model,
				'write',
				array(
					'id'     => $id,
					'values' => $data,
				),
				true
			);
			return true;
		}
		if ( !$this->authenticate() ) {
			return false;
		}
		try {
			return $this->get_client()->update( $model, $id, $data );
		} catch ( Exception $e ) {
			$this->log_exception( 'Odoo update failed', $e );
			return false;
		}
	}

	/**
	 * Create record in Odoo
	 *
	 * @param string $model Model name.
	 * @param array  $data Data to create.
	 * @return array|bool
	 */
	public function create_record( $model, $data ) {
		if ( $this->shadow ) {
			$fake_id = $this->shadow_next_id();
			$this->shadow_record( 'create', (string) $model, 'create', $data, $fake_id );
			return $fake_id;
		}
		if ( !$this->authenticate() ) {
			return false;
		}
		try {
			return $this->get_client()->create( $model, $data );
		} catch ( Exception $e ) {
			$this->log_exception( 'Odoo create failed', $e );
			return false;
		}
	}

	public function execute( $model, $method, $args ) {
		if ( $this->shadow ) {
			if ( ! in_array( $method, self::READ_METHODS, true ) ) {
				$this->shadow_record( 'execute', (string) $model, (string) $method, $args, true );
				return true;
			}
			if ( $this->references_fake_id( $args ) ) {
				return array();
			}
		}
		if ( !$this->authenticate() ) {
			return false;
		}
		try {
			return $this->get_client()->execute( $model, $method, $args );
		} catch ( Exception $e ) {
			$this->log_exception( 'Odoo execute failed', $e );
			return false;
		}
	}

	/**
	 * Log an exception to WooCommerce logs
	 *
	 * @param string     $message   The message to log
	 * @param \Exception $exception The exception to log
	 */
	public function log_exception( $message, $exception ) {
		$this->last_error = $exception->getMessage();
		wc_get_logger()->error(
			$message,
			array(
				'message' => $exception->getMessage(),
				'code'    => $exception->getCode(),
				'trace'   => $exception->getTraceAsString(),
			)
		);
	}

	/**
	 * Return the last Odoo error message captured (empty string if none).
	 *
	 * @return string
	 */
	public function get_last_error() {
		return $this->last_error;
	}

	/**
	 * Log a warning to WooCommerce logs
	 *
	 * @param string $message The message to log
	 * @param array  $context Additional context to log
	 */
	public function log_warning( $message, $context = array() ) {
		wc_get_logger()->warning( $message, $context );
	}

	/**
	 * Log an info message to WooCommerce logs
	 *
	 * @param string $message The message to log
	 * @param array  $context Additional context to log
	 */
	public function log_info( $message, $context = array() ) {
		wc_get_logger()->info( $message, $context );
	}

	/**
	 * Log an error to WooCommerce logs
	 *
	 * @param string $message The message to log
	 * @param array  $context Additional context to log
	 */
	public function log_error( $message, $context = array() ) {
		wc_get_logger()->error( $message, $context );
	}

	/**
	 * Log a debug message to WooCommerce logs
	 *
	 * @param string $message The message to log
	 * @param array  $context Additional context to log
	 */
	public function log_debug( $message, $context = array() ) {
		wc_get_logger()->debug( $message, $context );
	}
}
