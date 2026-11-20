<?php
/**
 * Append-only request history.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Data;

/**
 * Class EventLog
 *
 * The plugin only inserts events; there is no update or delete path.
 *
 * @phpstan-type EventRow array{id: int, type: string, actor_type: string, actor_id: int|null, payload: array<string, mixed>, created_at_gmt: string}
 */
final class EventLog {

	public const ACTOR_SYSTEM   = 'system';
	public const ACTOR_CUSTOMER = 'customer';
	public const ACTOR_USER     = 'user';

	/**
	 * Appends one event.
	 *
	 * @param int                  $request_id Request ID.
	 * @param string               $type       Event type, e.g. "status_changed".
	 * @param array<string, mixed> $payload    Event data.
	 * @param string               $actor_type system, customer or user.
	 * @param int|null             $actor_id   User ID for actor "user".
	 * @return void
	 */
	public function add( int $request_id, string $type, array $payload = array(), string $actor_type = self::ACTOR_SYSTEM, ?int $actor_id = null ): void {
		global $wpdb;

		if ( self::ACTOR_USER === $actor_type && null === $actor_id ) {
			$actor_id = get_current_user_id() > 0 ? get_current_user_id() : null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Append-only custom table.
		$wpdb->insert(
			$wpdb->prefix . 'ppwb_events',
			array(
				'request_id'     => $request_id,
				'type'           => $type,
				'actor_type'     => $actor_type,
				'actor_id'       => $actor_id,
				'payload'        => wp_json_encode( $payload ),
				'created_at_gmt' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Free text and personal data kept in event payloads.
	 */
	private const PERSONAL_KEYS = array( 'order_email', 'text', 'message', 'reason', 'file', 'tracking', 'tracking_number', 'error' );

	/**
	 * GDPR erasure: removes personal data and free text from the payloads of a
	 * request's events. The events themselves (type, actor, time) stay, so the
	 * history still shows what happened. The only path that changes stored
	 * events.
	 *
	 * @param int $request_id Request ID.
	 * @return void
	 */
	public function redact( int $request_id ): void {
		global $wpdb;

		foreach ( $this->for_request( $request_id ) as $event ) {
			$payload = array_diff_key( $event['payload'], array_flip( self::PERSONAL_KEYS ) );
			if ( count( $payload ) === count( $event['payload'] ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Erasure on request.
			$wpdb->update( $wpdb->prefix . 'ppwb_events', array( 'payload' => wp_json_encode( $payload ) ), array( 'id' => $event['id'] ) );
		}
	}

	/**
	 * Events of a request, oldest first.
	 *
	 * @param int $request_id Request ID.
	 * @return list<EventRow>
	 */
	public function for_request( int $request_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- History must be current.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, type, actor_type, actor_id, payload, created_at_gmt FROM %i WHERE request_id = %d ORDER BY id ASC', $wpdb->prefix . 'ppwb_events', $request_id ),
			ARRAY_A
		);

		$events = array();
		foreach ( (array) $rows as $row ) {
			$payload  = json_decode( (string) $row['payload'], true );
			$events[] = array(
				'id'             => (int) $row['id'],
				'type'           => (string) $row['type'],
				'actor_type'     => (string) $row['actor_type'],
				'actor_id'       => null === $row['actor_id'] ? null : (int) $row['actor_id'],
				'payload'        => is_array( $payload ) ? $payload : array(),
				'created_at_gmt' => (string) $row['created_at_gmt'],
			);
		}

		return $events;
	}
}
