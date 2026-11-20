<?php
/**
 * Money in integer minor units.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Domain;

/**
 * Class Money
 *
 * Amounts are parsed from WooCommerce decimal strings without going through
 * floats, and shares are rounded half up in integer arithmetic.
 */
final class Money {

	/**
	 * Parses a decimal string such as "349.00" or "-12.5" into minor units.
	 *
	 * @param string $amount   Decimal string.
	 * @param int    $decimals Currency decimals (2 for PLN, EUR).
	 * @return int
	 */
	public static function to_minor( string $amount, int $decimals = 2 ): int {
		$amount   = trim( $amount );
		$negative = str_starts_with( $amount, '-' );
		$parts    = explode( '.', ltrim( $amount, '+-' ), 2 );
		$whole    = preg_replace( '/\D/', '', $parts[0] ) ?? '';
		$fraction = preg_replace( '/\D/', '', $parts[1] ?? '' ) ?? '';

		$kept  = substr( str_pad( $fraction, $decimals, '0' ), 0, $decimals );
		$minor = (int) ( ( '' === $whole ? '0' : $whole ) . $kept );

		// Round half up on the first dropped digit.
		$next = $fraction[ $decimals ] ?? '0';
		if ( (int) $next >= 5 ) {
			++$minor;
		}

		return $negative ? -$minor : $minor;
	}

	/**
	 * Formats minor units as a decimal string with the given precision.
	 *
	 * @param int $minor    Amount in minor units.
	 * @param int $decimals Currency decimals.
	 * @return string
	 */
	public static function to_decimal( int $minor, int $decimals = 2 ): string {
		$sign   = $minor < 0 ? '-' : '';
		$digits = str_pad( (string) abs( $minor ), $decimals + 1, '0', STR_PAD_LEFT );

		if ( 0 === $decimals ) {
			return $sign . $digits;
		}

		return $sign . substr( $digits, 0, -$decimals ) . '.' . substr( $digits, -$decimals );
	}

	/**
	 * Share of a line amount for part of its quantity, rounded half up.
	 *
	 * @param int $line_minor Amount for the whole line.
	 * @param int $part_qty   Quantity taken.
	 * @param int $line_qty   Quantity on the line (> 0).
	 * @return int
	 */
	public static function share( int $line_minor, int $part_qty, int $line_qty ): int {
		if ( $line_qty <= 0 ) {
			return 0;
		}

		return intdiv( 2 * $line_minor * $part_qty + $line_qty, 2 * $line_qty );
	}
}
