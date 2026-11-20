<?php
/**
 * Filtered, paginated request list for the admin screen.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Data;

use PrimePlaced\WithdrawalButton\Domain\Status;

/**
 * Class RequestListQuery
 *
 * List filtering lives here, not in RequestRepository, so the repository
 * stays small (AGENTS.md, engineering conventions).
 *
 * @phpstan-type ListRow array{id: int, number: string, order_id: int, email: string, full_name: string, status: string, flags: string, submitted_at_gmt: string, refund_due_gmt: string, currency: string, estimated_total: string}
 * @phpstan-type ExportRow array{id: int, number: string, order_id: int, email: string, full_name: string, status: string, flags: string, reason_code: string, reason_text: string, submitted_at_gmt: string, refund_due_gmt: string, currency: string, estimated_total: string, refunded_total: string, tracking_number: string, tracking_carrier: string, created_via: string}
 */
final class RequestListQuery {

	private const SORTABLE = array( 'number', 'submitted_at_gmt', 'refund_due_gmt' );

	/**
	 * Runs the query.
	 *
	 * @param string $search   Request number, email, name or order ID.
	 * @param string $status   Status key or '' for all.
	 * @param int    $page     1-based page.
	 * @param int    $per_page Rows per page.
	 * @param string $orderby  Column, one of SORTABLE.
	 * @param string $order    'asc' or 'desc'.
	 * @param string $flag     '' for all, a request flag (action_required, which also matches integration_error; late, before_shipment) or due_soon (refund due within 3 days).
	 * @return array{rows: list<ListRow>, total: int}
	 */
	public function run( string $search, string $status, int $page, int $per_page, string $orderby, string $order, string $flag = '' ): array {
		global $wpdb;

		$table   = $wpdb->prefix . 'ppwb_requests';
		$orderby = in_array( $orderby, self::SORTABLE, true ) ? $orderby : 'submitted_at_gmt';
		$status  = null !== Status::tryFrom( $status ) ? $status : '';
		$search  = trim( $search );
		$like    = '%' . $wpdb->esc_like( $search ) . '%';

		// The SQL text is fixed: empty filters switch themselves off with "%s = ''".
		$flag    = in_array( $flag, self::FLAGS, true ) ? $flag : '';
		$soon    = gmdate( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS );
		$filters = array( $status, $status, $search, $like, $like, $like, absint( ltrim( $search, '#' ) ), $flag, $flag, $soon, $flag, $flag );
		$paging  = array( max( 1, $per_page ), max( 0, ( $page - 1 ) * $per_page ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Admin list must be current; replacements are passed as one array.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE (%s = '' OR status = %s) AND (%s = '' OR number LIKE %s OR email LIKE %s OR full_name LIKE %s OR order_id = %d) AND (%s = '' OR (%s = 'due_soon' AND status IN ('new', 'in_transit', 'received') AND refund_due_gmt <= %s) OR FIND_IN_SET(%s, COALESCE(flags, '')) > 0 OR (%s = 'action_required' AND FIND_IN_SET('integration_error', COALESCE(flags, '')) > 0))",
				array_merge( array( $table ), $filters )
			)
		);

		$args = array_merge( array( $table ), $filters, array( $orderby ), $paging );
		$rows = 'asc' === strtolower( $order )
			? $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, number, order_id, email, full_name, status, flags, submitted_at_gmt, refund_due_gmt, currency, estimated_total FROM %i WHERE (%s = '' OR status = %s) AND (%s = '' OR number LIKE %s OR email LIKE %s OR full_name LIKE %s OR order_id = %d) AND (%s = '' OR (%s = 'due_soon' AND status IN ('new', 'in_transit', 'received') AND refund_due_gmt <= %s) OR FIND_IN_SET(%s, COALESCE(flags, '')) > 0 OR (%s = 'action_required' AND FIND_IN_SET('integration_error', COALESCE(flags, '')) > 0)) ORDER BY %i ASC, id ASC LIMIT %d OFFSET %d",
					$args
				),
				ARRAY_A
			)
			: $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, number, order_id, email, full_name, status, flags, submitted_at_gmt, refund_due_gmt, currency, estimated_total FROM %i WHERE (%s = '' OR status = %s) AND (%s = '' OR number LIKE %s OR email LIKE %s OR full_name LIKE %s OR order_id = %d) AND (%s = '' OR (%s = 'due_soon' AND status IN ('new', 'in_transit', 'received') AND refund_due_gmt <= %s) OR FIND_IN_SET(%s, COALESCE(flags, '')) > 0 OR (%s = 'action_required' AND FIND_IN_SET('integration_error', COALESCE(flags, '')) > 0)) ORDER BY %i DESC, id DESC LIMIT %d OFFSET %d",
					$args
				),
				ARRAY_A
			);
		// phpcs:enable

		$list = array();
		foreach ( (array) $rows as $row ) {
			$list[] = array(
				'id'               => (int) $row['id'],
				'number'           => (string) $row['number'],
				'order_id'         => (int) $row['order_id'],
				'email'            => (string) $row['email'],
				'full_name'        => (string) $row['full_name'],
				'status'           => (string) $row['status'],
				'flags'            => (string) $row['flags'],
				'submitted_at_gmt' => (string) $row['submitted_at_gmt'],
				'refund_due_gmt'   => (string) $row['refund_due_gmt'],
				'currency'         => (string) $row['currency'],
				'estimated_total'  => (string) $row['estimated_total'],
			);
		}

		return array(
			'rows'  => $list,
			'total' => $total,
		);
	}

	/**
	 * Rows for exports, oldest first, read in batches after the last seen ID.
	 *
	 * Uses the list filters plus an optional earliest submission date. Bank
	 * accounts, IP hashes and statements are never selected.
	 *
	 * @param string $search   Request number, email, name or order ID.
	 * @param string $status   Status key or '' for all.
	 * @param string $from_gmt Earliest submission (Y-m-d H:i:s, UTC) or ''.
	 * @param int    $after_id Last ID of the previous batch, 0 for the first.
	 * @param int    $limit    Batch size.
	 * @return list<ExportRow>
	 */
	public function batch( string $search, string $status, string $from_gmt, int $after_id, int $limit ): array {
		global $wpdb;

		$status = null !== Status::tryFrom( $status ) ? $status : '';
		$search = trim( $search );
		$like   = '%' . $wpdb->esc_like( $search ) . '%';
		// MySQL in strict mode rejects comparing a DATETIME with '', so no date means the earliest one.
		$from_gmt = 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $from_gmt ) ? $from_gmt : '1000-01-01 00:00:00';
		$args     = array( $wpdb->prefix . 'ppwb_requests', max( 0, $after_id ), $status, $status, $search, $like, $like, $like, absint( ltrim( $search, '#' ) ), $from_gmt, max( 1, $limit ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Export must be current; replacements are passed as one array.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, number, order_id, email, full_name, status, flags, reason_code, reason_text, submitted_at_gmt, refund_due_gmt, currency, estimated_total, refunded_total, tracking_number, tracking_carrier, created_via FROM %i WHERE id > %d AND (%s = '' OR status = %s) AND (%s = '' OR number LIKE %s OR email LIKE %s OR full_name LIKE %s OR order_id = %d) AND submitted_at_gmt >= %s ORDER BY id ASC LIMIT %d",
				$args
			),
			ARRAY_A
		);
		// phpcs:enable

		$list = array();
		foreach ( (array) $rows as $row ) {
			$list[] = array(
				'id'               => (int) $row['id'],
				'number'           => (string) $row['number'],
				'order_id'         => (int) $row['order_id'],
				'email'            => (string) $row['email'],
				'full_name'        => (string) $row['full_name'],
				'status'           => (string) $row['status'],
				'flags'            => (string) $row['flags'],
				'reason_code'      => (string) $row['reason_code'],
				'reason_text'      => (string) $row['reason_text'],
				'submitted_at_gmt' => (string) $row['submitted_at_gmt'],
				'refund_due_gmt'   => (string) $row['refund_due_gmt'],
				'currency'         => (string) $row['currency'],
				'estimated_total'  => (string) $row['estimated_total'],
				'refunded_total'   => (string) $row['refunded_total'],
				'tracking_number'  => (string) $row['tracking_number'],
				'tracking_carrier' => (string) $row['tracking_carrier'],
				'created_via'      => (string) $row['created_via'],
			);
		}

		return $list;
	}

	/**
	 * Flag filters of the list.
	 */
	public const FLAGS = array( 'action_required', 'late', 'due_soon', 'before_shipment' );

	/**
	 * Number of requests per flag filter.
	 *
	 * @return array<string, int>
	 */
	public function counts_by_flag(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- List views.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(FIND_IN_SET('action_required', COALESCE(flags, '')) > 0 OR FIND_IN_SET('integration_error', COALESCE(flags, '')) > 0) AS action_required, SUM(FIND_IN_SET('late', COALESCE(flags, '')) > 0) AS late, SUM(FIND_IN_SET('before_shipment', COALESCE(flags, '')) > 0) AS before_shipment, SUM(status IN ('new', 'in_transit', 'received') AND refund_due_gmt <= %s) AS due_soon FROM %i",
				gmdate( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS ),
				$wpdb->prefix . 'ppwb_requests'
			),
			ARRAY_A
		);

		$counts = array();
		foreach ( self::FLAGS as $flag ) {
			$counts[ $flag ] = (int) ( is_array( $row ) ? ( $row[ $flag ] ?? 0 ) : 0 );
		}

		return $counts;
	}

	/**
	 * Product lines of several requests, for the list column.
	 *
	 * @param list<int> $ids Request IDs.
	 * @return array<int, string> Request ID => "Name x2, Name x1".
	 */
	public function products_for( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( array() === $ids ) {
			return array();
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The IN list holds only %d placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT request_id, name, qty FROM %i WHERE request_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY id ASC',
				array_merge( array( $wpdb->prefix . 'ppwb_request_items' ), $ids )
			),
			ARRAY_A
		);
		// phpcs:enable

		$lines = array();
		foreach ( (array) $rows as $row ) {
			$lines[ (int) $row['request_id'] ][] = $row['name'] . ' x' . (int) $row['qty'];
		}

		return array_map( static fn ( array $names ): string => implode( ', ', $names ), $lines );
	}

	/**
	 * Number of requests per status.
	 *
	 * @return array<string, int>
	 */
	public function counts_by_status(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed GROUP BY for list views.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i GROUP BY status', $wpdb->prefix . 'ppwb_requests' ),
			ARRAY_A
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}
}
