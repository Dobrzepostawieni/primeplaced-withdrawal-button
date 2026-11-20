<?php
/**
 * Withdrawal statement text and its hash.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Domain;

/**
 * Class Statement
 *
 * The rendered text is stored once with its SHA-256 hash and never changes
 * afterwards.
 */
final class Statement {

	/**
	 * Fills the statement template.
	 *
	 * @param string               $template Template with {placeholders}.
	 * @param array<string,string> $values   Values keyed by placeholder name without braces.
	 * @return string
	 */
	public static function render( string $template, array $values ): string {
		$pairs = array();
		foreach ( $values as $name => $value ) {
			$pairs[ '{' . $name . '}' ] = $value;
		}

		return strtr( $template, $pairs );
	}

	/**
	 * SHA-256 of the exact stored text.
	 *
	 * @param string $text Statement text.
	 * @return string
	 */
	public static function hash( string $text ): string {
		return hash( 'sha256', $text );
	}
}
