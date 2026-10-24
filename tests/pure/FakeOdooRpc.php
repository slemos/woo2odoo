<?php
/**
 * In-memory stand-in for \winternet\odoo\JsonRpcClient.
 *
 * Woo2Odoo_Client is constructed with this object instead of the real JSON-RPC
 * client, so the plugin's real client code (including shadow mode) runs, but no
 * request ever leaves the process. Every call is recorded in $calls, which the
 * tests compare (the "payloads sent to Odoo").
 *
 * It keeps a tiny record store per model and evaluates simple domains
 * (=, !=, in, like, ilike, =ilike) so lookups behave like Odoo for the cases
 * the plugin uses.
 *
 * @package Woo2Odoo
 */

// phpcs:ignoreFile

class FakeOdooRpc {

	/** @var array<int,array> Recorded calls: [method, model, ...args]. */
	public $calls = array();

	/** @var array<string,array<int,array>> model => id => values. */
	public $records = array();

	/** @var int Next id for created records. */
	private $next_id = 1000;

	/** Many2one fields returned as [id, name] by reads. */
	const M2O = array( 'product_id', 'partner_id', 'order_id', 'commercial_partner_id', 'move_id', 'country_id', 'parent_id' );

	public function __construct() {
		$this->records = array(
			'res.country'       => array( 46 => array( 'id' => 46, 'code' => 'CL', 'name' => 'Chile' ) ),
			'res.country.state' => array( 1171 => array( 'id' => 1171, 'name' => 'Metropolitana', 'country_id' => 46 ) ),
			'product.product'   => array(
				11 => array( 'id' => 11, 'default_code' => 'SKU-A', 'name' => 'Esmalte A' ),
				12 => array( 'id' => 12, 'default_code' => 'SKU-B', 'name' => 'Lima B' ),
				99 => array( 'id' => 99, 'default_code' => 'FEE-SVC', 'name' => 'Recargo' ),
			),
		);
	}

	/** Add a record (e.g. an existing partner or sale order). */
	public function seed( string $model, array $values ): void {
		$this->records[ $model ][ $values['id'] ] = $values;
	}

	public function authenticate() {
		return 7;
	}

	public function searchRead( $model, $args = array(), $options = array() ) {
		$this->calls[] = array( 'searchRead', $model, $args, $options );
		$rows          = $this->search( $model, $args['where'] ?? array(), $args['fields'] ?? array(), $args['limit'] ?? null );
		return $this->shape( $rows, $options );
	}

	public function create( $model, $fields ) {
		$this->calls[] = array( 'create', $model, $fields );
		$id            = $this->next_id++;
		$values        = array_merge( (array) $fields, array( 'id' => $id ) );
		if ( 'sale.order' === $model ) {
			$values['name']           = 'S' . $id;
			$values['invoice_status'] = 'to invoice';
		}
		$this->records[ $model ][ $id ] = $values;
		return $id;
	}

	public function update( $model, $id, $fields ) {
		$this->calls[] = array( 'update', $model, $id, $fields );
		if ( isset( $this->records[ $model ][ $id ] ) ) {
			$this->records[ $model ][ $id ] = array_merge( $this->records[ $model ][ $id ], (array) $fields );
		}
		return true;
	}

	public function execute( $model, $method, $args, $options = array() ) {
		$this->calls[] = array( 'execute', $model, $method, $args );
		if ( 'read' === $method ) {
			$ids    = (array) $args[0];
			$fields = $args[1] ?? array();
			$out    = array();
			foreach ( $ids as $id ) {
				if ( isset( $this->records[ $model ][ $id ] ) ) {
					$out[] = $this->project( $model, $this->records[ $model ][ $id ], $fields );
				}
			}
			return $out;
		}
		if ( 'search_read' === $method ) {
			return $this->search( $model, $args[0] ?? array(), $args[1] ?? array(), null );
		}
		if ( 'account.payment' === $model && 'action_post' === $method ) {
			foreach ( (array) $args[0] as $id ) {
				$this->records[ $model ][ $id ]['state']   = 'in_process';
				$this->records[ $model ][ $id ]['move_id'] = 5000 + $id;
				$this->records[ $model ][ $id ]['name']    = 'PBNK1/' . $id;
			}
		}
		if ( 'res.partner' === $model && 'read' !== $method ) {
			return true;
		}
		return true;
	}

