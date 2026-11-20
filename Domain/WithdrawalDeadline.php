<?php
/**
 * The one place that computes the end of the withdrawal period.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Class WithdrawalDeadline
 *
 * The law counts the period from delivery, which WooCommerce does not know.
 * Start of the period, first match wins:
 * 1. the delivery date entered by the shop (or a carrier integration);
 * 2. "count from paid": payment date plus the delivery buffer;
 * 3. completion date plus the delivery buffer (shops mark orders completed
 *    when they ship, not when the parcel arrives);
 * 4. a completed order without a completion date: payment (or creation) date
 *    plus the delivery buffer.
 * An order that is paid but not completed yet may be withdrawn before delivery.
 * By default it closes 30 days after payment (never sooner than buffer plus
 * period). A shop that always marks orders Completed on shipment may set the
 * limit to 0: the order then stays open, with the deadline it would get if it
 * shipped today. An order never has "no deadline".
 */
final class WithdrawalDeadline {

	public const FROM_COMPLETED = 'completed';

	public const FROM_PAID = 'paid';

	public const DEFAULT_DELIVERY_DAYS = 3;

	/**
	 * 0 means no limit for orders that are not completed yet.
	 */
	public const DEFAULT_UNFULFILLED_DAYS = 30;

	public const MAX_DAYS = 365;

	/**
	 * Days added to shipping or payment to reach the probable delivery.
	 *
	 * @var int
	 */
	private int $delivery_days;

	/**
	 * Longest time a paid but not completed order stays open.
	 *
	 * @var int
	 */
	private int $unfulfilled_days;

	/**
	 * Creates the rules.
	 *
	 * @param WithdrawalPeriod $period           Period length.
	 * @param int              $delivery_days    Delivery buffer in days.
	 * @param int              $unfulfilled_days Max days since payment for orders not completed; 0 for no limit.
	 * @param string           $count_from       FROM_COMPLETED or FROM_PAID.
	 */
	public function __construct(
		private WithdrawalPeriod $period,
		int $delivery_days = self::DEFAULT_DELIVERY_DAYS,
		int $unfulfilled_days = self::DEFAULT_UNFULFILLED_DAYS,
		private string $count_from = self::FROM_COMPLETED
	) {
		$this->delivery_days    = self::clamp_days( $delivery_days );
		$this->unfulfilled_days = self::clamp_days( $unfulfilled_days );
	}

	/**
	 * Keeps a day count within 0..MAX_DAYS.
	 *
	 * @param int $days Days.
	 * @return int
	 */
	public static function clamp_days( int $days ): int {
		return min( self::MAX_DAYS, max( 0, $days ) );
	}

	/**
	 * Last moment (23:59:59 shop time) a statement is on time.
	 *
	 * @param OrderDates             $dates     Order dates.
	 * @param DateTimeZone           $shop_zone Shop timezone.
	 * @param DateTimeImmutable|null $now       Current moment, for an order not shipped yet; null for the clock.
	 * @return DateTimeImmutable
	 */
	public function deadline( OrderDates $dates, DateTimeZone $shop_zone, ?DateTimeImmutable $now = null ): DateTimeImmutable {
		if ( null !== $dates->delivered ) {
			return $this->period->deadline( $dates->delivered, $shop_zone );
		}

		$paid_or_created = $dates->paid ?? $dates->created;

		if ( self::FROM_PAID === $this->count_from ) {
			return $this->period->deadline( $this->plus_buffer( $paid_or_created ), $shop_zone );
		}

		if ( null !== $dates->completed ) {
			return $this->period->deadline( $this->plus_buffer( $dates->completed ), $shop_zone );
		}

		if ( $dates->fulfilled ) {
			return $this->period->deadline( $this->plus_buffer( $paid_or_created ), $shop_zone );
		}

		// Not shipped and no limit: the period has not started, so it ends as if the order shipped now.
		if ( 0 === $this->unfulfilled_days ) {
			return $this->period->deadline( $this->plus_buffer( $now ?? new DateTimeImmutable( 'now', $shop_zone ) ), $shop_zone );
		}

		$days = max( $this->unfulfilled_days, $this->delivery_days + $this->period->days() );

		return $paid_or_created
			->setTimezone( $shop_zone )
			->modify( '+' . $days . ' days' )
			->setTime( 23, 59, 59 );
	}

	/**
	 * Whether a statement made at $now is on time.
	 *
	 * @param OrderDates        $dates     Order dates.
	 * @param DateTimeImmutable $now       Statement moment.
	 * @param DateTimeZone      $shop_zone Shop timezone.
	 * @return bool
	 */
	public function is_open( OrderDates $dates, DateTimeImmutable $now, DateTimeZone $shop_zone ): bool {
		return $now <= $this->deadline( $dates, $shop_zone, $now );
	}

	/**
	 * Adds the delivery buffer.
	 *
	 * @param DateTimeImmutable $moment Shipping or payment moment.
	 * @return DateTimeImmutable
	 */
	private function plus_buffer( DateTimeImmutable $moment ): DateTimeImmutable {
		return $moment->modify( '+' . $this->delivery_days . ' days' );
	}
}
