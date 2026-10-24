<?php
/**
 * Woo2Odoo_Shadow_Order Class File
 *
 * A WC order that cannot be persisted. Shadow runs load the real order through
 * this class, so the sync code can set meta and add notes exactly as in a real
 * run while nothing reaches the database.
 *
 * @package Woo2Odoo
 */
namespace Woo2Odoo;

use WC_Order;

/**
 * Class Woo2Odoo_Shadow_Order
 */
class Woo2Odoo_Shadow_Order extends WC_Order {

	/** @var string[] Notes the sync would have added. */
	public $shadow_notes = array();

	/**
	 * Load the same order (same id, data and meta) as a shadow order.
	 *
	 * @param \WC_Order $order Real order.
	 * @return self
	 */
	public static function from_order( WC_Order $order ): self {
		return new self( $order->get_id() );
	}

	/**
	 * Never persist.
	 *
	 * @return int Order id.
	 */
	public function save() {
		return $this->get_id();
	}

	/**
	 * Record the note in memory only.
	 *
	 * @param string $note             Note text.
	 * @param int    $is_customer_note Ignored.
	 * @param bool   $added_by_user    Ignored.
	 * @param mixed  ...$rest          Ignored (newer WC signatures).
	 * @return int 0, no comment is created.
	 */
	public function add_order_note( $note, $is_customer_note = 0, $added_by_user = false, ...$rest ) {
		$this->shadow_notes[] = (string) $note;
		return 0;
	}
}
