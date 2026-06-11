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

	public function order_sync( $order_id ) {
		if ( !$this->client->authenticate() ) {
			return false;
		}

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
					$payment_info = $this->get_payment_info_from_wc_order( $order );
					if ( $payment_info ) {
						$this->create_outstanding_payment( $invoice_id, (int) $customer_data['id'], $payment_info );
					}
				}
			}

		} catch (Exception $e) {
			$this->client->log_exception( 'Odoo order_sync failed', $e );
			return false;
		}

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

		// Create account.move header (draft invoice)
		$invoice_id = $this->client->create_record( 'account.move', array(
			'move_type'                    => 'out_invoice',
			'partner_id'                   => (int) $customer_data['invoice_id'],
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
			'partner_id'               => (int) $odoo_customer['invoice_id'],
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
				$customer_id = $this->create_or_update_customer( $user, null );
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

			$memo = 'TBK-' . $order->get_meta( 'authorizationCode' ) . ' / WC#' . $order->get_id();

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

			$memo = 'MP-' . $payment_ids . ' / WC#' . $order->get_id();

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
	 * Create an outstanding payment record in Odoo for electronic payments.
	 *
	 * @param int   $invoice_id Odoo account.move ID (invoice).
	 * @param int   $partner_id Odoo res.partner ID (customer).
	 * @param array $payment_info Payment info array with keys: amount, date, memo.
	 * @return int|false Payment ID if created and posted, false on failure.
	 */
	private function create_outstanding_payment( $invoice_id, $partner_id, $payment_info ) {
		$export_settings = get_option( 'Woo2Odoo-plugin-export', array() );
		$journal_id      = isset( $export_settings['payment_journal_id'] ) ? (int) $export_settings['payment_journal_id'] : 14;

		$payment_data = array(
			'payment_type' => 'inbound',
			'partner_type' => 'customer',
			'partner_id'   => $partner_id,
			'amount'       => $payment_info['amount'],
			'journal_id'   => $journal_id,
			'date'         => $payment_info['date'],
			'memo'         => $payment_info['memo'],
			'currency_id'  => 44,
		);

		$payment_id = $this->client->create_record( 'account.payment', $payment_data );

		if ( !$payment_id ) {
			$this->client->log_error( 'Failed to create outstanding payment', array( 'payment_data' => $payment_data ) );
			return false;
		}

		try {
			$this->client->execute( 'account.payment', 'action_post', array( array( $payment_id ) ) );
			$this->client->log_info( 'Outstanding payment created and posted', array(
				'payment_id' => $payment_id,
				'invoice_id' => $invoice_id,
				'partner_id' => $partner_id,
				'amount'     => $payment_info['amount'],
			) );
			return $payment_id;
		} catch ( Exception $e ) {
			$this->client->log_error( 'Failed to post outstanding payment', array(
				'payment_id' => $payment_id,
				'error'      => $e->getMessage(),
			) );
			return false;
		}
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
}
