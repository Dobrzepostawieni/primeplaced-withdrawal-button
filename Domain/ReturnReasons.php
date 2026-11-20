<?php
/**
 * Return reasons the customer can pick.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Domain;

/**
 * Class ReturnReasons
 *
 * Parses the shop's list: one reason per line, optionally "code|Label". The
 * code is what requests store, so renaming a label keeps the statistics.
 */
final class ReturnReasons {

	public const OTHER = 'other';

	public const MAX_REASONS = 20;

	public const MAX_CODE_LENGTH = 64;

	public const MAX_LABEL_LENGTH = 100;

	/**
	 * Parses the list into code => label pairs, in the shop's order.
	 *
	 * Empty lines, duplicate codes and the reserved "other" code are skipped;
	 * the list is capped at MAX_REASONS.
	 *
	 * @param string                   $raw     Raw list, one reason per line.
	 * @param callable(string): string $slugify Turns a label into a code.
	 * @return array<string, string>
	 */
	public static function parse( string $raw, callable $slugify ): array {
		$reasons = array();
		$lines   = preg_split( '/\R/u', $raw );
		foreach ( false === $lines ? array() : $lines as $line ) {
			$parts = explode( '|', $line, 2 );
			$label = trim( 2 === count( $parts ) ? $parts[1] : $parts[0] );
			$code  = 2 === count( $parts ) ? trim( $parts[0] ) : '';
			$code  = mb_substr( $slugify( '' === $code ? $label : $code ), 0, self::MAX_CODE_LENGTH );

			if ( '' === $label || '' === $code || self::OTHER === $code || isset( $reasons[ $code ] ) ) {
				continue;
			}

			$reasons[ $code ] = mb_substr( $label, 0, self::MAX_LABEL_LENGTH );
			if ( count( $reasons ) >= self::MAX_REASONS ) {
				break;
			}
		}

		return $reasons;
	}

	/**
	 * Whether a posted code may be stored: one from the list or "other".
	 *
	 * @param string                $code    Posted code.
	 * @param array<string, string> $reasons Parsed list.
	 * @return bool
	 */
	public static function is_valid( string $code, array $reasons ): bool {
		return self::OTHER === $code || isset( $reasons[ $code ] );
	}
}
