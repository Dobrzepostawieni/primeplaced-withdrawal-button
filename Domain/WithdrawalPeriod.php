<?php
/**
 * Withdrawal period.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Class WithdrawalPeriod
 *
 * The period starts at delivery and ends at 23:59:59 shop time on its last
 * day. Which moment counts as delivery is decided by WithdrawalDeadline.
 */
final class WithdrawalPeriod {

	public const MIN_DAYS = 14;

	/**
	 * Length in days, never below the statutory minimum.
	 *
	 * @var int
	 */
	private int $days;

	/**
	 * Creates the period.
	 *
	 * @param int $days Length configured by the shop.
	 */
	public function __construct( int $days = self::MIN_DAYS ) {
		$this->days = max( self::MIN_DAYS, $days );
	}

	/**
	 * Period length in days.
	 *
	 * @return int
	 */
	public function days(): int {
		return $this->days;
	}

	/**
	 * Last moment of the period.
	 *
	 * @param DateTimeImmutable $delivered_at Delivery moment.
	 * @param DateTimeZone      $shop_zone    Shop timezone.
	 * @return DateTimeImmutable
	 */
	public function deadline( DateTimeImmutable $delivered_at, DateTimeZone $shop_zone ): DateTimeImmutable {
		return $delivered_at
			->setTimezone( $shop_zone )
			->modify( '+' . $this->days . ' days' )
			->setTime( 23, 59, 59 );
	}

	/**
	 * Whether a statement made now is on time.
	 *
	 * @param DateTimeImmutable $delivered_at Delivery moment.
	 * @param DateTimeImmutable $now          Current moment.
	 * @param DateTimeZone      $shop_zone    Shop timezone.
	 * @return bool
	 */
	public function is_open( DateTimeImmutable $delivered_at, DateTimeImmutable $now, DateTimeZone $shop_zone ): bool {
		return $now <= $this->deadline( $delivered_at, $shop_zone );
	}
}
