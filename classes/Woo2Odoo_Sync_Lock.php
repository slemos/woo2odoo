<?php
/**
 * Woo2Odoo_Sync_Lock Class File
 *
 * Short-lived named lock (one per WC order) so two concurrent requests, e.g. a
 * payment gateway webhook and the customer's return page, cannot both sync the
 * same order and create two sale orders in Odoo.
 *
 * Same pattern WooCommerce / Action Scheduler use for their option locks: an
 * `INSERT IGNORE` into the options table, which is atomic thanks to the unique
 * `option_name` key. It deliberately bypasses the options API and the object
 * cache, so it behaves the same with or without a persistent (Redis) object
 * cache, where transients would live only in Redis and wp_cache_add() atomicity
 * would depend on the drop-in. A stale lock (holder crashed) expires after the TTL.
 *
 * @package Woo2Odoo
 */
namespace Woo2Odoo;

/**
 * Class Woo2Odoo_Sync_Lock
 */
class Woo2Odoo_Sync_Lock {

	/** Option name prefix of the lock rows. */
	public const PREFIX = '_woo2odoo_lock_';

	/** Default lock lifetime in seconds (an order sync takes a few seconds; a big one < 1 min). */
	public const DEFAULT_TTL = 180;

	/** @var array<string,string> Values of the locks held by this instance, by name. */
	private $held = array();

	/**
	 * Try to take the lock. Never blocks.
	 *
	 * @param string $name Lock name (e.g. "order_123").
	 * @param int    $ttl  Seconds after which the lock is considered stale.
	 * @return bool True when acquired.
	 */
	public function acquire( string $name, int $ttl = self::DEFAULT_TTL ): bool {
		global $wpdb;
		if ( ! $wpdb ) {
			return true; // No database (should not happen inside WP): fail open, as before.
		}
		$option = self::PREFIX . $name;
		$value  = ( time() + $ttl ) . '|' . wp_generate_password( 12, false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				$option,
				$value
			)
		);
		if ( 1 === (int) $inserted ) {
			$this->held[ $name ] = $value;
			return true;
		}

		// Held by someone else: take it over only if it is stale, atomically
		// (the UPDATE matches only if nobody changed it in between).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$current = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option )
		);
		$expires = (int) strtok( $current, '|' );
		if ( '' !== $current && $expires < time() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					$value,
					$option,
					$current
				)
			);
			if ( 1 === (int) $updated ) {
				$this->held[ $name ] = $value;
				return true;
			}
		}
		return false;
	}

	/**
	 * Release a lock taken by this instance. A lock that expired and was taken
	 * over by another request is left alone (its value no longer matches).
	 *
	 * @param string $name Lock name.
	 */
	public function release( string $name ): void {
		global $wpdb;
		if ( ! $wpdb || ! isset( $this->held[ $name ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::PREFIX . $name,
				$this->held[ $name ]
			)
		);
		unset( $this->held[ $name ] );
	}
}
