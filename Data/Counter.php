<?php
/**
 * Atomic request counter.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Data;

use RuntimeException;

/**
 * Class Counter
 */
final class Counter {

	/**
	 * Increments the counter for a scope in one statement and returns the new value.
	 *
	 * Two requests in the same second get different values; the unique index on
	 * ppwb_requests.number is the second line of defence.
	 *
	 * @param string $scope 'global' or a year such as '2026'.
	 * @param int    $start Value of the first number in a new scope.
	 * @throws RuntimeException When the database rejects the increment.
	 * @return int
	 */
	public function next( string $scope, int $start ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'ppwb_counters';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic increment must hit the database.
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (scope, value) VALUES (%s, LAST_INSERT_ID(%d)) ON DUPLICATE KEY UPDATE value = LAST_INSERT_ID(value + 1)',
				$table,
				$scope,
				max( 1, $start )
			)
		);

		if ( false === $result ) {
			throw new RuntimeException( esc_html( 'Could not increment the request counter.' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Same connection as the increment.
		return (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
	}

	/**
	 * Value the next request will get, without taking it (settings preview).
	 *
	 * @param string $scope Counter scope.
	 * @param int    $start First value of a new counter.
	 * @return int
	 */
	public function peek( string $scope, int $start ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Preview of the live counter.
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT value FROM %i WHERE scope = %s', $wpdb->prefix . 'ppwb_counters', $scope ) );

		return null === $value ? max( 1, $start ) : (int) $value + 1;
	}
}
