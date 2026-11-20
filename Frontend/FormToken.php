<?php
/**
 * Signed token that carries a verified order between form steps.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Frontend;

/**
 * Class FormToken
 *
 * The form keeps no server session: after step 1 the order ID and email are
 * signed with an HMAC and expire after 30 minutes.
 */
final class FormToken {

	private const TTL = 1800;

	/**
	 * Issues a token for a verified order.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $email    Verified billing email.
	 * @return string
	 */
	public function issue( int $order_id, string $email ): string {
		$payload = $this->encode( (string) wp_json_encode( array( $order_id, strtolower( $email ), time() + self::TTL ) ) );

		return $payload . '.' . $this->sign( $payload );
	}

	/**
	 * Verifies a token.
	 *
	 * @param string $token Token from the form.
	 * @return array{order_id: int, email: string}|null
	 */
	public function verify( string $token ): ?array {
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) || ! hash_equals( $this->sign( $parts[0] ), $parts[1] ) ) {
			return null;
		}

		$data = json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ), true ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Token transport, not obfuscation.
		if ( ! is_array( $data ) || 3 !== count( $data ) || (int) $data[2] < time() ) {
			return null;
		}

		return array(
			'order_id' => (int) $data[0],
			'email'    => (string) $data[1],
		);
	}

	/**
	 * Signs an arbitrary value, for example a request ID in the success URL.
	 *
	 * @param string $value Value to sign.
	 * @return string
	 */
	public function sign( string $value ): string {
		return substr( hash_hmac( 'sha256', $value, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * URL-safe base64.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Token transport, not obfuscation.
	}
}
