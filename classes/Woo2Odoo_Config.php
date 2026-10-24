<?php
/**
 * Woo2Odoo_Config Class File
 *
 * Single place that reads the integration settings that change WHAT is sent to
 * Odoo (master switch, origin prefix, fiscal data source, partner matching,
 * fee/shipping products, salesperson/team, shadow mode).
 *
 * Every getter returns the value that reproduces the historical (v1.6.1)
 * behaviour when the option is unset, so an existing installation that never
 * saved any of these settings keeps sending exactly the same payloads.
 *
 * @package Woo2Odoo
 */
namespace Woo2Odoo;

/**
 * Class Woo2Odoo_Config
 */
class Woo2Odoo_Config {

	/** Option that holds the "Export" tab settings. */
	public const OPTION_EXPORT = 'Woo2Odoo-plugin-export';

	/** Shipping product used before it became configurable. */
	public const LEGACY_SHIPPING_PRODUCT_ID = 1229;

	/** Fiscal data source: user meta for logged-in buyers, order meta for guests (v1.6.1). */
	public const FISCAL_SOURCE_LEGACY = 'legacy';

	/** Fiscal data source: order meta first, user meta as fallback. */
	public const FISCAL_SOURCE_ORDER_FIRST = 'order_first';

	/**
	 * Raw value of one "Export" tab setting.
	 *
	 * @param string $key           Setting key.
	 * @param mixed  $default_value Value when unset.
	 * @return mixed
	 */
	public static function get( string $key, $default_value = '' ) {
		$values = get_option( self::OPTION_EXPORT, array() );
		if ( is_array( $values ) && isset( $values[ $key ] ) ) {
			return $values[ $key ];
		}
		return $default_value;
	}

	/**
	 * Master switch: may the plugin sync anything with Odoo?
	 *
	 * Disabled ONLY when explicitly turned off, so an installation that never
	 * stored `export_order` (or stored the old checkbox value 'true') keeps
	 * syncing exactly as before:
	 *  - constant WOO2ODOO_SYNC_DISABLED === true (wp-config.php, works before
	 *    the plugin is ever configured, e.g. on a freshly activated site), or
	 *  - setting export_order === 'false'.
	 * The result passes through the `woo2odoo_sync_enabled` filter.
	 *
	 * @return bool
	 */
	public static function sync_enabled(): bool {
		$enabled = true;
		if ( defined( 'WOO2ODOO_SYNC_DISABLED' ) && true === constant( 'WOO2ODOO_SYNC_DISABLED' ) ) {
			$enabled = false;
		} elseif ( 'false' === self::get( 'export_order', '' ) ) {
			$enabled = false;
		}
		return (bool) apply_filters( 'woo2odoo_sync_enabled', $enabled );
	}

	/**
	 * Prefix prepended to the WC order id in sale.order `origin` /
	 * `client_order_ref` and in payment references. Empty by default.
	 *
	 * @return string
	 */
	public static function origin_prefix(): string {
		$prefix = trim( (string) self::get( 'origin_prefix', '' ) );
		return (string) apply_filters( 'woo2odoo_origin_prefix', $prefix );
	}

	/**
	 * Reference that identifies a WC order in Odoo: prefix + order id.
	 *
	 * @param int|string $order_id WooCommerce order id.
	 * @return string
	 */
	public static function order_ref( $order_id ): string {
		return self::origin_prefix() . $order_id;
	}

	/**
	 * Where fiscal data (invoice type, RUTs, giro, razón social) is read from.
	 *
	 * @return string One of the FISCAL_SOURCE_* constants.
	 */
	public static function fiscal_source(): string {
		$value = (string) self::get( 'fiscal_source', self::FISCAL_SOURCE_LEGACY );
		return self::FISCAL_SOURCE_ORDER_FIRST === $value ? self::FISCAL_SOURCE_ORDER_FIRST : self::FISCAL_SOURCE_LEGACY;
	}

	/**
	 * Whether partners are matched by RUT before the email search. Off by default.
	 *
	 * @return bool
	 */
	public static function partner_match_by_rut(): bool {
		return 'true' === self::get( 'partner_match_rut', 'false' );
	}

	/**
	 * Odoo service product for WC fee lines: product id (numeric) or SKU.
	 * Empty (default) = fee lines are not sent, as before.
	 *
	 * @return string
	 */
	public static function fee_product(): string {
		return trim( (string) self::get( 'fee_product', '' ) );
	}

	/**
	 * Odoo product id used for the shipping line.
	 *
	 * @return int
	 */
	public static function shipping_product_id(): int {
		$value = (int) self::get( 'shipping_product_id', 0 );
		return $value > 0 ? $value : self::LEGACY_SHIPPING_PRODUCT_ID;
	}

	/**
	 * Odoo res.users id set as salesperson (`user_id`), 0 = leave Odoo's default.
	 *
	 * @return int
	 */
	public static function salesperson_user_id(): int {
		return max( 0, (int) apply_filters( 'woo2odoo_salesperson_user_id', (int) self::get( 'salesperson_user_id', 0 ) ) );
	}

	/**
	 * Odoo crm.team id set as sales team (`team_id`), 0 = leave Odoo's default.
	 *
	 * @return int
	 */
	public static function sales_team_id(): int {
		return max( 0, (int) apply_filters( 'woo2odoo_sales_team_id', (int) self::get( 'sales_team_id', 0 ) ) );
	}

	/**
	 * Shadow mode: Odoo writes are logged and faked, reads still hit Odoo.
	 * On with the constant WOO2ODOO_SHADOW_MODE === true or setting shadow_mode = 'true'.
	 *
	 * @return bool
	 */
	public static function shadow_mode(): bool {
		if ( defined( 'WOO2ODOO_SHADOW_MODE' ) && true === constant( 'WOO2ODOO_SHADOW_MODE' ) ) {
			return true;
		}
		return 'true' === self::get( 'shadow_mode', 'false' );
	}
}
