<?php
/**
 * Woo2Odoo_Order_Manager Class File
 *
 * This file contains the OrderManager class which add logic to manage the order integration between
 * WooCommerce and Odoo.
 *
 * @package Woo2Odoo
 */
namespace Woo2Odoo;

use Exception;

/**
 * Class Woo2odoo_Order_Manager
 */
class Woo2Odoo_Order_Manager {

	private Woo2Odoo_Client $client;

	/**
	 * @var array an array that stores the default mapping for fields between Odoo and WooCommerce
	 */
	public $default_mapping = array(
		'processing' => 'in_payment',
		'completed'  => 'paid',
		'pending'    => 'quote_order',
		'failed'     => 'cancelled',
		'on-hold'    => 'quote_only',
		'cancelled'  => 'cancelled',
		'refunded'   => 'refunded',
	);

	public $states = array(
		'quote_only'  => array(
			'order_state'    => 'sent',
			'invoice_state'  => '',
			'payment_state'  => '',
			'invoice_status' => 'to invoice',
		),
		'quote_order' => array(
			'order_state'    => 'sale',
			'invoice_state'  => '',
			'payment_state'  => '',
			'invoice_status' => 'to invoice',
		),
		'in_payment'  => array(
			'order_state'    => 'sale',
			'invoice_state'  => 'posted',
			'payment_state'  => 'in_payment',
			'invoice_status' => 'invoiced',
		),
		'paid'        => array(
			'order_state'    => 'sale',
			'invoice_state'  => 'posted',
			'payment_state'  => 'paid',
			'invoice_status' => 'invoiced',
		),
		'cancelled'   => array(
			'order_state'    => 'cancel',
			'invoice_state'  => 'posted',
			'payment_state'  => 'cancelled',
			'invoice_status' => 'no',
		),
		'refunded'    => array(
			'order_state'    => 'sale',
			'invoice_state'  => 'posted',
			'payment_state'  => 'reversed',
			'invoice_status' => 'invoiced',
			'rev_invoice'    => array(
				'state'         => 'posted',
				'payment_state' => 'paid',
			),
		),
	);

	public function __construct() {
		$this->client = new Woo2Odoo_Client();
	}

	/**
	 * Persist sync status, date and optional error message on the WC order.
	 * Accepts an already-loaded order to avoid a redundant DB fetch inside loops.
	 */
	private function set_sync_status( int $order_id, string $status, string $error = '', ?\WC_Abstract_Order $order = null ): void {
		if ( ! $order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}
		$order->update_meta_data( '_woo2odoo_sync_status', $status );
		$order->update_meta_data( '_woo2odoo_sync_date', current_time( 'mysql' ) );
		if ( $error !== '' ) {
			$order->update_meta_data( '_woo2odoo_sync_error', mb_substr( $error, 0, 255 ) );
		} elseif ( 'synced' === $status ) {
			$order->delete_meta_data( '_woo2odoo_sync_error' );
		}
		$order->save();
	}

	public function order_sync( $order_id ) {
		if ( !$this->client->authenticate() ) {
			$this->set_sync_status( (int) $order_id, 'failed', 'Odoo auth failed' );
			return false;
		}

		$this->set_sync_status( (int) $order_id, 'pending' );

		try {
			$order = wc_get_order( $order_id );
			if ( !$order ) {
				$this->client->log_warning( 'Odoo order_sync failed: Order not found', array( 'order_id' => $order_id ) );
				return false;
			}

			$odoo_order_state = $this->odoo_states( $this->default_mapping[ $order->get_status() ], 'order_state' );
			// Get the customer data
			$customer_data = $this->get_customer_data( $order );
			if ( !$customer_data ) {
				$this->client->log_error( 'Error getting customer data', array( 'order_id' => $order_id ) );
				$this->set_sync_status( (int) $order_id, 'failed', 'Customer data unavailable', $order );
				return false;
			}
			// Search if the order exists in Odoo
			$odoo_order = $this->client->search_read(
				'sale.order',
				array(
					array(
						'origin',
						'=',
						"$order_id",
					),
				),
				array( 'id', 'amount_total', 'state', 'invoice_status' ),
				null,
				1,
				null,
				array( 'single' => true )
			);
			$is_new_order = false;
			if ( !$odoo_order ) {
				// Create the order in Odoo
				$order_data = array(
					'partner_id'          => (int) $customer_data['id'],
					'partner_invoice_id'  => (int) $customer_data['invoice_id'],
					'partner_shipping_id' => (int) $customer_data['shipping_id'],
					'state'               => $odoo_order_state,
					'note'                => __( 'Woo Order Id : ', 'woo2odoo-plugin' ) . $order_id,
					'payment_term_id'     => 1,
					'origin'              => $order_id,
					'date_order'          => date_format( $order->get_date_created(), 'Y-m-d H:i:s' ),
				);

				$odoo_order_id = $this->client->create_record( 'sale.order', $order_data );

				// Check if creation went ok
				if ( !$odoo_order_id ) {
					$this->client->log_error( 'Failed to create order in Odoo', $order_data );
					return false;
				}
				$is_new_order = true;

				$order->update_meta_data( '_odoo_sale_order_id', $odoo_order_id );
				$order->save();

				$so_read = $this->client->execute( 'sale.order', 'read', array( array( $odoo_order_id ), array( 'name' ) ) );
				$so_name = ! empty( $so_read ) ? $so_read[0]->name : "ID {$odoo_order_id}";
				$order->add_order_note( "Woo2Odoo: Pedido de venta {$so_name} creado en Odoo." );

			} else {
				if ( $odoo_order->state !== $odoo_order_state ) {
					$this->client->log_info(
						'Order status mismatch',
						array(
							'order_id'     => $order_id,
							'order_status' => $order->get_status(),
							'odoo_status'  => $odoo_order['state'],
						)
					);
				}
				if ( $odoo_order->invoice_status === 'invoiced' ) {
					$this->client->log_info(
						'Order already invoiced, cannot continue',
						array(
							'order_id' => $order_id,
						)
					);
					return true;
				}
				$odoo_order_id = $odoo_order->id;
			}
			// Add order line items
			$this->add_order_line_items( $order, $odoo_order_id, (int) $customer_data['id'] );

			// Add shipping line if applicable
			$this->add_shipping_line( $order, $odoo_order_id, (int) $customer_data['id'] );

			if ( ! empty( $order->get_customer_note() ) ) {
				$order_line    = array(
					'order_partner_id' => (int) $customer_data['id'],
					'order_id'         => $odoo_order_id,
					'product_uom_qty'  => false,
					'product_id'       => false,
					'display_type'     => 'line_note',
					'name'             => $order->get_customer_note(),
				);
				$order_line_id = $this->client->create_record( 'sale.order.line', $order_line );

				if ( !$order_line_id ) {
					$this->client->log_error( 'Error creating order line item in Odoo', array( 'Order Line Data' => $order_line ) );
					return false;
				}
			}

			// Create invoice in Odoo (only for new orders)
			if ( $is_new_order ) {
				$invoice_id = $this->create_invoice_for_so( $odoo_order_id, $order_id, $customer_data );

				if ( $invoice_id ) {
					// Store invoice ID on the WC order object so it survives WC's subsequent
					// save() call (which syncs wp_postmeta from the order object via HPOS backfill).
					// update_post_meta() alone gets wiped by OrdersTableDataStore::backfill_post_record().
					$order->update_meta_data( '_woo2odoo_invoice_id', $invoice_id );
					$order->save();
					$order->add_order_note( "Woo2Odoo: Boleta en borrador creada en Odoo (ID {$invoice_id})." );

					$payment_info = $this->get_payment_info_from_wc_order( $order );
					if ( $payment_info ) {
						$payment_id = $this->create_outstanding_payment( $invoice_id, (int) $customer_data['id'], $payment_info, $order );
						if ( $payment_id ) {
							$order->update_meta_data( '_woo2odoo_payment_id', $payment_id );
							$order->save();
						}
					}
				}
			}

		} catch (Exception $e) {
			$this->client->log_exception( 'Odoo order_sync failed', $e );
			$this->set_sync_status( (int) $order_id, 'failed', $e->getMessage() );
			return false;
		}

		$this->set_sync_status( (int) $order_id, 'synced', '', $order );
		return true;
	}

