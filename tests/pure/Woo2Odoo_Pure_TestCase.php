<?php
/**
 * Base class for the pure suite: resets the WP stubs and builds orders/clients.
 *
 * @package Woo2Odoo
 */

// phpcs:ignoreFile

use PHPUnit\Framework\TestCase;
use Woo2Odoo\Woo2Odoo_Client;

abstract class Woo2Odoo_Pure_TestCase extends TestCase {

	protected function setUp(): void {
		WP_Stub_State::reset();
	}

	/** Set "Export" tab settings (Woo2Odoo-plugin-export). */
	protected function set_export( array $values ): void {
		update_option( 'Woo2Odoo-plugin-export', array_merge( get_option( 'Woo2Odoo-plugin-export', array() ) ?: array(), $values ) );
	}

	/** A real Woo2Odoo_Client wired to an in-memory fake Odoo. */
	protected function client( FakeOdooRpc $rpc, ?bool $shadow = null ): Woo2Odoo_Client {
		return new Woo2Odoo_Client( $rpc, $shadow );
	}

	/**
	 * Register a representative B2C order: two IVA products (one with a coupon
	 * discount already applied to its line total), shipping, Transbank payment.
	 *
	 * @param array $over Overrides (meta merged, other keys replaced).
	 */
	protected function make_order( int $id, array $over = array() ): WC_Order {
		$data = array(
			'id'             => $id,
			'status'         => 'processing',
			'items'          => array(
				// 2 x 5.990 CLP incl. IVA => net 10.067,23; coupon 10% => 9.060,50 net.
				array( 'sku' => 'SKU-A', 'qty' => 2, 'total' => '9060.50', 'tax' => '1721.50' ),
				array( 'sku' => 'SKU-B', 'qty' => 1, 'total' => '2512.61', 'tax' => '477.39' ),
			),
			'shipping_total' => '3990',
			'shipping_tax'   => '0',
			'billing'        => array(
				'first_name' => 'Ana',
				'last_name'  => 'Pérez',
				'company'    => '',
				'address_1'  => 'Av. Siempre Viva 742',
				'address_2'  => 'Depto 3',
				'city'       => 'Santiago',
				'state'      => 'Región Metropolitana de Santiago',
				'postcode'   => '8320000',
				'country'    => 'CL',
				'email'      => 'ana@example.com',
				'phone'      => '+56911111111',
			),
			'shipping'       => array(),
			'payment_method' => 'transbank_webpay_plus_rest',
			'total'          => '17762',
			'meta'           => array(
				'transactionStatus'               => 'Autorizada',
				'amount'                          => '17762',
				'transactionDate'                 => '20-10-2026 10:05:00 -03:00',
				'_billing_invoice_type'           => '',
				'_wc_other/pinkmask/rut_personal' => '12.345.678-5',
			),
		);
		foreach ( $over as $k => $v ) {
			if ( 'meta' === $k ) {
				$data['meta'] = array_merge( $data['meta'], $v );
			} else {
				$data[ $k ] = $v;
			}
		}
		$order                          = new WC_Order( $data );
		WP_Stub_State::$orders[ $id ] = $order;
		return $order;
	}

	/** Factura variant of make_order(). */
	protected function factura_meta(): array {
		return array(
			'_billing_invoice_type'           => '1',
			'_wc_other/pinkmask/rut_empresa'  => '77.777.777-7',
			'_wc_other/pinkmask/giro'         => 'Venta de insumos',
			'_wc_other/pinkmask/rut_personal' => '12.345.678-5',
			'_billing_rut'                    => '77.777.777-7',
		);
	}

	/** Register a logged-in buyer with user meta. */
	protected function make_user( int $id, string $email, array $meta ): WP_User {
		$user                              = new WP_User( $id, $email, 'Ana P' );
		WP_Stub_State::$users[ $id ]       = $user;
		WP_Stub_State::$user_meta[ $id ]   = $meta;
		return $user;
	}
}
