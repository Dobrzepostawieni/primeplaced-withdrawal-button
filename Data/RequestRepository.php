<?php
/**
 * Persistence of withdrawal requests, their items and events.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Data;

use PrimePlaced\WithdrawalButton\Domain\Status;
use RuntimeException;

/**
 * Class RequestRepository
 *
 * @phpstan-type RequestRow array{
 *     id: int, number: string, order_id: int, email: string, full_name: string,
 *     status: string, submitted_at_gmt: string, refund_due_gmt: string,
 *     currency: string, estimated_total: string, items_count: int
 * }
 * @phpstan-type NewItem array{order_item_id: int, product_id: int, variation_id: int, name: string, qty: int, unit_total: string, tax: string}
 * @phpstan-type FullRequest array<string, string|int|null>
 * @phpstan-type ItemRow array{id: int, order_item_id: int, product_id: int, variation_id: int, name: string, qty: int, unit_total: string, tax: string}
 */
final class RequestRepository {

	/**
	 * Inserts a request with its items and the "created" event in one transaction.
	 *
	 * @param array<string, string|int|float|null> $request Column values for ppwb_requests.
	 * @param list<NewItem>                        $items   Request items.
	 * @throws RuntimeException When any insert fails; nothing is stored then.
	 * @return int New request ID.
	 */
	public function create( array $request, array $items ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, written once.
		$wpdb->query( 'START TRANSACTION' );

		if ( false === $wpdb->insert( $wpdb->prefix . 'ppwb_requests', $request ) ) {
			$wpdb->query( 'ROLLBACK' );
			throw new RuntimeException( esc_html( 'Could not store the request.' ) );
		}
		$request_id = (int) $wpdb->insert_id;

		foreach ( $items as $item ) {
			if ( false === $wpdb->insert( $wpdb->prefix . 'ppwb_request_items', array( 'request_id' => $request_id ) + $item ) ) {
				$wpdb->query( 'ROLLBACK' );
				throw new RuntimeException( esc_html( 'Could not store the request items.' ) );
			}
		}

		$event = array(
			'request_id'     => $request_id,
			'type'           => 'created',
			'actor_type'     => 'customer',
			'actor_id'       => null,
			'payload'        => wp_json_encode( array( 'status' => Status::New->value ) ),
			'created_at_gmt' => $request['submitted_at_gmt'] ?? current_time( 'mysql', true ),
		);
		if ( false === $wpdb->insert( $wpdb->prefix . 'ppwb_events', $event ) ) {
			$wpdb->query( 'ROLLBACK' );
			throw new RuntimeException( esc_html( 'Could not store the request event.' ) );
		}

		$wpdb->query( 'COMMIT' );
		// phpcs:enable

		return $request_id;
	}

	/**
	 * Takes the per-order lock for storing a request (waits up to 10 s).
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return bool False when another process still holds it.
	 */
	public function lock_order( int $order_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', self::lock_name( $order_id ) ) );
	}

	/**
	 * Releases the per-order lock.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function unlock_order( int $order_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock.
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name( $order_id ) ) );
	}

	/**
	 * Lock name, unique per site table prefix (MySQL allows 64 characters).
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return string
	 */
	private static function lock_name( int $order_id ): string {
		global $wpdb;

		return substr( md5( $wpdb->prefix ), 0, 12 ) . '_ppwb_order_' . $order_id;
	}

