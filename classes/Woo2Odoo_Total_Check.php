<?php
/**
 * Woo2Odoo_Total_Check
 *
 * Warns when the total of a WooCommerce order differs from the total of its linked
 * Odoo sale order by more than a tolerance (default 2 CLP). Asked for by the accounting
 * side: such orders were found late and by hand, and the receipt had to be edited in Odoo.
 *
 * Warning only. Nothing is corrected, blocked or re-synced:
 *  - an order note ("Total Woo X distinto del total Odoo Y"),
 *  - the order meta `_woo2odoo_total_diff` ("woo|odoo") while the totals differ, which the
 *    "Estado Odoo" tab filters on (`sync_filter=diff`),
 *  - the sync status stays `synced`, so the re-sync button and every status check keep working.
 *
 * When it runs: a minute after any save of an order that is already linked to a sale order
 * (this covers the sync itself and later edits in the admin), through a single wp-cron event
 * per order. It never calls Odoo inside the save request. If Odoo is down the check is
 * skipped quietly; the next save or `wp woo2odoo check-totals` catches it.
 *
 * The decision logic is pure PHP (no WordPress calls) so it can be unit-tested.
 *
 * @package Woo2Odoo
 */

namespace Woo2Odoo;

use Throwable;
use WC_Order;

use function wc_get_order;

class Woo2Odoo_Total_Check {

	/** Order meta: "woo|odoo" (2 decimals) while the two totals differ beyond the tolerance. */
	public const META_DIFF = '_woo2odoo_total_diff';

	/** wp-cron hook that runs the check for one order id. */
	public const CRON_HOOK = 'woo2odoo_check_total';

	/** Seconds between a save and the check (lets a sync that is still writing lines finish). */
	public const DELAY = 60;

	/** Default tolerance in currency units (CLP has no cents; this absorbs rounding). */
	public const DEFAULT_TOLERANCE = 2.0;

	/** WooCommerce statuses worth checking: the order is live in Odoo. */
	public const CHECK_STATUSES = array( 'on-hold', 'processing', 'completed', 'en-despacho' );

	/** Set while this class saves an order, so its own save does not schedule another check. */
	private static bool $running = false;

	// ---------------------------------------------------------------------------------
	// Pure logic
	// ---------------------------------------------------------------------------------

	/** True when the totals differ by more than the tolerance. */
	public static function differs( float $woo, float $odoo, float $tolerance ): bool {
		return abs( $woo - $odoo ) > $tolerance + 0.00001;
	}

	/** Stable key stored in the meta; two checks with the same totals give the same key. */
	public static function diff_key( float $woo, float $odoo ): string {
		return number_format( $woo, 2, '.', '' ) . '|' . number_format( $odoo, 2, '.', '' );
	}

	/**
	 * What to do given the previous meta value and the current totals.
	 *
	 * @return string `none` (match, nothing stored), `resolved` (match, a difference was stored),
	 *                `new` (difference, nothing stored), `changed` (difference with other
	 *                amounts than stored), `unchanged` (same difference already stored).
	 */
	public static function decide( string $previous_key, float $woo, float $odoo, float $tolerance ): string {
		if ( ! self::differs( $woo, $odoo, $tolerance ) ) {
			return '' === $previous_key ? 'none' : 'resolved';
		}
		if ( '' === $previous_key ) {
			return 'new';
		}
		return self::diff_key( $woo, $odoo ) === $previous_key ? 'unchanged' : 'changed';
	}

	/** Order-note text. Amounts are shown as whole pesos with Chilean separators. */
	public static function note_text( string $decision, float $woo, float $odoo, string $so_name ): string {
		$fmt  = fn( float $v ): string => '$' . number_format( $v, 0, ',', '.' );
		$diff = ( $woo - $odoo );
		$sign = $diff > 0 ? '+' : '-';
		if ( 'resolved' === $decision ) {
			return sprintf( 'Woo2Odoo: la diferencia de total se resolvió. Woo %s y Odoo %s (%s) coinciden.', $fmt( $woo ), $fmt( $odoo ), $so_name );
		}
		return sprintf(
			'Woo2Odoo: el total de Woo %s es distinto del total de Odoo %s (%s). Diferencia %s%s. Solo es un aviso: no se corrigió nada; revisa el pedido de venta en Odoo.',
			$fmt( $woo ),
			$fmt( $odoo ),
			$so_name,
			$sign,
			$fmt( abs( $diff ) )
		);
	}

