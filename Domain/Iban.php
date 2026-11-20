<?php
/**
 * IBAN validation (refund account for cash on delivery).
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Domain;

/**
 * Class Iban
 */
final class Iban {

	/**
	 * Normalizes an account number: spaces removed, upper case, and the PL
	 * prefix added to a 26-digit Polish NRB.
	 *
	 * @param string $value Raw input.
	 * @return string
	 */
	public static function normalize( string $value ): string {
		$clean = strtoupper( preg_replace( '/[\s-]+/', '', $value ) ?? '' );

		return 1 === preg_match( '/^\d{26}$/', $clean ) ? 'PL' . $clean : $clean;
	}

	/**
	 * Whether the IBAN checksum (ISO 13616, mod 97) is valid.
	 *
	 * @param string $value Raw or normalized value.
	 * @return bool
	 */
	public static function is_valid( string $value ): bool {
		$iban = self::normalize( $value );
		if ( 1 !== preg_match( '/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban ) ) {
			return false;
		}

		$moved     = substr( $iban, 4 ) . substr( $iban, 0, 4 );
		$remainder = 0;
		foreach ( str_split( $moved ) as $char ) {
			$digits = ctype_alpha( $char ) ? (string) ( ord( $char ) - 55 ) : $char;
			foreach ( str_split( $digits ) as $digit ) {
				$remainder = ( $remainder * 10 + (int) $digit ) % 97;
			}
		}

		return 1 === $remainder;
	}

	/**
	 * Groups of four characters for reading, e.g. "PL61 1090 1014 ...".
	 *
	 * @param string $value Account number.
	 * @return string
	 */
	public static function grouped( string $value ): string {
		return trim( chunk_split( self::normalize( $value ), 4, ' ' ) );
	}
}
