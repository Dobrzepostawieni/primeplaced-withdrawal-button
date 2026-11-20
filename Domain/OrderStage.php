<?php
/**
 * Where an order is in its life, as far as withdrawal is concerned.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Domain;

/**
 * Enum OrderStage
 *
 * - Shipped: the goods have left the shop, a regular withdrawal.
 * - BeforeShipment: paid (or payable on delivery), not shipped yet. The
 *   consumer may withdraw before delivery (Directive 2011/83/EU art. 9 ust. 3);
 *   by default the request is a "cancellation before shipment".
 * - Closed: not paid (also "on hold" while the shop waits for a bank
 *   transfer), cancelled, failed, refunded or a draft. Only a message.
 */
enum OrderStage: string {

	case Shipped        = 'shipped';
	case BeforeShipment = 'before_shipment';
	case Closed         = 'closed';

	/**
	 * Statuses of orders that are not paid or are already closed.
	 *
	 * @var list<string>
	 */
	private const CLOSED_STATUSES = array( 'pending', 'cancelled', 'failed', 'refunded', 'checkout-draft', 'draft', 'trash' );

	/**
	 * Statuses of orders the shop has not shipped yet. "withdrawal" is the
	 * plugin's own status: it is set after a request, before or after shipping.
	 *
	 * @var list<string>
	 */
	private const BEFORE_SHIPMENT_STATUSES = array( 'processing', 'on-hold', 'withdrawal' );

	/**
	 * Stage of an order.
	 *
	 * @param string $status    WooCommerce status without the wc- prefix.
	 * @param bool   $paid      Whether the order is paid: a paid status or a payment date.
	 * @param bool   $completed Whether the order has a completion date.
	 * @return self
	 */
	public static function of( string $status, bool $paid, bool $completed ): self {
		if ( in_array( $status, self::CLOSED_STATUSES, true ) ) {
			return self::Closed;
		}

		if ( 'completed' === $status || $completed ) {
			return self::Shipped;
		}

		// "On hold" without a payment waits for a bank transfer: there is nothing to refund yet.
		if ( 'on-hold' === $status && ! $paid ) {
			return self::Closed;
		}

		if ( in_array( $status, self::BEFORE_SHIPMENT_STATUSES, true ) ) {
			return self::BeforeShipment;
		}

		// A custom status ("shipped", "delivered") of a paid order.
		return $paid ? self::Shipped : self::Closed;
	}
}