	// ---------------------------------------------------------------------------------
	// WordPress glue
	// ---------------------------------------------------------------------------------

	/** Tolerance in currency units; filterable. */
	public static function tolerance(): float {
		return (float) apply_filters( 'woo2odoo_total_tolerance', self::DEFAULT_TOLERANCE );
	}

	/** Hook: `woocommerce_update_order`. Schedules at most one pending check per order. */
	public static function schedule( $order_id ): void {
		$order_id = (int) $order_id;
		if ( self::$running || $order_id <= 0 ) {
			return;
		}
		if ( wp_next_scheduled( self::CRON_HOOK, array( $order_id ) ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! self::is_checkable( $order ) ) {
			return;
		}
		wp_schedule_single_event( time() + self::DELAY, self::CRON_HOOK, array( $order_id ) );
	}

	/** Linked to a sale order, synced, and in a status that is live in Odoo. */
	public static function is_checkable( $order ): bool {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		if ( ! (int) $order->get_meta( '_odoo_sale_order_id' ) ) {
			return false;
		}
		if ( 'synced' !== $order->get_meta( '_woo2odoo_sync_status' ) ) {
			return false;
		}
		return in_array( $order->get_status(), self::CHECK_STATUSES, true );
	}

	/**
	 * Cron callback: reads the sale order from Odoo and applies the result.
	 *
	 * @param int         $order_id WooCommerce order id.
	 * @param object|null $client   Odoo client (anything with `execute()`); tests inject a fake.
	 */
	public static function run( $order_id, $client = null ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! self::is_checkable( $order ) ) {
			return;
		}
		try {
			$client = $client ?? new Woo2Odoo_Client();
			$read   = $client->execute( 'sale.order', 'read', array( array( (int) $order->get_meta( '_odoo_sale_order_id' ) ), array( 'name', 'state', 'amount_total' ) ) );
		} catch ( Throwable $e ) {
			error_log( 'woo2odoo total check skipped for order ' . (int) $order_id . ': ' . $e->getMessage() );
			return;
		}
		if ( empty( $read[0] ) || 'cancel' === ( $read[0]->state ?? '' ) ) {
			return;
		}
		self::apply( $order, (float) $order->get_total(), (float) $read[0]->amount_total, (string) ( $read[0]->name ?? '' ), true );
	}

	/**
	 * Compare, and (when `$write`) store the meta and add the note.
	 *
	 * @return string The decision (see decide()).
	 */
	public static function apply( WC_Order $order, float $woo, float $odoo, string $so_name, bool $write ): string {
		$previous = (string) $order->get_meta( self::META_DIFF );
		$decision = self::decide( $previous, $woo, $odoo, self::tolerance() );
		if ( ! $write || in_array( $decision, array( 'none', 'unchanged' ), true ) ) {
			return $decision;
		}
		self::$running = true;
		try {
			if ( 'resolved' === $decision ) {
				$order->delete_meta_data( self::META_DIFF );
			} else {
				$order->update_meta_data( self::META_DIFF, self::diff_key( $woo, $odoo ) );
			}
			$order->add_order_note( self::note_text( $decision, $woo, $odoo, $so_name ) );
			$order->save();
		} finally {
			self::$running = false;
		}
		return $decision;
	}

	/** Register the hooks (called once from the plugin constructor). */
	public static function register(): void {
		add_action( 'woocommerce_update_order', array( __CLASS__, 'schedule' ), 20, 1 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ), 10, 1 );
	}
}
