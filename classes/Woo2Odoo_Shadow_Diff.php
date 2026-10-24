<?php
/**
 * Woo2Odoo_Shadow_Diff Class File
 *
 * Compares the sale.order payload captured by a shadow run with the sale order
 * that already exists in Odoo for the same origin. Read-only on Odoo.
 *
 * @package Woo2Odoo
 */
namespace Woo2Odoo;

/**
 * Class Woo2Odoo_Shadow_Diff
 */
class Woo2Odoo_Shadow_Diff {

	/** sale.order header fields compared when the shadow payload sets them. */
	public const HEADER_FIELDS = array( 'partner_id', 'partner_invoice_id', 'partner_shipping_id', 'origin', 'client_order_ref', 'user_id', 'team_id', 'payment_term_id', 'warehouse_id' );

	/**
	 * Build the comparison rows.
	 *
	 * @param Woo2Odoo_Client $client Client (reads go to Odoo even in shadow mode).
	 * @param array           $log    Woo2Odoo_Client::get_shadow_log().
	 * @param string          $origin Origin to look up (prefix + WC order id).
	 * @return array<int,array{field:string,shadow:string,odoo:string,match:string}>|null Null when no sale order exists.
	 */
	public static function compare( Woo2Odoo_Client $client, array $log, string $origin ): ?array {
		$so = $client->search_read(
			'sale.order',
			array(
				array( 'origin', '=', $origin ),
				array( 'state', '!=', 'cancel' ),
			),
			array_merge( array( 'id', 'name', 'amount_untaxed' ), self::HEADER_FIELDS ),
			null,
			1,
			null,
			array( 'single' => true )
		);
		if ( ! $so ) {
			return null;
		}

		$odoo_lines = $client->search_read(
			'sale.order.line',
			array( array( 'order_id', '=', (int) $so->id ) ),
			array( 'product_id', 'product_uom_qty', 'price_unit', 'display_type', 'name' )
		);

		return self::rows( $log, $so, is_array( $odoo_lines ) ? $odoo_lines : array() );
	}

	/**
	 * Pure comparison (no Odoo access), for tests.
	 *
	 * @param array  $log        Shadow log.
	 * @param object $so         Existing sale.order record.
	 * @param array  $odoo_lines Existing sale.order.line records.
	 * @return array<int,array{field:string,shadow:string,odoo:string,match:string}>
	 */
	public static function rows( array $log, $so, array $odoo_lines ): array {
		$shadow_so    = array();
		$shadow_lines = array();
		foreach ( $log as $entry ) {
			if ( 'create' !== $entry['op'] ) {
				continue;
			}
			if ( 'sale.order' === $entry['model'] && empty( $shadow_so ) ) {
				$shadow_so = (array) $entry['args'];
			} elseif ( 'sale.order.line' === $entry['model'] && empty( $entry['args']['display_type'] ) ) {
				$shadow_lines[] = (array) $entry['args'];
			}
		}

		$rows   = array();
		$rows[] = self::row( 'sale.order', 'created', (string) ( $so->name ?? $so->id ), true );
		foreach ( self::HEADER_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $shadow_so ) ) {
				continue;
			}
			$shadow = self::scalar( $shadow_so[ $field ] );
			$odoo   = self::scalar( $so->$field ?? '' );
			$rows[] = self::row( $field, $shadow, $odoo, $shadow === $odoo );
		}

		// Lines, grouped by product: total quantity and amount.
		$group             = static function ( array $lines, bool $is_odoo ): array {
			$out = array();
			foreach ( $lines as $line ) {
				$line = (array) $line;
				if ( $is_odoo && ! empty( $line['display_type'] ) ) {
					continue;
				}
				$product = self::scalar( $line['product_id'] ?? '' );
				if ( '' === $product || 'false' === $product ) {
					continue;
				}
				if ( ! isset( $out[ $product ] ) ) {
					$out[ $product ] = array(
						'qty'    => 0.0,
						'amount' => 0.0,
					);
				}
				$qty                        = (float) ( $line['product_uom_qty'] ?? 0 );
				$out[ $product ]['qty']    += $qty;
				$out[ $product ]['amount'] += $qty * (float) ( $line['price_unit'] ?? 0 );
			}
			return $out;
		};
		$shadow_by_product = $group( $shadow_lines, false );
		$odoo_by_product   = $group( $odoo_lines, true );

		$untaxed  = 0.0;
		$products = array_unique( array_merge( array_keys( $shadow_by_product ), array_keys( $odoo_by_product ) ) );
		sort( $products );
		foreach ( $products as $product ) {
			$s        = $shadow_by_product[ $product ] ?? null;
			$o        = $odoo_by_product[ $product ] ?? null;
			$untaxed += $s ? $s['amount'] : 0.0;
			$fmt      = static fn( $v ) => $v ? sprintf( '%g x = %.2f', $v['qty'], $v['amount'] ) : '(none)';
			$is_match = $s && $o && abs( $s['qty'] - $o['qty'] ) < 0.0001 && abs( $s['amount'] - $o['amount'] ) < 1.0;
			$rows[]   = self::row( 'line product ' . $product, $fmt( $s ), $fmt( $o ), $is_match );
		}

		$odoo_untaxed = (float) ( $so->amount_untaxed ?? 0 );
		// CLP amounts: tolerate rounding of per-unit prices (< 1 peso).
		$rows[] = self::row( 'amount_untaxed', sprintf( '%.2f', $untaxed ), sprintf( '%.2f', $odoo_untaxed ), abs( $untaxed - $odoo_untaxed ) < 1.0 );

		return $rows;
	}

	/**
	 * Normalise an Odoo value (many2one [id, name], false, number) to a comparable string.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function scalar( $value ): string {
		if ( is_array( $value ) ) {
			return isset( $value[0] ) ? (string) $value[0] : '';
		}
		if ( false === $value || null === $value ) {
			return '';
		}
		return (string) $value;
	}

	/**
	 * One output row.
	 *
	 * @param string $field  Label.
	 * @param string $shadow Shadow value.
	 * @param string $odoo   Odoo value.
	 * @param bool   $is_match Whether they match.
	 * @return array
	 */
	private static function row( string $field, string $shadow, string $odoo, bool $is_match ): array {
		return array(
			'field'  => $field,
			'shadow' => $shadow,
			'odoo'   => $odoo,
			'match'  => $is_match ? 'yes' : 'NO',
		);
	}
}
