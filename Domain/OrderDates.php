<?php
/**
 * Order dates that start the withdrawal period.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Domain;

use DateTimeImmutable;

/**
 * Class OrderDates
 *
 * Plain snapshot of the order, so the deadline rules stay free of WooCommerce.
 */
final class OrderDates {

	/**
	 * Creates the snapshot.
	 *
	 * @param DateTimeImmutable      $created   Order creation, always known.
	 * @param DateTimeImmutable|null $paid      Payment moment.
	 * @param DateTimeImmutable|null $completed Moment the order became "Completed".
	 * @param DateTimeImmutable|null $delivered Delivery date entered by the shop or a carrier integration.
	 * @param bool                   $fulfilled Whether the order is completed (status or completion date).
	 */
	public function __construct(
		public readonly DateTimeImmutable $created,
		public readonly ?DateTimeImmutable $paid = null,
		public readonly ?DateTimeImmutable $completed = null,
		public readonly ?DateTimeImmutable $delivered = null,
		public readonly bool $fulfilled = false
	) {
	}
}
