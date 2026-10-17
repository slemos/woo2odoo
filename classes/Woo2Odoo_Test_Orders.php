<?php
/**
 * Woo2Odoo_Test_Orders
 *
 * "Test / Debug" skip-list: orders (and refunds) whose customer email matches a
 * configurable list of patterns are NOT sent to Odoo. Meant for QA / smoke-test
 * orders placed against production, so they never create quotations, invoices or
 * credit notes in the accounting system.
 *
 * Design rules:
 *  - Opt-in. Nothing is skipped unless the master switch is on AND the list is non-empty.
 *  - Applies only to orders that were NEVER exported (no `_odoo_sale_order_id`).
 *    An order that already exists in Odoo keeps syncing/refunding normally, so
 *    adding an email later can never leave Odoo and WooCommerce inconsistent.
 *  - Loud, not silent: a skipped order gets sync status `skipped`, a stored
 *    reason and (optionally) an order note, so a missing order never looks like a bug.
 *  - Exact emails or `*` / `?` glob patterns (e.g. `qa+*@example.com`). A pattern
 *    that would match whole public mail providers (e.g. `*@gmail.com`) or that has
 *    a wildcard in the domain is rejected at save time.
 *
 * The matching/parsing methods are pure PHP (no WordPress calls) so they can be
 * unit-tested without a WordPress environment.
 *
 * @package Woo2Odoo
 */

namespace Woo2Odoo;

use WC_Order;

class Woo2Odoo_Test_Orders {

	/** Settings section / option suffix: option name is `Woo2Odoo-plugin-testing`. */
	public const SECTION = 'testing';

	/** Value stored in `_woo2odoo_sync_status` for skipped orders. */
	public const STATUS_SKIPPED = 'skipped';

	/** Order meta holding the pattern that caused the skip. */
	public const META_REASON = '_woo2odoo_skip_reason';

	/**
	 * Public mail providers: a wildcard local part on these would skip real customers.
	 */
	public const PUBLIC_MAIL_DOMAINS = array(
		'gmail.com',
		'googlemail.com',
		'hotmail.com',
		'hotmail.es',
		'hotmail.cl',
		'outlook.com',
		'outlook.es',
		'outlook.cl',
		'live.com',
		'live.cl',
		'msn.com',
		'yahoo.com',
		'yahoo.es',
		'yahoo.cl',
		'icloud.com',
		'me.com',
		'mac.com',
		'proton.me',
		'protonmail.com',
		'aol.com',
		'gmx.com',
		'mail.com',
	);

	// ── Pure helpers (no WordPress calls) ─────────────────────────────────────

	/**
	 * Split the raw textarea into clean lowercase patterns.
	 * Blank lines and `#` comments are ignored; duplicates removed; order kept.
	 *
	 * @param string $raw Raw textarea value.
	 * @return string[]
	 */
	public static function parse_patterns( string $raw ): array {
		$out = array();
		$lines = preg_split( '/\R/', $raw );
		if ( false === $lines ) {
			return $out;
		}
		foreach ( $lines as $line ) {
			$line = strtolower( trim( $line ) );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$out[ $line ] = true;
		}
		return array_keys( $out );
	}

	/**
	 * Is the pattern syntactically acceptable and not dangerously broad?
	 *
	 * @param string $pattern Lowercase pattern.
	 * @return string '' when valid, otherwise a short reason code
	 *                (`syntax`, `no_at`, `broad_domain`, `broad_public`).
	 */
	public static function pattern_problem( string $pattern ): string {
		if ( ! preg_match( '/^[a-z0-9._%+\-*?@]+$/', $pattern ) ) {
			return 'syntax';
		}
		if ( 1 !== substr_count( $pattern, '@' ) ) {
			return 'no_at';
		}
		list( $local, $domain ) = explode( '@', $pattern, 2 );
		if ( '' === $local || '' === $domain ) {
			return 'syntax';
		}
		if ( false !== strpbrk( $domain, '*?' ) ) {
			return 'broad_domain';
		}
		$local_is_wildcard_only = '' === trim( $local, '*?' );
		if ( $local_is_wildcard_only && in_array( $domain, self::PUBLIC_MAIL_DOMAINS, true ) ) {
			return 'broad_public';
		}
		return '';
	}

	/**
	 * Split raw input into accepted patterns and rejected lines (with reason).
	 *
	 * @param string $raw Raw textarea value.
	 * @return array{valid: string[], rejected: array<string,string>}
	 */
	public static function sanitize_list( string $raw ): array {
		$valid    = array();
		$rejected = array();
		foreach ( self::parse_patterns( $raw ) as $pattern ) {
			$problem = self::pattern_problem( $pattern );
			if ( '' === $problem ) {
				$valid[] = $pattern;
			} else {
				$rejected[ $pattern ] = $problem;
			}
		}
		return array(
			'valid'    => $valid,
			'rejected' => $rejected,
		);
	}

