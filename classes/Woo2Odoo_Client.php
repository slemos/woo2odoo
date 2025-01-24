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
	 * Constructor
	 */
	public function __construct() {
		$this->client = Woo2Odoo_ClientFactory::instance()->get_client();
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
	 *   - `indexBy` : field name to index the returned array by
	 *   - `single` : set true to return a single record, or null if nothing found. Or set string 'require' to throw Exception if nothing found
	 *   - `expandFields` : Expand a field with an array of record IDs into a new property called `_expanded`. Eg. `['invoice_line_ids' => ['model' => 'account.move.line']]�
	 */
	public function search_read( $model, $where = array(), $fields = array(), $offset = null, $limit = null, $order = null, $options = array() ) {

		if ( !$this->authenticate() ) {
			return false;
		}
		// Generate a unique cache key
		$cache_key = 'odoo_search_read_' . md5( wp_json_encode( func_get_args() ) );

		// Check if the cache exists
		$cached_result = wp_cache_get( $cache_key, 'woo2odoo' );
		if ( false !== $cached_result ) {
			return $cached_result;
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

			// Store the result in a transient if not null
			if ( $result ) {
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

	/**
	 * Log an exception to WooCommerce logs
	 *
	 * @param string     $message   The message to log
	 * @param \Exception $exception The exception to log
	 */
	public function log_exception( $message, $exception ) {
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
