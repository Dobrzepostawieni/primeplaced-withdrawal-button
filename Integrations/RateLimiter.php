<?php
/**
 * Fixed-window rate limiter backed by transients.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Integrations;

/**
 * Class RateLimiter
 *
 * A window has a fixed end: recording a hit rewrites the transient with the
 * remaining time only, so it never extends the window.
 */
final class RateLimiter {

	/**
	 * Whether the key has used up its attempts in the current window.
	 *
	 * @param string $key      Limiter key, already hashed.
	 * @param int    $max_hits Allowed hits per window.
	 * @return bool
	 */
	public function is_blocked( string $key, int $max_hits ): bool {
		$state = $this->state( $key );

		return null !== $state && $state['hits'] >= $max_hits;
	}

	/**
	 * Records one hit.
	 *
	 * @param string $key    Limiter key, already hashed.
	 * @param int    $window Window length in seconds.
	 * @return void
	 */
	public function hit( string $key, int $window ): void {
		$now   = time();
		$state = $this->state( $key ) ?? array(
			'hits' => 0,
			'ends' => $now + $window,
		);

		++$state['hits'];
		set_transient( $this->transient( $key ), $state, max( 1, $state['ends'] - $now ) );
	}

	/**
	 * Current window state, or null when there is no active window.
	 *
	 * @param string $key Limiter key.
	 * @return array{hits: int, ends: int}|null
	 */
	private function state( string $key ): ?array {
		$state = get_transient( $this->transient( $key ) );
		if ( ! is_array( $state ) || ! isset( $state['hits'], $state['ends'] ) || (int) $state['ends'] <= time() ) {
			return null;
		}

		return array(
			'hits' => (int) $state['hits'],
			'ends' => (int) $state['ends'],
		);
	}

	/**
	 * Transient name for a key.
	 *
	 * @param string $key Limiter key.
	 * @return string
	 */
	private function transient( string $key ): string {
		// Keys carry the client IP: salted, so the transient name cannot be turned back into it.
		return 'ppwb_rl_' . substr( hash_hmac( 'sha256', $key, wp_salt( 'nonce' ) ), 0, 32 );
	}
}
