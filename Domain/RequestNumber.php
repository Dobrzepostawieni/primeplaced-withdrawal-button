<?php
/**
 * Request number formatting.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Domain;

use DateTimeImmutable;

/**
 * Class RequestNumber
 */
final class RequestNumber {

	public const DEFAULT_FORMAT = 'ZW-{RRRR}-{NNNNNN}';

	/**
	 * Builds a number from the format, the counter value and the submission date.
	 *
	 * Placeholders: {RRRR} year, {RR} two-digit year, {MM} month, {N...} counter
	 * padded to the number of N, {ZAMOWIENIE} order number.
	 *
	 * @param string            $format       Format from settings.
	 * @param int               $counter      Counter value assigned atomically.
	 * @param DateTimeImmutable $submitted_at Submission moment in shop time.
	 * @param string            $order_number WooCommerce order number.
	 * @return string
	 */
	public static function format( string $format, int $counter, DateTimeImmutable $submitted_at, string $order_number ): string {
		$number = strtr(
			$format,
			array(
				'{RRRR}'       => $submitted_at->format( 'Y' ),
				'{RR}'         => $submitted_at->format( 'y' ),
				'{MM}'         => $submitted_at->format( 'm' ),
				'{ZAMOWIENIE}' => $order_number,
			)
		);

		$with_counter = preg_replace_callback(
			'/\{(N+)\}/',
			static fn ( array $found ): string => str_pad( (string) $counter, strlen( $found[1] ), '0', STR_PAD_LEFT ),
			$number
		);

		return $with_counter ?? $number;
	}
}