	/**
	 * First pattern matching the email (case-insensitive), or '' when none.
	 *
	 * @param string   $email    Customer email.
	 * @param string[] $patterns Already-parsed patterns.
	 * @return string
	 */
	public static function matching_pattern( string $email, array $patterns ): string {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return '';
		}
		foreach ( $patterns as $pattern ) {
			if ( '' !== $pattern && fnmatch( $pattern, $email ) ) {
				return $pattern;
			}
		}
		return '';
	}

	// ── Settings ──────────────────────────────────────────────────────────────

	/**
	 * Effective settings. Checkboxes are stored as the strings 'true' / 'false'.
	 * Defaults apply only before the tab has ever been saved.
	 *
	 * @return array{enabled: bool, patterns: string[], orders: bool, refunds: bool, note: bool}
	 */
	public static function settings(): array {
		$saved = get_option( 'Woo2Odoo-plugin-' . self::SECTION, null );
		$saved = is_array( $saved ) ? $saved : array();
		$flag  = static function ( string $key, bool $fallback ) use ( $saved ): bool {
			return array_key_exists( $key, $saved ) ? ( 'true' === $saved[ $key ] ) : $fallback;
		};
		return array(
			'enabled'  => $flag( 'skip_enabled', false ),
			'patterns' => self::parse_patterns( isset( $saved['skip_emails'] ) ? (string) $saved['skip_emails'] : '' ),
			'orders'   => $flag( 'skip_orders', true ),
			'refunds'  => $flag( 'skip_refunds', true ),
			'note'     => $flag( 'skip_note', true ),
		);
	}

	/** Is the feature active (master switch on and at least one pattern)? */
	public static function is_active(): bool {
		$s = self::settings();
		return $s['enabled'] && ! empty( $s['patterns'] );
	}

	// ── Decision + bookkeeping (need WooCommerce) ─────────────────────────────

	/**
	 * Decide whether an operation for this order must be skipped.
	 *
	 * @param \WC_Order|null $order   The (parent) order.
	 * @param string         $context 'orders' or 'refunds'.
	 * @return string The matching pattern when it must be skipped, '' otherwise.
	 */
	public static function should_skip( $order, string $context ): string {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}
		$s = self::settings();
		if ( ! $s['enabled'] || empty( $s['patterns'] ) || empty( $s[ $context ] ) ) {
			return '';
		}
		// Never skip an order that already lives in Odoo (keep both systems consistent).
		if ( (int) $order->get_meta( '_odoo_sale_order_id' ) > 0 ) {
			return '';
		}

		$emails = array( (string) $order->get_billing_email() );
		$uid    = (int) $order->get_customer_id();
		if ( $uid > 0 ) {
			$user = get_userdata( $uid );
			if ( $user ) {
				$emails[] = (string) $user->user_email;
			}
		}

		$match = '';
		foreach ( $emails as $email ) {
			$match = self::matching_pattern( $email, $s['patterns'] );
			if ( '' !== $match ) {
				break;
			}
		}

		/**
		 * Filters the skip decision. Return a non-empty string to skip, '' to sync.
		 *
		 * @param string    $match   Matching pattern ('' when none).
		 * @param \WC_Order $order   Order.
		 * @param string    $context 'orders' or 'refunds'.
		 */
		return (string) apply_filters( 'woo2odoo_skip_test_order', $match, $order, $context );
	}

	/**
	 * Record that an order was skipped: status, reason and (optionally) a note.
	 * Idempotent: repeated skips for the same pattern do not duplicate notes.
	 *
	 * @param \WC_Order $order   Order.
	 * @param string    $context 'orders' or 'refunds'.
	 * @param string    $pattern Pattern that matched.
	 */
	public static function mark_skipped( WC_Order $order, string $context, string $pattern ): void {
		$note = self::settings()['note'];

		if ( 'refunds' === $context ) {
			// Do not touch the order's sync status for a refund; just leave a trace.
			if ( $note ) {
				$order->add_order_note( sprintf( 'Woo2Odoo: reembolso omitido, no se envía a Odoo (coincide con la lista de pruebas: %s).', $pattern ) );
			}
			return;
		}

		$already = self::STATUS_SKIPPED === $order->get_meta( '_woo2odoo_sync_status' )
			&& $pattern === $order->get_meta( self::META_REASON );

		$order->update_meta_data( '_woo2odoo_sync_status', self::STATUS_SKIPPED );
		$order->update_meta_data( '_woo2odoo_sync_date', current_time( 'mysql' ) );
		$order->update_meta_data( self::META_REASON, $pattern );
		$order->delete_meta_data( '_woo2odoo_sync_error' );
		if ( $note && ! $already ) {
			$order->add_order_note( sprintf( 'Woo2Odoo: pedido omitido, no se envía a Odoo (coincide con la lista de pruebas: %s).', $pattern ) );
		}
		$order->save();
	}

	/** Does this order currently carry the `skipped` sync status? */
	public static function is_skipped( $order ): bool {
		return $order instanceof WC_Order
			&& self::STATUS_SKIPPED === $order->get_meta( '_woo2odoo_sync_status' );
	}
}
