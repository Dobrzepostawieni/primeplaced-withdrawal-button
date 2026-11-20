<?php
/**
 * Encryption of the bank account number.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Integrations;

/**
 * Class Crypto
 *
 * Uses sodium_crypto_secretbox with a key derived from the site salts. The value
 * is stored as "v1:" + base64(nonce . ciphertext).
 */
final class Crypto {

	/**
	 * Encrypts a value.
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	public function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $this->key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary transport of ciphertext.
	}

	/**
	 * Decrypts a value, or returns '' when it cannot be read.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public function decrypt( string $stored ): string {
		if ( ! str_starts_with( $stored, 'v1:' ) ) {
			return '';
		}

		$raw = base64_decode( substr( $stored, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary transport of ciphertext.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$this->key()
		);

		return false === $plain ? '' : $plain;
	}

	/**
	 * Masks an account number, keeping the last four digits.
	 *
	 * @param string $plain Account number.
	 * @return string
	 */
	public static function mask( string $plain ): string {
		$digits = preg_replace( '/\s+/', '', $plain ) ?? '';

		return '' === $digits ? '' : str_repeat( '*', max( 0, strlen( $digits ) - 4 ) ) . substr( $digits, -4 );
	}

	/**
	 * 32-byte key derived from wp_salt().
	 *
	 * @return string
	 */
	private function key(): string {
		return hash( 'sha256', 'ppwb-bank-account|' . wp_salt( 'secure_auth' ), true );
	}
}