	/**
	 * Create a Odoo Invoice.
	 *
	 * @param \WC_Order $order order from WooCommerce.
	 * @param array     $customer_data from get_customer_data().
	 * 
	 * @return bool|int $invoice_id return invoice id if created successfully else false.
	 * 
	 */
	/**
	 * Create an invoice (account.move) in Odoo for a given sale.order.
	 * Called after order_sync creates the SO. Invoice is left in draft state.
	 *
	 * @param int   $odoo_order_id Odoo sale.order ID.
	 * @param int   $wc_order_id   WooCommerce order ID (for logging/origin).
	 * @param array $customer_data From get_customer_data(): keys id, invoice_id, shipping_id.
	 * @return int|false Invoice ID or false on failure.
	 */
	private function create_invoice_for_so( $odoo_order_id, $wc_order_id, $customer_data ) {
		// Get SO details to use as invoice origin
		$so = $this->client->search_read(
			'sale.order',
			array( array( 'id', '=', $odoo_order_id ) ),
			array( 'id', 'name', 'date_order' ),
			null, 1, null,
			array( 'single' => true )
		);
		$so_name = $so ? $so->name : "WC#$wc_order_id";

		// Get journal from plugin export settings (falls back to journal 9)
		$export_settings = get_option( 'Woo2Odoo-plugin-export', array() );
		$journal_id      = isset( $export_settings['invoiceJournal'] ) ? (int) $export_settings['invoiceJournal'] : 9;

		// Determine document type: (33) Factura when _billing_invoice_type=1, else (39) Boleta
		$requires_factura        = get_post_meta( $wc_order_id, '_billing_invoice_type', true ) === '1';
		$latam_document_type_id  = $requires_factura ? 1 : 5;

		// Payment reference: WC order number (set before action_post so Odoo doesn't replace it with invoice name)
		$payment_reference = 'WC#' . $wc_order_id;

		// Terms & conditions: use configured URL if set, otherwise clear Odoo's default
		$terms_url = isset( $export_settings['invoice_terms_url'] ) ? trim( $export_settings['invoice_terms_url'] ) : '';
		$narration = $terms_url
			? '<p>Términos y condiciones: <a href="' . esc_url( $terms_url ) . '">' . esc_html( $terms_url ) . '</a></p>'
			: '';

		// Create account.move header (draft invoice).
		// partner_id must be the commercial master (not the invoice-address child).
		// Odoo's l10n_cl uses commercial_partner_id for the legal document — using a child
		// causes the boleta/factura to appear under the wrong account.
		$invoice_id = $this->client->create_record( 'account.move', array(
			'move_type'                    => 'out_invoice',
			'partner_id'                   => (int) $customer_data['id'],
			'partner_shipping_id'          => (int) $customer_data['shipping_id'],
			'invoice_origin'               => $so_name,
			'journal_id'                   => $journal_id,
			'invoice_date'                 => date( 'Y-m-d' ),
			'currency_id'                  => 44, // CLP
			'l10n_latam_document_type_id'  => $latam_document_type_id,
			'payment_reference'            => $payment_reference,
			'narration'                    => $narration,
		) );

		if ( !$invoice_id ) {
			$this->client->log_error( 'Failed to create invoice for SO', array( 'so_id' => $odoo_order_id, 'wc_order_id' => $wc_order_id ) );
			return false;
		}

		// Get SO lines to create invoice lines
		$so_lines = $this->client->search_read(
			'sale.order.line',
			array( array( 'order_id', '=', $odoo_order_id ) ),
			array( 'id', 'product_id', 'product_uom_qty', 'price_unit', 'display_type', 'name' ),
			null, null, null
		);

		if ( $so_lines ) {
			foreach ( $so_lines as $line ) {
				// Skip section/note display lines
				if ( !empty( $line->display_type ) ) {
					continue;
				}
				if ( empty( $line->product_id ) ) {
					continue;
				}
				$this->client->create_record( 'account.move.line', array(
					'move_id'       => $invoice_id,
					'product_id'    => (int) $line->product_id[0],
					'quantity'      => $line->product_uom_qty,
					'price_unit'    => $line->price_unit,
					'sale_line_ids' => array( array( 6, 0, array( (int) $line->id ) ) ),
				) );
			}
		}

		$this->client->log_info( 'Invoice created in Odoo (draft)', array(
			'invoice_id'   => $invoice_id,
			'so_id'        => $odoo_order_id,
			'wc_order_id'  => $wc_order_id,
		) );
		return $invoice_id;
	}

