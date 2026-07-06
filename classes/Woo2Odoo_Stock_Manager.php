<?php
/**
 * Woo2Odoo_Stock_Manager Class File
 *
 * This file contains the Stock_Manager class which synchronizes product stock
 * between WooCommerce and Odoo.
 *
 * @package Woo2Odoo
 */
namespace Woo2Odoo;

use Exception;

/**
 * Class Woo2Odoo_Stock_Manager
 */
class Woo2Odoo_Stock_Manager {

	private Woo2Odoo_Client $client;

	/**
	 * Constructor
	 *
	 * @param Woo2Odoo_Client $client Authenticated Odoo client.
	 */
	public function __construct( Woo2Odoo_Client $client ) {
		$this->client = $client;
	}

	/**
	 * Fetch product quantity from Odoo by SKU
	 *
	 * @param string $sku Product SKU.
	 * @return float|null Free quantity in Odoo, or null if not found.
	 */
	public function fetch_odoo_qty( string $sku ): ?float {
		if ( !$this->client->authenticate() ) {
			return null;
		}

		try {
			$product = $this->client->search_read(
				'product.product',
				array( array( 'default_code', '=', $sku ) ),
				array( 'free_qty', 'default_code', 'name' ),
				null,
				1,
				null,
				array( 'single' => true )
			);

			if ( !$product ) {
				$this->client->log_info(
					'Product not found in Odoo',
					array( 'sku' => $sku )
				);
				return null;
			}

			$qty = isset( $product->free_qty ) ? (float) $product->free_qty : 0;

			$this->client->log_info(
				'Fetched product quantity from Odoo',
				array(
					'sku'      => $sku,
					'name'     => $product->name ?? '',
					'free_qty' => $qty,
				)
			);

			return $qty;

		} catch ( Exception $e ) {
			$this->client->log_exception(
				'Error fetching product quantity from Odoo',
				$e
			);
			return null;
		}
	}

	/**
	 * Synchronize a single WooCommerce product with Odoo stock
	 *
	 * @param \WC_Product $product WooCommerce product object.
	 * @return bool True if sync successful, false otherwise.
	 */
	public function sync_product( \WC_Product $product ): bool {
		try {
			$sku = $product->get_sku();

			if ( empty( $sku ) ) {
				$this->client->log_warning(
					'Product has no SKU, skipping stock sync',
					array( 'product_id' => $product->get_id() )
				);
				return false;
			}

			$qty = $this->fetch_odoo_qty( $sku );

			if ( null === $qty ) {
				$this->client->log_warning(
					'Could not fetch Odoo quantity for product',
					array(
						'product_id' => $product->get_id(),
						'sku'        => $sku,
					)
				);
				return false;
			}

			wc_update_product_stock( $product, $qty, 'set' );

			$stock_status = $qty > 0 ? 'instock' : 'outofstock';
			$product->set_stock_status( $stock_status );

			$product->set_manage_stock( true );
			$product->save();

			$this->client->log_info(
				'Product stock synced successfully',
				array(
					'product_id'   => $product->get_id(),
					'sku'          => $sku,
					'qty'          => $qty,
					'stock_status' => $stock_status,
				)
			);

			return true;

		} catch ( Exception $e ) {
			$this->client->log_exception(
				'Error syncing product stock',
				$e
			);
			return false;
		}
	}

	/**
	 * Synchronize all WooCommerce products with Odoo stock
	 *
	 * @return array Statistics: ['updated' => N, 'not_found' => N, 'errors' => N]
	 */
	public function sync_all(): array {
		if ( !$this->client->authenticate() ) {
			return array(
				'updated'   => 0,
				'not_found' => 0,
				'errors'    => 0,
			);
		}

		// Note: search_read() no longer caches by default (caching is opt-in via the
		// 'cache' option). The free_qty query below does not opt in, so it always reads
		// fresh Odoo stock — no cache flush needed here.

		$stats = array(
			'updated'   => 0,
			'not_found' => 0,
			'errors'    => 0,
		);

		try {
			$products = wc_get_products(
				array(
					'status' => 'publish',
					'limit'  => -1,
					'return' => 'objects',
				)
			);

			foreach ( $products as $product ) {
				$sku = $product->get_sku();

				if ( empty( $sku ) ) {
					$stats['not_found']++;
					continue;
				}

				if ( $this->sync_product( $product ) ) {
					$stats['updated']++;
				} else {
					$stats['errors']++;
				}
			}

			$this->client->log_info(
				'Stock synchronization completed',
				array(
					'updated'   => $stats['updated'],
					'not_found' => $stats['not_found'],
					'errors'    => $stats['errors'],
				)
			);

		} catch ( Exception $e ) {
			$this->client->log_exception(
				'Error during bulk stock synchronization',
				$e
			);
		}

		return $stats;
	}
}
