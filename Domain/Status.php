<?php
/**
 * Request statuses and allowed transitions.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Domain;

/**
 * Enum Status
 *
 * The only place that decides which status changes are allowed.
 * Leaving a final status needs an explicit
 * "reopen" action, which is not a regular transition.
 *
 * Flow: New (waiting for the parcel), In transit (automatic when the
 * customer adds a tracking number), Parcel received, then the refund closes
 * the request ("Completed, refunded"). "Completed" closes a request without
 * a refund. There is no "accepted" step: the statement is effective when
 * made, the shop does not accept it.
 */
enum Status: string {

	case New       = 'new';
	case InTransit = 'in_transit';
	case Received  = 'received';
	case Refunded  = 'refunded';
	case Completed = 'completed';
	case Rejected  = 'rejected';
	case Cancelled = 'cancelled';

	/**
	 * Whether the status closes the request until it is reopened.
	 *
	 * @return bool
	 */
	public function is_final(): bool {
		return in_array( $this, array( self::Refunded, self::Completed, self::Rejected, self::Cancelled ), true );
	}

	/**
	 * Whether "reopen" may bring the request back. A refunded request stays
	 * closed: the money went back already.
	 *
	 * @return bool
	 */
	public function can_reopen(): bool {
		return $this->is_final() && self::Refunded !== $this;
	}

	/**
	 * Statuses reachable from this one by a regular transition.
	 *
	 * @return list<self>
	 */
	public function allowed_transitions(): array {
		return match ( $this ) {
			self::New       => array( self::InTransit, self::Received, self::Refunded, self::Completed, self::Rejected, self::Cancelled ),
			self::InTransit => array( self::Received, self::Refunded, self::Completed, self::Rejected, self::Cancelled ),
			self::Received  => array( self::Refunded, self::Completed, self::Rejected, self::Cancelled ),
			self::Refunded, self::Completed, self::Rejected, self::Cancelled => array(),
		};
	}

	/**
	 * Whether a regular transition to the target status is allowed.
	 *
	 * @param self $target Target status.
	 * @return bool
	 */
	public function can_transition_to( self $target ): bool {
		return in_array( $target, $this->allowed_transitions(), true );
	}
}