	public function create_invoice( $order, $customer_data ) {

		if ( ! $this->client->authenticate() ) {
			return false;
		}

		$woo_state = $helper->get_state( $order->get_status() );
		$statuses  = $helper->odoo_states( $woo_state );
		
		$order_total = $order->get_total();
		
		$billing_type = $order->get_meta( '_billing_invoice_type' );
		$invoice_data         = $this->create_invoice_data( $customer_data, (int) $order_odoo_id, $order_total );
		if ( 0 < $order_total ) {
			if ( '' === $billing_type ) {
				$invoice_data['l10n_latam_document_type_id'] = 5;
			} else {
				$invoice_data['l10n_latam_document_type_id'] = 1;
			}
			$invoice_data['l10n_latam_document_number'] = $this->get_last_l10n_latam_document_number($invoice_data['l10n_latam_document_type_id']);
		}
		else {
			$invoice_data['name'] = 'WEB/' . $order_id;
		}
		// Create the record in the journal
		$invoice_id = $odoo_api->create_record( 'account.move', $invoice_data );

		if ( isset( $invoice_id['faultString'] ) ) {
			$error_msg = 'Error for Creating  Invoice Id  =>' . $order_id . 'Msg : ' . print_r( $invoice_id, true );
			$odoo_api->add_log( $error_msg );

			return false;
		}

		if ( ! isset( $this->odoo_settings['odooTax'] ) ) {
			$error_msg = 'Invalid Tax Setting For Order Id ' . $order_id;
			$odoo_api->add_log( $error_msg );

			return false;
		}
		// get tax id from the admin setting.
		$tax_id = (int) $this->odoo_settings['odooTax'];

		$tax_data = $odoo_api->fetch_record_by_id( 'account.tax', array( $tax_id ) );

		if ( isset( $tax_data['faultCode'] ) ) {
			$error_msg = 'Error For Fetching Tax data Msg : ' . print_r( $tax_data['msg'], true );
			$odoo_api->add_log( $error_msg );

			return false;
		}
		$invoice_lines = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();

			$order_line_id = wc_get_order_item_meta( $item_id, '_order_line_id' );
			$odoo_api->add_log( 'order_line_id : ' . print_r( $order_line_id, true ) );
			// nico.
			$conditions = array( array( $this->odoo_sku_mapping, '=', $product->get_sku() ) );

			$product_id = $odoo_api->search_record( 'product.product', $conditions );
			if ( ! $product_id ) {
				$odoo_api->add_log( 'Product not found for Invoice!!' );

				return false;
			}
			if ( 0 < $order_total ) {
				if ( 1 === $tax_data['price_include'] ) {
					$total_price = $item->get_total() + $item->get_total_tax();
				} else {
					$total_price = $item->get_total();
				}
			}
			else {
				$total_price = 0;
			}
			$unit_price = round( number_format( (float) ( $total_price / $item->get_quantity() ), 0, '.', '' ) );

			if ( 'yes' === $this->odoo_settings['odoo_export_invoice'] ) {
				$invoice_line_data = array(
					'partner_id'    => (int) $customer_data['id'],
					'move_id'       => $invoice_id,
					'price_unit'    => $unit_price,
					'quantity'      => $item->get_quantity(),
					'product_id'    => $product_id,
					'sale_line_ids' => array( array( 6, 0, array( (int) $order_line_id ) ) ),
				);
				if ( 'no' === $this->odoo_settings['odoo_fiscal_position'] ) {
					$invoice_line_data['tax_ids'] = array( array( 6, 0, array( (int) $tax_id ) ) );

					if ( $item->get_total_tax() > 0 ) {
						$invoice_line_data['tax_ids'] = array( array( 6, 0, array( (int) $tax_id ) ) );
					} else {
						$invoice_line_data['tax_ids'] = array( array( 6, 0, array() ) );
					}
				}
				$invoice_lines[] = $odoo_api->create_record( 'account.move.line', $invoice_line_data );
			}
		}

		if ( $order->get_shipping_total() > 0 ) {
			$shipping_tax_id = (int) $this->odoo_settings['shippingOdooTax'];

			$order_line_id = get_post_meta( $order_id, '_order_line_id', true );

			$odoo_api->add_log( 'Shipping line : ' . print_r( $order_line_id, true ) );

			if ( 'yes' === $this->odoo_settings['odoo_export_invoice'] ) {
				$price             = $order->get_shipping_total() ?: 0;
				$invoice_line_data = array(
					'partner_id'    => (int) $customer_data['id'],
					'move_id'       => $invoice_id,
					'price_unit'    => round( $price, 0 ),
					'quantity'      => 1,
					'product_id'    => (int) $this->get_delivery_product_id(),
					'tax_ids'       => array( array( 6, 0, array( (int) $shipping_tax_id ) ) ),
					'sale_line_ids' => array( array( 6, 0, array( (int) $order_line_id ) ) ),
				);
				$invoice_lines[]   = $odoo_api->create_record( 'account.move.line', $invoice_line_data );
			}
		}

		if ( ! empty( $order->get_customer_note() ) ) {
			if ( 'yes' === $this->odoo_settings['odoo_export_invoice'] ) {
				$order_line_id = get_post_meta( $order_id, '_order_note_id', true );
				$odoo_api->add_log( 'order note line : ' . print_r( $order_line_id, true ) );
				$invoice_line_data = array(
					'partner_id'    => (int) $customer_data['id'],
					'move_id'       => $invoice_id,
					'price_unit'    => false,
					'quantity'      => false,
					'product_id'    => false,
					'sale_line_ids' => array( array( 6, 0, array( (int) $order_line_id ) ) ),
					'display_type'  => 'line_note',
					'name'          => $order->get_customer_note(),
				);
				$invoice_lines[]   = $odoo_api->create_record( 'account.move.line', $invoice_line_data );
			}
		}