	/** Write calls only (create/update/execute of a non-read method). */
	public function writes(): array {
		return array_values(
			array_filter(
				$this->calls,
				static fn( $c ) => in_array( $c[0], array( 'create', 'update' ), true ) || ( 'execute' === $c[0] && ! in_array( $c[2], array( 'read', 'search_read' ), true ) )
			)
		);
	}

	/** Recorded calls of one kind on one model. */
	public function calls_for( string $method, string $model ): array {
		return array_values( array_filter( $this->calls, static fn( $c ) => $c[0] === $method && $c[1] === $model ) );
	}

	// ── internals ──────────────────────────────────────────────────────────────

	private function search( string $model, $where, $fields, $limit ): array {
		$out = array();
		foreach ( $this->records[ $model ] ?? array() as $rec ) {
			if ( $this->matches( $rec, (array) $where ) ) {
				$out[] = $this->project( $model, $rec, $fields );
				if ( $limit && count( $out ) >= $limit ) {
					break;
				}
			}
		}
		return $out;
	}

	private function matches( array $rec, array $where ): bool {
		foreach ( $where as $cond ) {
			list( $field, $op, $value ) = $cond;
			$field  = preg_replace( '/\.id$/', '', $field );
			$actual = $rec[ $field ] ?? false;
			if ( is_array( $actual ) && in_array( $field, self::M2O, true ) ) {
				$actual = $actual[0];
			}
			switch ( $op ) {
				case '=':
					if ( is_bool( $value ) || is_bool( $actual ) ) {
						$ok = (bool) $actual === (bool) $value;
					} else {
						$ok = (string) $actual === (string) $value;
					}
					break;
				case '!=':
					$ok = (string) $actual !== (string) $value;
					break;
				case 'in':
					$ok = in_array( (string) $actual, array_map( 'strval', (array) $value ), true );
					break;
				case '=ilike':
					$ok = strtolower( (string) $actual ) === strtolower( (string) $value );
					break;
				case 'like':
				case 'ilike':
					$re = '/^' . str_replace( '%', '.*', preg_quote( (string) $value, '/' ) ) . '$/i';
					$ok = (bool) preg_match( $re, (string) $actual );
					break;
				default:
					throw new RuntimeException( "FakeOdooRpc: unsupported operator {$op}" );
			}
			if ( ! $ok ) {
				return false;
			}
		}
		return true;
	}

	private function project( string $model, array $rec, $fields ): object {
		$row = array( 'id' => $rec['id'] );
		$keys = empty( $fields ) ? array_keys( $rec ) : (array) $fields;
		foreach ( $keys as $f ) {
			$v = $rec[ $f ] ?? false;
			if ( in_array( $f, self::M2O, true ) && ( is_int( $v ) || ( is_string( $v ) && ctype_digit( $v ) ) ) ) {
				$v = array( (int) $v, 'Record ' . $v );
			}
			$row[ $f ] = $v;
		}
		if ( 'res.partner' === $model && in_array( 'commercial_partner_id', $keys, true ) && false === $row['commercial_partner_id'] ) {
			$parent                       = $rec['parent_id'] ?? false;
			$cid                          = $parent ? ( is_array( $parent ) ? $parent[0] : (int) $parent ) : $rec['id'];
			$row['commercial_partner_id'] = array( $cid, 'Record ' . $cid );
		}
		return (object) $row;
	}

	private function shape( array $rows, array $options ) {
		if ( ! empty( $options['single'] ) ) {
			return $rows ? $rows[0] : null;
		}
		if ( ! empty( $options['indexBy'] ) ) {
			$out = array();
			foreach ( $rows as $row ) {
				$out[ $row->{ $options['indexBy'] } ] = $row;
			}
			return $out;
		}
		return $rows;
	}
}