	/**
	 * Quantities per order item in requests that are not refunded yet (not
	 * rejected or cancelled either). Refunded requests are already counted by
	 * the WooCommerce refunds, so counting them here would count them twice.
	 *
	 * @param int $order_id          WooCommerce order ID.
	 * @param int $before_request_id Only requests stored before this one; 0 for all.
	 * @return array<int, int> Order item ID => quantity.
	 */
	public function requested_quantities( int $order_id, int $before_request_id = 0 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must reflect the latest requests.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.order_item_id, SUM(i.qty) AS qty FROM %i i JOIN %i r ON r.id = i.request_id
				WHERE r.order_id = %d AND (%d = 0 OR r.id < %d) AND r.status NOT IN (%s, %s) AND (r.wc_refund_ids IS NULL OR r.wc_refund_ids = '') GROUP BY i.order_item_id",
				$wpdb->prefix . 'ppwb_request_items',
				$wpdb->prefix . 'ppwb_requests',
				$order_id,
				$before_request_id,
				$before_request_id,
				Status::Rejected->value,
				Status::Cancelled->value
			),
			ARRAY_A
		);

		$quantities = array();
		foreach ( (array) $rows as $row ) {
			$quantities[ (int) $row['order_item_id'] ] = (int) $row['qty'];
		}

		return $quantities;
	}

	/**
	 * Request number by ID.
	 *
	 * @param int $request_id Request ID.
	 * @return array{number: string, submitted_at_gmt: string, refund_due_gmt: string, email: string, flags: string}|null
	 */
	public function find_summary( int $request_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read right after insert.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT number, submitted_at_gmt, refund_due_gmt, email, flags FROM %i WHERE id = %d', $wpdb->prefix . 'ppwb_requests', $request_id ),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'number'           => (string) $row['number'],
			'submitted_at_gmt' => (string) $row['submitted_at_gmt'],
			'refund_due_gmt'   => (string) $row['refund_due_gmt'],
			'email'            => (string) $row['email'],
			'flags'            => (string) $row['flags'],
		);
	}

	/**
	 * Latest requests for the admin list.
	 *
	 * @param int $limit Maximum rows.
	 * @return list<RequestRow>
	 */
	public function latest( int $limit = 500 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin list must be current.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT r.id, r.number, r.order_id, r.email, r.full_name, r.status, r.submitted_at_gmt, r.refund_due_gmt,
					r.currency, r.estimated_total, COALESCE(SUM(i.qty), 0) AS items_count
				FROM %i r LEFT JOIN %i i ON i.request_id = r.id
				GROUP BY r.id ORDER BY r.submitted_at_gmt DESC, r.id DESC LIMIT %d',
				$wpdb->prefix . 'ppwb_requests',
				$wpdb->prefix . 'ppwb_request_items',
				$limit
			),
			ARRAY_A
		);

		$list = array();
		foreach ( (array) $rows as $row ) {
			$list[] = array(
				'id'               => (int) $row['id'],
				'number'           => (string) $row['number'],
				'order_id'         => (int) $row['order_id'],
				'email'            => (string) $row['email'],
				'full_name'        => (string) $row['full_name'],
				'status'           => (string) $row['status'],
				'submitted_at_gmt' => (string) $row['submitted_at_gmt'],
				'refund_due_gmt'   => (string) $row['refund_due_gmt'],
				'currency'         => (string) $row['currency'],
				'estimated_total'  => (string) $row['estimated_total'],
				'items_count'      => (int) $row['items_count'],
			);
		}

		return $list;
	}

	/**
	 * Number of requests with status "new", for the menu badge.
	 *
	 * @return int
	 */
	public function count_new(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cheap indexed count.
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $wpdb->prefix . 'ppwb_requests', Status::New->value )
		);
	}

	/**
	 * Columns that never change after insert.
	 *
	 * @var list<string>
	 */
	private const IMMUTABLE = array( 'id', 'number', 'order_id', 'statement_text', 'statement_hash', 'submitted_at_gmt', 'created_at_gmt' );

	/**
	 * Full request row.
	 *
	 * @param int $request_id Request ID.
	 * @return FullRequest|null
	 */
	public function find( int $request_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must be current.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'ppwb_requests', $request_id ), ARRAY_A );

		return is_array( $row ) ? $this->normalize( $row ) : null;
	}

	/**
	 * Request by number and email (status page). The email is compared in constant time.
	 *
	 * @param string $number Request number.
	 * @param string $email  Customer email.
	 * @return FullRequest|null
	 */
	public function find_by_number_and_email( string $number, string $email ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must be current.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE number = %s', $wpdb->prefix . 'ppwb_requests', strtoupper( trim( $number ) ) ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}

		$expected = strtolower( trim( (string) $row['email'] ) );

		return hash_equals( $expected, strtolower( trim( $email ) ) ) ? $this->normalize( $row ) : null;
	}

	/**
	 * Requests of one email address or customer, newest first.
	 *
	 * @param string $email       Email (may be empty).
	 * @param int    $customer_id Customer ID (0 to skip).
	 * @return list<FullRequest>
	 */
	public function for_customer( string $email, int $customer_id = 0 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must be current.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE (email = %s AND %s <> \'\') OR (customer_id = %d AND %d > 0) ORDER BY submitted_at_gmt DESC',
				$wpdb->prefix . 'ppwb_requests',
				$email,
				$email,
				$customer_id,
				$customer_id
			),
			ARRAY_A
		);

		return array_values( array_map( array( $this, 'normalize' ), (array) $rows ) );
	}

	/**
	 * Requests of one order, newest first (order box).
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return list<FullRequest>
	 */
	public function for_order( int $order_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must be current.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id DESC', $wpdb->prefix . 'ppwb_requests', $order_id ),
			ARRAY_A
		);

		return array_values( array_map( array( $this, 'normalize' ), (array) $rows ) );
	}

	/**
	 * Items of a request.
	 *
	 * @param int $request_id Request ID.
	 * @return list<ItemRow>
	 */
	public function items( int $request_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must be current.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE request_id = %d ORDER BY id ASC', $wpdb->prefix . 'ppwb_request_items', $request_id ),
			ARRAY_A
		);

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'id'            => (int) $row['id'],
				'order_item_id' => (int) $row['order_item_id'],
				'product_id'    => (int) $row['product_id'],
				'variation_id'  => (int) $row['variation_id'],
				'name'          => (string) $row['name'],
				'qty'           => (int) $row['qty'],
				'unit_total'    => (string) $row['unit_total'],
				'tax'           => (string) $row['tax'],
			);
		}

		return $items;
	}

	/**
	 * Updates mutable columns. Immutable ones are rejected.
	 *
	 * @param int                            $request_id Request ID.
	 * @param array<string, string|int|null> $fields     Column values.
	 * @throws RuntimeException When an immutable column is passed or the update fails.
	 * @return void
	 */
	public function update( int $request_id, array $fields ): void {
		global $wpdb;

		$blocked = array_intersect( array_keys( $fields ), self::IMMUTABLE );
		if ( array() !== $blocked ) {
			throw new RuntimeException( esc_html( 'Immutable request columns: ' . implode( ', ', $blocked ) ) );
		}

		$fields['updated_at_gmt'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		if ( false === $wpdb->update( $wpdb->prefix . 'ppwb_requests', $fields, array( 'id' => $request_id ) ) ) {
			throw new RuntimeException( esc_html( 'Could not update the request.' ) );
		}
	}

	/**
	 * Adds or removes a flag (late, action_required, integration_error).
	 *
	 * @param int    $request_id Request ID.
	 * @param string $flag       Flag name.
	 * @param bool   $on         Whether the flag is set.
	 * @return void
	 */
	public function set_flag( int $request_id, string $flag, bool $on ): void {
		$request = $this->find( $request_id );
		if ( null === $request ) {
			return;
		}

		$flags = array_filter( explode( ',', (string) $request['flags'] ) );
		$flags = $on ? array_unique( array_merge( $flags, array( $flag ) ) ) : array_diff( $flags, array( $flag ) );

		$this->update( $request_id, array( 'flags' => array() === $flags ? null : implode( ',', $flags ) ) );
	}

	/**
	 * GDPR erasure: replaces personal data, keeps number, dates, status and the
	 * statement hash. The only path that may touch the statement text.
	 *
	 * @param int $request_id Request ID.
	 * @return void
	 */
	public function anonymize( int $request_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Erasure on request.
		$wpdb->update(
			$wpdb->prefix . 'ppwb_requests',
			array(
				'email'           => 'deleted-' . $request_id . '@invalid',
				'full_name'       => __( '[deleted]', 'primeplaced-withdrawal-button' ),
				'reason_text'     => null,
				'statement_text'  => __( '[erased on request; see the stored SHA-256 hash]', 'primeplaced-withdrawal-button' ),
				'bank_account'    => null,
				'ip_hash'         => null,
				'user_agent'      => null,
				'tracking_number' => null,
				'pdf_path'        => null,
				'customer_id'     => null,
				'updated_at_gmt'  => current_time( 'mysql', true ),
			),
			array( 'id' => $request_id )
		);
	}

	/**
	 * Whether the request went through the GDPR erasure.
	 *
	 * @param array<string, mixed> $request Request row.
	 * @return bool
	 */
	public static function is_anonymized( array $request ): bool {
		return 'deleted-' . (int) ( $request['id'] ?? 0 ) . '@invalid' === (string) ( $request['email'] ?? '' );
	}

	/**
	 * Open requests whose refund deadline is within the given number of days (or past).
	 *
	 * @param int $days Days ahead.
	 * @return list<FullRequest>
	 */
	public function refund_due_within( int $days ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Daily reminder query.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status IN (%s, %s, %s) AND refund_due_gmt <= %s ORDER BY refund_due_gmt ASC',
				$wpdb->prefix . 'ppwb_requests',
				Status::New->value,
				Status::InTransit->value,
				Status::Received->value,
				gmdate( 'Y-m-d H:i:s', time() + max( 0, $days ) * DAY_IN_SECONDS )
			),
			ARRAY_A
		);

		return array_values( array_map( array( $this, 'normalize' ), (array) $rows ) );
	}

	/**
	 * Casts numeric columns of a raw row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return FullRequest
	 */
	private function normalize( array $row ): array {
		$clean = array();
		foreach ( $row as $key => $value ) {
			$clean[ (string) $key ] = in_array( $key, array( 'id', 'order_id', 'customer_id' ), true ) ? (int) $value : ( null === $value ? null : (string) $value );
		}

		return $clean;
	}
}