		if ( count( $invoice_lines ) > 0 && ( 'yes' === $this->odoo_settings['odoo_export_invoice'] ) ) {
			$odoo_order = $odoo_api->update_record( 'sale.order', (int) $order_odoo_id, array( 'state' => $statuses['order_state'] ) );
			$odoo_api->add_log( 'Order update: ' . print_r( $odoo_order, true ) . ' - To stat: ' . print_r( $statuses['order_state'], true ) );

			if ( $helper->is_inv_mark_paid() ) {
				$invoice = $odoo_api->update_record( 'account.move', (int) $invoice_id, array( 'state' => $statuses['invoice_state'] ) );
				if ( 13 === $odoo_ver ) {
					$invoice = $odoo_api->update_record( 'account.move', (int) $invoice_id, array( 'invoice_payment_state' => $statuses['payment_state'] ) );
				} else {
					$invoice = $odoo_api->update_record( 'account.move', (int) $invoice_id, array( 'payment_state' => $statuses['payment_state'] ) );
				}
			} else {
				$invoice = $odoo_api->update_record( 'account.move', (int) $invoice_id, array( 'state' => 'draft' ) );
				if ( 13 === $odoo_ver ) {
					$invoice = $odoo_api->update_record( 'account.move', (int) $invoice_id, array( 'invoice_payment_state' => 'not_paid' ) );
				} else {
					$invoice = $odoo_api->update_record( 'account.move', (int) $invoice_id, array( 'payment_state' => 'not_paid' ) );
				}
			}

			if ( ! $invoice ) {
				$error_msg = 'Error for Creating  Invoice  for Order Id  =>' . $order_id . 'Msg : ' . print_r( $invoice, true );
				$odoo_api->add_log( $error_msg );

				return false;
			}

			$invoice_url = $this->create_pdf_download_link( $invoice_id );
			if ( isset( $invoice_data['invoice_origin'] ) && ! empty( $invoice_data['invoice_origin'] ) ) {
				$order_origin = $invoice_data['invoice_origin'];
				update_post_meta( $order_id, '_odoo_order_origin', $order_origin );
			}
			update_post_meta( $order_id, '_odoo_invoice_id', $invoice_id );
			update_post_meta( $order_id, '_odoo_invoice_url', $invoice_url );

			return $invoice_id;
		}
	}

	/**
	 * Create_invoice description.
	 *
	 * @param  [array] $odoo_customer [customer ids array].
	 * @param  [int]   $odoo_order_id    [order id].
	 *
	 * @return [int]                   [invoice Id]
	 */
	public function create_invoice_data( $odoo_customer, $odoo_order_id, $order_total ) {
		$odoo_api = $this->get_odoo_api();
		$order    = $odoo_api->fetch_record_by_id( 'sale.order', array( $odoo_order_id ), array( 'id', 'name', 'date_order' ) );
		$odoo_api->add_log( 'Preparing invoice data for order: ' . print_r( $order, true ) );
		$data              = array(
			'partner_id'               => (int) $odoo_customer['id'],
			'invoice_origin'           => $order['name'],
			'state'                    => 'draft',
			'type_name'                => 'Invoice',
			'invoice_payment_term_id'  => 1,
			'partner_shipping_id'      => (int) $odoo_customer['shipping_id'],
			'invoice_date'             => gmdate( 'Y-m-d', strtotime( $order['date_order'] ) ),
			'invoice_date_due'         => gmdate( 'Y-m-d', strtotime( $order['date_order'] ) ),
			'invoice_cash_rounding_id' => 1,
			// Rounding CLP.
			'currency_id'              => 44,
		);

		if ( 0 < $order_total) {
			$data['journal_id'] = $this->odoo_settings['invoiceJournal'];
		}
		else {
			$data['journal_id'] = 19;
		}

		$data['move_type']  = 'out_invoice';
		$odoo_api->add_log( 'Invoice data: ' . print_r( $data, true ) );
		return $data;
	}

	/**
	 * Add shipping line to the order in Odoo
	 *
	 * @param WC_Order $order
	 * @param int $odoo_order
	 * @param int $customer_id
	 *
	 * @return bool true if the shipping line was added successfully
	 */
	private function add_shipping_line( $order, $odoo_order, $customer_id ) {

		$retval = true;

		if ( $order->get_shipping_total() > 0 ) {
			// Search and validate if the shipping line already exists
			$shipping_line = $this->client->search_read(
				'sale.order.line',
				array(
					array( 'order_id', '=', $odoo_order ),
					array( 'product_id', '=', 1229 ), // Hardcoded shipping product ID
				),
				array( 'id' ),
				null,
				1,
				null,
				array( 'single' => true )
			);

			$shipping_data = array(
				'order_partner_id' => $customer_id,
				'order_id'         => $odoo_order,
				'product_id'       => 1229, // Hardcoded shipping product ID
				'product_uom_qty'  => 1,
				'price_unit'       => $order->get_shipping_total(),
			);

			if ( $shipping_line ) {
				if (!$this->client->update_record( 'sale.order.line', $shipping_line->id, $shipping_data )) {
					$this->client->log_error( 'Error updating shipping line in Odoo', array( 'Shipping Line Data' => $shipping_data ) );
					$retval = false;
				}
			} elseif (!$this->client->create_record( 'sale.order.line', $shipping_data )) {
					$this->client->log_error( 'Error creating shipping line in Odoo', array( 'Shipping Line Data' => $shipping_data ) );
					$retval = false;
			}
		}

		return $retval;
	}

	/**
	 * Manage Customer Data.
	 *
	 * @param WC_Order $order order objects data.
	 *
	 * @return array|false $customer_data return customer data or false if error is found
	 */
	public function get_customer_data( $order ) {
		if ( !$this->client->authenticate() ) {
			return false;
		}

		$customer_data         = array();
		$billing_address_order = $order->get_address( 'billing' );
		$user                  = $order->get_user();

		if ( $user ) {
			$email = $user->user_email;
		} else {
			$email = $billing_address_order['email'];
		}

		if ( $email ) {
			$customer_id = $this->client->search_read(
				'res.partner',
				array(
					array(
						'email',
						'=',
						$email,
					),
					array(
						'type',
						'=',
						'contact',
					),
				),
				array( 'id' ),
				null,
				1,
				null,
				array( 'single' => true )
			);

			// If user not exists in Odoo then Create New Customer in odoo.
			if ( empty( $customer_id ) || false === $customer_id ) {
				$this->client->log_info( 'User not found in Odoo, creating new', array( 'User Email' => $email ) );
				// Registered users build the partner from WP_User + user meta. Guest
				// orders have no WP_User ($order->get_user() === false), so build the
				// partner from the order's billing address instead. Without this,
				// guest checkouts fail with "Customer data is empty" and never sync.
				$customer_id = $user
					? $this->create_or_update_customer( $user, null )
					: $this->create_customer_from_order( $order, null );
			} else {
				// Remove std class object from the result to match the create return value.
				$customer_id = (int) $customer_id->id;
			}

			if ( is_numeric( $customer_id ) ) {
				$customer_data['id']          = $customer_id;
				$customer_data['invoice_id']  = $this->get_or_create_address( 'invoice', $billing_address_order, $customer_id );
				$shipping_address_order = $order->get_address( 'shipping' );
				// Fall back to billing address if shipping is empty (e.g. "ship to same address")
				if ( empty( $shipping_address_order['address_1'] ) && empty( $shipping_address_order['city'] ) ) {
					$shipping_address_order = $billing_address_order;
				}
				$customer_data['shipping_id'] = $this->get_or_create_address( 'delivery', $shipping_address_order, $customer_id );
			}
		}

		return $customer_data;
	}

	/**
	 * Create or update customer.
	 *
	 * @param WP_User    $customer_data Customer data.
	 * @param int|null   $customer_id   Customer ID.
	 */
	public function create_or_update_customer( $customer_data, $customer_id ) {

		if ( !$customer_data ) {
			$this->client->log_error( 'Error creating customer in Odoo', array( 'msg' => 'Customer data is empty' ) );
			return false;
		}

		$all_meta_for_user = get_user_meta( $customer_data->ID ) ?: array();
		$billing_state     = isset( $all_meta_for_user['billing_state'][0] ) ? $all_meta_for_user['billing_state'][0] : '';
		$billing_country   = isset( $all_meta_for_user['billing_country'][0] ) ? $all_meta_for_user['billing_country'][0] : 'CL';
		$state_county      = $this->get_state_and_country_codes( $billing_state, $billing_country );

		// Build customer name with proper fallbacks to avoid empty names in Odoo
		$first_name = trim( get_user_meta( $customer_data->ID, 'first_name', true ) );
		$last_name = trim( get_user_meta( $customer_data->ID, 'last_name', true ) );

		// If user profile names are empty, try billing names from meta
		if ( empty( $first_name ) && isset( $all_meta_for_user['billing_first_name'][0] ) ) {
			$first_name = trim( $all_meta_for_user['billing_first_name'][0] );
		}
		if ( empty( $last_name ) && isset( $all_meta_for_user['billing_last_name'][0] ) ) {
			$last_name = trim( $all_meta_for_user['billing_last_name'][0] );
		}

		// Construct full name with proper spacing
		$customer_name = trim( $first_name . ' ' . $last_name );

		// If still empty, use display_name as fallback
		if ( empty( $customer_name ) ) {
			$customer_name = $customer_data->display_name;
		}

		// Final fallback to user_login if still empty
		if ( empty( $customer_name ) ) {
			$customer_name = $customer_data->user_login;
		}

		$data              = array(
			'name'                              => $customer_name,
			'display_name'                      => $customer_name,
			'email'                             => $customer_data->user_email,
			'customer_rank'                     => 1,
			'type'                              => 'contact',
			'phone'                             => isset( $all_meta_for_user['billing_phone'][0] ) ? $all_meta_for_user['billing_phone'][0] : '',
			'street'                            => isset( $all_meta_for_user['billing_address_1'][0] ) ? $all_meta_for_user['billing_address_1'][0] : '',
			'city'                              => isset( $all_meta_for_user['billing_city'][0] ) ? $all_meta_for_user['billing_city'][0] : '',
			'state_id'                          => $state_county['state'],
			'country_id'                        => $state_county['country'],
			'zip'                               => isset( $all_meta_for_user['billing_postcode'][0] ) ? $all_meta_for_user['billing_postcode'][0] : '',
			'l10n_latam_identification_type_id' => 4,
			'vat'                               => $this->format_rut( isset( $all_meta_for_user['billing_rut'][0] ) ? $all_meta_for_user['billing_rut'][0] : '' ),
			'l10n_cl_sii_taxpayer_type'         => '1',
			'l10n_cl_dte_email'                 => isset( $all_meta_for_user['billing_email'][0] ) ? $all_meta_for_user['billing_email'][0] : $customer_data->user_email,
			'l10n_cl_activity_description'      => !empty( $all_meta_for_user['billing_giro'][0] ) ? $all_meta_for_user['billing_giro'][0] : 'Manicurista',
		);

		if ( $customer_id ) {
			$response = $this->client->update_record( 'res.partner', $customer_id, $data );
		} else {
			$response = $this->client->create_record( 'res.partner', $data );
		}

		return $response;
	}

	/**
	 * Create (or update) an Odoo partner from a guest order's billing address.
	 *
	 * Guest checkouts have no WP_User, so the data that create_or_update_customer()
	 * reads from user meta is unavailable. This builds the same res.partner payload
	 * from $order->get_address( 'billing' ) plus the order's _billing_rut meta.
	 *
	 * @param WC_Order  $order       The guest order.
	 * @param int|null  $customer_id Existing Odoo partner id to update, or null to create.
	 */
	public function create_customer_from_order( $order, $customer_id ) {

		$billing = $order->get_address( 'billing' );

		if ( empty( $billing['email'] ) ) {
			$this->client->log_error( 'Error creating customer in Odoo', array( 'msg' => 'Guest order has no billing email' ) );
			return false;
		}

		$billing_state   = isset( $billing['state'] ) ? $billing['state'] : '';
		$billing_country = !empty( $billing['country'] ) ? $billing['country'] : 'CL';
		$state_county    = $this->get_state_and_country_codes( $billing_state, $billing_country );

		$customer_name = trim( $billing['first_name'] . ' ' . $billing['last_name'] );
		if ( empty( $customer_name ) ) {
			$customer_name = $billing['email'];
		}

		// RUT is stored as order meta (guest orders have no user meta). Prefer the
		// canonical _billing_rut, fall back to billing_rut.
		$rut = $order->get_meta( '_billing_rut' );
		if ( empty( $rut ) ) {
			$rut = $order->get_meta( 'billing_rut' );
		}

		$data = array(
			'name'                              => $customer_name,
			'display_name'                      => $customer_name,
			'email'                             => $billing['email'],
			'customer_rank'                     => 1,
			'type'                              => 'contact',
			'phone'                             => isset( $billing['phone'] ) ? $billing['phone'] : '',
			'street'                            => isset( $billing['address_1'] ) ? $billing['address_1'] : '',
			'city'                              => isset( $billing['city'] ) ? $billing['city'] : '',
			'state_id'                          => $state_county['state'],
			'country_id'                        => $state_county['country'],
			'zip'                               => isset( $billing['postcode'] ) ? $billing['postcode'] : '',
			'l10n_latam_identification_type_id' => 4,
			'vat'                               => $this->format_rut( $rut ),
			'l10n_cl_sii_taxpayer_type'         => '1',
			'l10n_cl_dte_email'                 => $billing['email'],
			'l10n_cl_activity_description'      => 'Manicurista',
		);

		if ( $customer_id ) {
			$response = $this->client->update_record( 'res.partner', $customer_id, $data );
		} else {
			$response = $this->client->create_record( 'res.partner', $data );
		}

		return $response;
	}

	public function format_rut( $rut ) {
		// Remove any non-numeric characters
		$rut = preg_replace( '/[^0-9]/', '', $rut );

		// Insert the hyphen before the last character
		$formatted_rut = substr( $rut, 0, -1 ) . '-' . substr( $rut, -1 );

		return $formatted_rut;
	}

	/**
	 * Get the state ID based on the state code and country code.
	 *
	 * @param string $state_code The state code.
	 * @param string $country_code The country code.
	 * @return array The state ID.
	 */
	public function get_state_and_country_codes( $state_code, $country_code ) {
		if ( !$this->client->authenticate() ) {
			return false;
		}
		$state_codes = array();

		$country = $this->client->search_read( 'res.country', array( array( 'code', '=', $country_code ) ), array( 'id' ), null, 1, null, array( 'single' => true ) );
		if ( $country ) {
			$state_codes['country'] = $country->id;

			if ( 'Región Metropolitana de Santiago' === $state_code ) {
				$state_code = 'Metropolitana';
			}
			$states = $this->client->search_read( 'res.country.state', array( array( 'name', 'like', "%{$state_code}%" ), array( 'country_id', '=', $country->id ) ), array( 'id' ), null, 1, null, array( 'single' => true ) );
			if ( $states ) {
				$state_codes['state'] = $states->id;
			} else {
				$state_codes['state'] = false;
			}
		} else {
			$state_codes['country'] = false;
			$state_codes['state']   = false;
		}

		return $state_codes;
	}

	public function prepare_order_data( $order ) {
		$order_data          = $order->get_data();
		$order_lines         = $order->get_items();
		$order_data['lines'] = array();
		foreach ($order_lines as $line) {
			$order_data['lines'][] = $line->get_data();
		}
		return $order_data;
	}

	public function get_or_create_address( $type, $address_order, $customer_id ) {
		if ( !$this->client->authenticate() ) {
			return false;
		}
		$is_new_address = true;
		$odoo_address   = $this->client->search_read(
			'res.partner',
			array(
				array( 'parent_id', '=', $customer_id ),
				array( 'type', '=', $type ),
				array( 'street', '=ilike', $address_order['address_1'] ),
				array( 'zip', '=', $address_order['postcode'] ),
			),
			array( 'id' ),
			null,
			1,
			null,
			array( 'single' => true )
		);
		if ( $odoo_address ) {
			$is_new_address = false;
			// Update existing address with current order data (name/phone/city may have changed)
			$update_data = $this->create_address_data( $type, $address_order, $customer_id );
			$this->client->update_record( 'res.partner', (int) $odoo_address->id, $update_data );
			return $odoo_address->id;
		}
		if ( $is_new_address ) {
			$address    = $this->create_address_data( $type, $address_order, $customer_id );
			$address_id = $this->client->create_record( 'res.partner', $address );
			if ( $address_id && !isset( $address_id['faultString'] ) ) {
				return $address_id;
			} else {
				$this->client->log_error( 'Error creating address for customer', array( 'Address' => $address ) );
				return false;
			}
		}
	}

		/**
	 * Create data for the odoo customer address.
	 *
	 * @param  string  $address_type address type delivery/invoice.
	 * @param  array   $userdata    [user data].
	 * @param  integer $parent_id   [user_id ].
	 *
	 * @return array              [formated address data for the customer]
	 */
	public function create_address_data( $address_type, $userdata, $parent_id ) {
		$data = array(
			'name'      => $userdata['first_name'] . ' ' . $userdata['last_name'],
			'email'     => $userdata['email'] ?? '',
			'street'    => $userdata['address_1'],
			'street2'   => $userdata['address_2'],
			'zip'       => $userdata['postcode'],
			'city'      => $userdata['city'] ?? '',
			'type'      => $address_type,
			'parent_id' => (int) $parent_id,
			'phone'     => $userdata['phone'] ?? false,
		);

		if ( ! empty( $userdata['state'] ) || ! empty( $userdata['country'] ) ) {
			$state_county = $this->get_state_and_country_codes( $userdata['state'], $userdata['country'] );
			if ( ! empty( $state_county ) ) {
				$data['state_id']   = $state_county['state'];
				$data['country_id'] = $state_county['country'];
			}
		}

		return $data;
	}

	/**
	 * Get all the SKUs in Odoo for a given order
	 *
	 * @param WC_Order $order
	 * @return array
	 *
	 */
	public function get_odoo_skus( $order ) {
		$skus        = array();
		$order_items = $order->get_items();
		foreach ($order_items as $item) {
			$skus[] = $item->get_product()->get_sku();
		}
		// Query Odoo for the products
		$products = $this->client->search_read( 'product.product', array( array( 'default_code', 'in', $skus ) ), array( 'default_code', 'id' ), null, 1000, null, array( 'indexBy' => 'default_code' ) );
		return $products;
	}


	/**
	 * Add order line item to Odoo
	 *
	 * @param WC_Order $order
	 * @param array $odoo_order
	 * @param int $customer_id
	 *
	 * @return bool true if the order lines was added successfully
	 */
	public function add_order_line_items( $order, $odoo_order, $customer_id ) {
		$order_items   = $order->get_items();
		$odoo_products = $this->get_odoo_skus( $order );
		$all_success   = true;

		foreach ($order_items as $item) {
			$product      = $item->get_product();
			$odoo_product = $odoo_products[ $product->get_sku() ] ?? false;
			if ( $odoo_product ) {
				$unit_price = number_format( (float) ( $item->get_total() / $item->get_quantity() ), 2, '.', '' );

				$line_data = array(
					'order_partner_id' => $customer_id,
					'order_id'         => $odoo_order,
					'product_id'       => $odoo_product->id,
					'product_uom_qty'  => $item->get_quantity(),
					'price_unit'       => $unit_price,
				);
				if ( $item->get_total_tax() > 0 ) {
					//Hardcoded IVA tax id, check if the array( 6, 0, array (3) ) is correct
					$line_data['tax_id'] = array( array( 6, 0, array( 3 ) ) );
				} else {
					$line_data['tax_id'] = array( array( 6, 0, array() ) );
				}
				// Check if the order line was already created
				$odoo_order_line = $this->client->search_read(
					'sale.order.line',
					array(
						array( 'order_id', '=', $odoo_order ),
						array( 'product_id.id', '=', $odoo_product->id ),
					),
					array( 'id' ),
					null,
					1,
					null,
					array( 'single' => true )
				);
				if ( $odoo_order_line ) {
					$retval = $this->client->update_record( 'sale.order.line', $odoo_order, $line_data );
				} else {
					$retval = $this->client->create_record( 'sale.order.line', $line_data );
				}
				if ( !$retval ) {
					$this->client->log_error( 'Error creating order line item in Odoo', array( 'Order Line Data' => $line_data ) );
					$all_success = false;
				}
			} else {
				$this->client->log_error( 'Product not found in Odoo', array( 'Product SKU' => $product->get_sku() ) );
				$all_success = false;
			}
		}

		return $all_success;
	}

	/**
	 * Get the last l10n_latam_document_number of a l10n_latam_document_type_id.
	 *
	 * @param int $l10n_latam_document_type_id The ID of the document type. 5 or 1 are valid values.
	 * @return string The last l10n_latam_document_number formated with 000000.
	 */
	public function get_last_l10n_latam_document_number( $l10n_latam_document_type_id ) {
		$journal_id = ( new Woo2Odoo_Plugin_Settings() )->get_value( 'export_order_journal', '', 'export' );

		$last_number = $this->client->search_read(
			'account.move',
			array(
				array( 'l10n_latam_document_type_id', '=', $l10n_latam_document_type_id ),
				array( 'name', 'not like', 'False%' ),
				array( 'state', '!=', 'cancel' ),
				array( 'journal_id', '=', (int) $journal_id ),
			),
			array( 'l10n_latam_document_number' ),
			null,
			1,
			'name DESC',
			array( 'single' => true )
		);

		if ( !isset( $last_number ) || !isset( $last_number->l10n_latam_document_number ) ) {
			$this->client->log_error( 'Unable to get Last Document Number For Document', array( 'document type id' => $l10n_latam_document_type_id, 'last_number' => $last_number ) );

			return '000000';
		}
		$new_number = (int) $last_number->l10n_latam_document_number;
		++$new_number;

		// Return a string with 6 digits, filled with zero to the left.
		return str_pad( $new_number, 6, '0', STR_PAD_LEFT );
	}


	/**
	 * Extract payment info from WooCommerce order if paid via Transbank or MercadoPago.
	 *
	 * @param \WC_Order $order WooCommerce order.
	 * @return array|false Array with keys 'amount', 'date', 'memo' if confirmed payment, false otherwise.
	 */
	private function get_payment_info_from_wc_order( \WC_Order $order ) {
		$payment_method = $order->get_payment_method();

		if ( 'transbank_webpay_plus_rest' === $payment_method ) {
			$status = $order->get_meta( 'transactionStatus' );
			if ( 'Autorizada' !== $status ) {
				return false;
			}

			$amount = $order->get_meta( 'amount' );
			if ( empty( $amount ) ) {
				$amount = $order->get_total();
			}
			$amount = (float) $amount;

			$date_str = $order->get_meta( 'transactionDate' );
			$date = $this->parse_transbank_date( $date_str );

			$memo = 'Pedido WC#' . $order->get_id();

			return array(
				'amount' => $amount,
				'date'   => $date,
				'memo'   => $memo,
			);
		} elseif ( 'woo-mercado-pago-basic' === $payment_method ) {
			$payment_ids = $order->get_meta( '_Mercado_Pago_Payment_IDs' );
			$paid_date = $order->get_meta( '_paid_date' );

			if ( empty( $payment_ids ) || empty( $paid_date ) ) {
				return false;
			}

			$amount = (float) $order->get_total();

			$date = $this->parse_mercadopago_date( $paid_date );

			$memo = 'Pedido WC#' . $order->get_id();

			return array(
				'amount' => $amount,
				'date'   => $date,
				'memo'   => $memo,
			);
		}

		return false;
	}

	/**
	 * Parse Transbank date format (d-m-Y H:i:s P) to Y-m-d.
	 *
	 * @param string $date_str Date string from Transbank metadata.
	 * @return string Date in Y-m-d format.
	 */
	private function parse_transbank_date( $date_str ) {
		if ( empty( $date_str ) ) {
			return date( 'Y-m-d' );
		}

		$date = \DateTime::createFromFormat( 'd-m-Y H:i:s P', $date_str );
		if ( false === $date ) {
			return date( 'Y-m-d' );
		}

		return $date->format( 'Y-m-d' );
	}

	/**
	 * Parse MercadoPago date format (Y-m-d H:i:s) to Y-m-d.
	 *
	 * @param string $date_str Date string from MercadoPago metadata.
	 * @return string Date in Y-m-d format.
	 */
	private function parse_mercadopago_date( $date_str ) {
		if ( empty( $date_str ) ) {
			return date( 'Y-m-d' );
		}

		$date = \DateTime::createFromFormat( 'Y-m-d H:i:s', $date_str );
		if ( false === $date ) {
			return date( 'Y-m-d' );
		}

		return $date->format( 'Y-m-d' );
	}

	/**
	 * Create an outstanding credit in Odoo for electronic payments (Transbank, MercadoPago).
	 *
	 * Creates an account.payment (inbound customer payment) linked to the invoice.
	 *
	 * Uses account.payment + action_post so Odoo manages the journal entry automatically.
	 * The journal configured in payment_journal_id must be the bank journal that corresponds
	 * to the actual bank account where the payment gateway (e.g. Transbank) deposits funds
	 * (e.g. Scotiabank). That journal must have payment_account_id set on its inbound
	 * payment method line (account.payment.method.line) — this is what triggers journal
	 * entry creation: Dr outstanding_receipts_account / Cr receivable.
	 *
	 * With l10n_cl, action_post puts the payment in "in_process" state (not "posted").
	 * Success is verified by checking that move_id was assigned (journal entry exists),
	 * not by checking state == "posted".
	 *
	 * Invoice payment_state will become "in_payment" once the payment is linked and posted.
	 * Final "paid" status occurs when the bank reconciliation step matches the outstanding
	 * receipts line against the actual bank deposit (done manually by the accountant).
	 *
	 * @param int            $invoice_id   Odoo account.move ID (invoice).
	 * @param int            $partner_id   Odoo res.partner ID (customer, any contact — commercial master resolved here).
	 * @param array          $payment_info Array with keys: amount (float), date (Y-m-d), memo (string).
	 * @param \WC_Order|null $order        WC order to add a sync note to (optional).
	 * @return int|false Payment ID on success, false on failure.
	 */
	private function create_outstanding_payment( $invoice_id, $partner_id, $payment_info, ?\WC_Order $order = null ) {
		$export_settings = get_option( 'Woo2Odoo-plugin-export', array() );
		$journal_id      = isset( $export_settings['payment_journal_id'] ) ? (int) $export_settings['payment_journal_id'] : 0;
		if ( ! $journal_id ) {
			wc_get_logger()->error( 'woo2odoo: payment_journal_id not configured — skipping payment creation' );
			return false;
		}

		// Resolve to commercial master so the payment partner matches the invoice.
		$partner_rows = $this->client->execute( 'res.partner', 'read', array(
			array( $partner_id ),
			array( 'commercial_partner_id' ),
		) );
		if ( ! empty( $partner_rows[0]->commercial_partner_id ) ) {
			$partner_id = (int) $partner_rows[0]->commercial_partner_id[0];
		}

		// Create account.payment linked to the invoice via invoice_ids (many2many add).
		// Odoo will reconcile the payment's receivable line with the invoice on action_post,
		// setting invoice payment_state = "in_payment".
		$payment_id = $this->client->create_record( 'account.payment', array(
			'payment_type' => 'inbound',
			'partner_type' => 'customer',
			'partner_id'   => $partner_id,
			'journal_id'   => $journal_id,
			'amount'       => $payment_info['amount'],
			'date'         => $payment_info['date'],
			'memo'         => $payment_info['memo'],
			'currency_id'  => 44,
			'invoice_ids'  => array( array( 4, $invoice_id, 0 ) ),
		) );

		if ( ! $payment_id ) {
			$this->client->log_error( 'Failed to create account.payment', array(
				'invoice_id' => $invoice_id,
				'partner_id' => $partner_id,
				'journal_id' => $journal_id,
			) );
			return false;
		}

		// Post the payment. With l10n_cl the resulting state is "in_process" (not "posted"),
		// but the journal entry IS created when the journal has payment_account_id configured.
		$this->client->execute( 'account.payment', 'action_post', array( array( $payment_id ) ) );

		// Verify success via move_id — journal entry must have been created.
		$pay_rows  = $this->client->execute( 'account.payment', 'read', array(
			array( $payment_id ),
			array( 'name', 'state', 'move_id' ),
		) );
		$pay_name  = ! empty( $pay_rows ) ? $pay_rows[0]->name : "ID {$payment_id}";
		$pay_state = ! empty( $pay_rows ) ? $pay_rows[0]->state : 'draft';
		$move_id   = ! empty( $pay_rows ) && ! empty( $pay_rows[0]->move_id )
			? (int) $pay_rows[0]->move_id[0]
			: 0;

		if ( ! $move_id ) {
			$this->client->log_error(
				'Payment posted but no journal entry created — verify journal payment_account_id config',
				array(
					'payment_id' => $payment_id,
					'pay_state'  => $pay_state,
					'journal_id' => $journal_id,
					'invoice_id' => $invoice_id,
				)
			);
			return false;
		}

		$this->client->log_info( 'Customer payment created and linked to invoice', array(
			'payment_id' => $payment_id,
			'pay_name'   => $pay_name,
			'pay_state'  => $pay_state,
			'move_id'    => $move_id,
			'invoice_id' => $invoice_id,
			'partner_id' => $partner_id,
			'amount'     => $payment_info['amount'],
		) );

		if ( $order ) {
			$order->add_order_note( "Woo2Odoo: Pago {$pay_name} registrado en Odoo ({$pay_state})." );
		}

		return $payment_id;
	}

	/**
	 * Returns the Odoo states.
	 *
	 * @param string $value current Woo Order status.
	 * @param string $context Odoo context (order_state, invoice_state, payment_state, invoice_status, rev_invoice).
	 *
	 * @return string|array the requested state.
	 */
	public function odoo_states( $value, $context ) {

		return $this->states[ $value ][ $context ];
	}

	/**
	 * Create a credit note (nota de crédito) in Odoo when WC registers a refund.
	 *
	 * @param int $order_id  WooCommerce order ID being refunded.
	 * @param int $refund_id WooCommerce refund post ID.
	 * @return int|false Credit note Odoo ID or false on failure.
	 */
	public function refund_sync( $order_id, $refund_id ) {
		if ( ! $this->client->authenticate() ) {
			return false;
		}

		try {
			// Skip if already exported (read via WC API to survive HPOS backfill)
			$wc_refund = new \WC_Order_Refund( $refund_id );
			$existing  = $wc_refund->get_meta( '_woo2odoo_return_invoice_id' );
			if ( $existing ) {
				$this->client->log_info( 'Refund already exported to Odoo', array( 'refund_id' => $refund_id, 'return_inv_id' => $existing ) );
				return (int) $existing;
			}

			// Find the original Odoo invoice (read via WC API)
			$wc_order        = wc_get_order( $order_id );
			$odoo_invoice_id = (int) $wc_order->get_meta( '_woo2odoo_invoice_id' );
			if ( ! $odoo_invoice_id ) {
				$this->client->log_warning( 'Original Odoo invoice not found for order — refund not exported', array( 'order_id' => $order_id ) );
				return false;
			}

			// Fetch invoice details from Odoo
			$odoo_invoice = $this->client->search_read(
				'account.move',
				array( array( 'id', '=', $odoo_invoice_id ) ),
				array( 'id', 'name', 'partner_id', 'invoice_origin' ),
				null, 1, null,
				array( 'single' => true )
			);
			if ( ! $odoo_invoice ) {
				$this->client->log_error( 'Odoo invoice not found by ID', array( 'invoice_id' => $odoo_invoice_id, 'order_id' => $order_id ) );
				return false;
			}

			$customer_data = $this->get_customer_data( $wc_order );
			if ( ! $customer_data ) {
				$this->client->log_error( 'Error getting customer data for refund', array( 'order_id' => $order_id ) );
				return false;
			}

			$export_settings        = get_option( 'Woo2Odoo-plugin-export', array() );
			$journal_id             = isset( $export_settings['invoiceJournal'] ) ? (int) $export_settings['invoiceJournal'] : 9;
			// Chile: Odoo record id=3 → code 61 "Electronic Credit Note" (Nota de Crédito)
			// Applies to both Boleta (39) and Factura (33) refunds.
			$latam_document_type_id = 3;

			$credit_note_id = $this->client->create_record( 'account.move', array(
				'move_type'                   => 'out_refund',
				'partner_id'                  => (int) $customer_data['id'],
				'partner_shipping_id'         => (int) $customer_data['shipping_id'],
				'reversed_entry_id'           => $odoo_invoice_id,
				'invoice_origin'              => $odoo_invoice->name ?? ( 'WC#' . $order_id ),
				'journal_id'                  => $journal_id,
				'invoice_date'                => date( 'Y-m-d' ),
				'currency_id'                 => 44, // CLP
				'l10n_latam_document_type_id' => $latam_document_type_id,
				'payment_reference'           => 'Refund WC#' . $order_id,
			) );

			if ( ! $credit_note_id ) {
				$this->client->log_error( 'Failed to create credit note in Odoo', array( 'order_id' => $order_id, 'invoice_id' => $odoo_invoice_id ) );
				return false;
			}

			// Gather SKUs for all refunded items
			$refund_items = $wc_refund->get_items();
			$skus         = array();
			foreach ( $refund_items as $item ) {
				$product = $item->get_product();
				if ( $product && $product->get_sku() ) {
					$skus[] = $product->get_sku();
				}
			}

			$odoo_products = array();
			if ( ! empty( $skus ) ) {
				$odoo_products = $this->client->search_read(
					'product.product',
					array( array( 'default_code', 'in', $skus ) ),
					array( 'id', 'default_code' ),
					null, 1000, null,
					array( 'indexBy' => 'default_code' )
				) ?: array();
			}

			foreach ( $refund_items as $item ) {
				$product = $item->get_product();
				if ( ! $product ) {
					continue;
				}
				$sku          = $product->get_sku();
				$odoo_product = $odoo_products[ $sku ] ?? false;
				if ( ! $odoo_product ) {
					$this->client->log_warning( 'Product not found in Odoo for refund line', array( 'sku' => $sku, 'order_id' => $order_id ) );
					continue;
				}

				$qty        = absint( $item->get_quantity() );
				$total      = abs( $item->get_total() );
				$unit_price = $qty > 0 ? round( $total / $qty, 2 ) : 0;

				$line_data = array(
					'move_id'    => $credit_note_id,
					'product_id' => (int) $odoo_product->id,
					'quantity'   => $qty,
					'price_unit' => $unit_price,
				);

				if ( abs( $item->get_total_tax() ) > 0 ) {
					$line_data['tax_ids'] = array( array( 6, 0, array( 3 ) ) ); // IVA 19%
				} else {
					$line_data['tax_ids'] = array( array( 6, 0, array() ) );
				}

				if ( ! $this->client->create_record( 'account.move.line', $line_data ) ) {
					$this->client->log_warning( 'Failed to create credit note line', array( 'sku' => $sku, 'order_id' => $order_id ) );
				}
			}

			// Store on WC refund object to survive HPOS backfill sync
			$wc_refund->update_meta_data( '_woo2odoo_return_invoice_id', $credit_note_id );
			$wc_refund->save();

			$this->client->log_info( 'Credit note created in Odoo (draft)', array(
				'credit_note_id' => $credit_note_id,
				'order_id'       => $order_id,
				'refund_id'      => $refund_id,
			) );

			return $credit_note_id;

		} catch ( Exception $e ) {
			$this->client->log_exception( 'refund_sync failed', $e );
			$wc_order = wc_get_order( $order_id );
			if ( $wc_order ) {
				$wc_order->update_meta_data( '_woo2odoo_refund_sync_status', 'failed' );
				$wc_order->update_meta_data( '_woo2odoo_refund_sync_error', mb_substr( $e->getMessage(), 0, 255 ) );
				$wc_order->update_meta_data( '_woo2odoo_refund_sync_date', current_time( 'mysql' ) );
				$wc_order->save();
			}
			return false;
		}
	}
}
