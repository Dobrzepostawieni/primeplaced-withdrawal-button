<?php
/**
 * WooCommerce order side: optional "Withdrawal" order status, auto-accept and
 * order search by request number.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Integrations;

use PrimePlaced\WithdrawalButton\Data\Settings;
use PrimePlaced\WithdrawalButton\Engine\Module;
use WC_Order;

/**
 * Class OrderIntegration
 */
final class OrderIntegration implements Module {

	public const ORDER_STATUS = 'wc-withdrawal';

	/**
	 * Creates the module.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct(
		private Settings $settings
	) {
	}

	/**
	 * Adds the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_register_shop_order_post_statuses', array( $this, 'register_status' ) );
		add_filter( 'wc_order_statuses', array( $this, 'add_status' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( $this, 'keep_paid' ) );
		add_action( 'ppwb_request_created', array( $this, 'on_created' ), 20, 2 );
		add_filter( 'woocommerce_shop_order_search_results', array( $this, 'search_legacy' ), 10, 2 );
		add_filter( 'woocommerce_hpos_admin_search_filters', array( $this, 'search_filter' ) );
		add_filter( 'woocommerce_hpos_generate_where_for_search_filter', array( $this, 'search_where' ), 10, 4 );
	}

	/**
	 * Registers the order status (HPOS and legacy).
	 *
	 * @param array<string, array<string, mixed>> $statuses Order statuses.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_status( array $statuses ): array {
		$statuses[ self::ORDER_STATUS ] = array(
			'label'                     => _x( 'Withdrawal from contract', 'Order status', 'primeplaced-withdrawal-button' ),
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of orders. */
			'label_count'               => _n_noop( 'Withdrawal from contract <span class="count">(%s)</span>', 'Withdrawal from contract <span class="count">(%s)</span>', 'primeplaced-withdrawal-button' ),
		);

		return $statuses;
	}

	/**
	 * Adds the status to the order status list.
	 *
	 * @param array<string, string> $statuses Order statuses.
	 * @return array<string, string>
	 */
	public function add_status( array $statuses ): array {
		$statuses[ self::ORDER_STATUS ] = _x( 'Withdrawal from contract', 'Order status', 'primeplaced-withdrawal-button' );

		return $statuses;
	}

	/**
	 * Lets payment completion work for orders in the withdrawal status.
	 *
	 * @param array<int, string> $statuses Statuses.
	 * @return array<int, string>
	 */
	public function keep_paid( array $statuses ): array {
		$statuses[] = 'withdrawal';

		return $statuses;
	}

	/**
	 * Optional order status change after a new request.
	 *
	 * @param int      $request_id Request ID.
	 * @param WC_Order $order      Order.
	 * @return void
	 */
	public function on_created( int $request_id, WC_Order $order ): void {
		if ( $this->settings->flag( 'set_order_status' ) ) {
			$order->update_status( 'withdrawal' );
		}
		unset( $request_id );
	}

	/**
	 * Legacy orders list: search by request number.
	 *
	 * @param array<int, int> $order_ids Found orders.
	 * @param string          $term      Search term.
	 * @return array<int, int>
	 */
	public function search_legacy( array $order_ids, string $term ): array {
		return array_values( array_unique( array_merge( $order_ids, $this->order_ids_for_number( $term ) ) ) );
	}

	/**
	 * HPOS orders list: adds "Withdrawal request" to the search filter.
	 *
	 * @param array<string, string> $filters Search filters.
	 * @return array<string, string>
	 */
	public function search_filter( array $filters ): array {
		$filters['ppwb_request'] = __( 'Withdrawal request', 'primeplaced-withdrawal-button' );

		return $filters;
	}

	/**
	 * HPOS search clause for the request filter.
	 *
	 * @param string $where  Current clause.
	 * @param string $term   Search term.
	 * @param string $filter Filter key.
	 * @param mixed  $query  Order query.
	 * @return string
	 */
	public function search_where( string $where, string $term, string $filter, mixed $query ): string {
		unset( $query );
		if ( 'ppwb_request' !== $filter ) {
			return $where;
		}

		global $wpdb;
		$ids = $this->order_ids_for_number( $term );

		return array() === $ids ? '1=0' : $wpdb->prefix . 'wc_orders.id IN (' . implode( ',', array_map( 'absint', $ids ) ) . ')';
	}

	/**
	 * Order IDs whose requests match a number fragment.
	 *
	 * @param string $term Search term.
	 * @return list<int>
	 */
	private function order_ids_for_number( string $term ): array {
		global $wpdb;

		$term = trim( $term );
		if ( strlen( $term ) < 3 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin search.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT order_id FROM %i WHERE number LIKE %s', $wpdb->prefix . 'ppwb_requests', '%' . $wpdb->esc_like( $term ) . '%' ) );

		return array_map( 'intval', (array) $ids );
	}
}
