<?php
/**
 * Withdrawal requests list in the style of the WooCommerce orders list.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Backend;

use PrimePlaced\WithdrawalButton\Data\RequestListQuery;
use PrimePlaced\WithdrawalButton\Domain\Money;
use PrimePlaced\WithdrawalButton\Domain\Status;
use PrimePlaced\WithdrawalButton\Integrations\Dates;
use PrimePlaced\WithdrawalButton\Integrations\WithdrawalService;
use WP_List_Table;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class RequestsListTable
 *
 * @phpstan-import-type ListRow from RequestListQuery
 */
final class RequestsListTable extends WP_List_Table {

	public const PER_PAGE_OPTION = 'ppwb_requests_per_page';

	/**
	 * Product lines of the rows on this page.
	 *
	 * @var array<int, string>
	 */
	private array $products = array();

	/**
	 * Creates the table.
	 *
	 * @param RequestListQuery $query List query.
	 */
	public function __construct( private RequestListQuery $query ) {
		parent::__construct(
			array(
				'singular' => 'ppwb-request',
				'plural'   => 'ppwb-requests',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns, named like the WooCommerce orders list.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'         => '<input type="checkbox">',
			'number'     => __( 'Request', 'primeplaced-withdrawal-button' ),
			'submitted'  => __( 'Date', 'primeplaced-withdrawal-button' ),
			'status'     => __( 'Status', 'primeplaced-withdrawal-button' ),
			'order'      => __( 'Order', 'primeplaced-withdrawal-button' ),
			'products'   => __( 'Products', 'primeplaced-withdrawal-button' ),
			'total'      => __( 'Estimated refund', 'primeplaced-withdrawal-button' ),
			'refund_due' => __( 'Refund due', 'primeplaced-withdrawal-button' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'number'     => array( 'number', false ),
			'submitted'  => array( 'submitted_at_gmt', true ),
			'refund_due' => array( 'refund_due_gmt', false ),
		);
	}

	/**
	 * Loads the current page of rows.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( self::PER_PAGE_OPTION, 20 );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters, as in WooCommerce lists.
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$status  = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'submitted_at_gmt';
		$order   = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'desc';
		$flag    = isset( $_REQUEST['flag'] ) ? sanitize_key( wp_unslash( $_REQUEST['flag'] ) ) : '';
		// phpcs:enable

		$result = $this->query->run( $search, $status, $this->get_pagenum(), $per_page, $orderby, $order, $flag );

		$this->items           = $result['rows'];
		$this->products        = $this->query->products_for( array_column( $result['rows'], 'id' ) );
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'number' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Status views ("All | New (2) | ...") like the orders list.
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$counts = $this->query->counts_by_status();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch.
		$current = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';
		$base    = admin_url( 'admin.php?page=' . AdminPage::SLUG );

		$views = array(
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch.
			'all' => $this->view_link( $base, __( 'All', 'primeplaced-withdrawal-button' ), array_sum( $counts ), '' === $current && empty( $_REQUEST['flag'] ) ),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch.
		$flag   = isset( $_REQUEST['flag'] ) ? sanitize_key( wp_unslash( $_REQUEST['flag'] ) ) : '';
		$labels = array(
			'action_required' => __( 'Action required', 'primeplaced-withdrawal-button' ),
			'late'            => __( 'Late', 'primeplaced-withdrawal-button' ),
			'due_soon'        => __( 'Refund due within 3 days', 'primeplaced-withdrawal-button' ),
			'before_shipment' => __( 'Cancellation before shipment', 'primeplaced-withdrawal-button' ),
		);
		foreach ( $this->query->counts_by_flag() as $key => $count ) {
			if ( $count > 0 ) {
				$views[ 'flag-' . $key ] = $this->view_link( add_query_arg( 'flag', $key, $base ), $labels[ $key ] ?? $key, $count, $key === $flag );
			}
		}

		foreach ( Status::cases() as $status ) {
			if ( empty( $counts[ $status->value ] ) ) {
				continue;
			}
			$views[ $status->value ] = $this->view_link(
				add_query_arg( 'status', $status->value, $base ),
				StatusView::label( $status ),
				$counts[ $status->value ],
				$status->value === $current
			);
		}

		return $views;
	}

	/**
	 * Message for an empty list, worded like WooCommerce's blank state.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No withdrawal requests found. They appear here as soon as a customer confirms the form.', 'primeplaced-withdrawal-button' );
	}

	/**
	 * Request number with customer, like "#123 Anna Kowalska" in orders.
	 *
	 * @param ListRow $item Row.
	 * @return string
	 */
	protected function column_number( $item ): string {
		$flags           = explode( ',', $item['flags'] );
		$before_shipment = in_array( WithdrawalService::BEFORE_SHIPMENT, $flags, true );
		$attention       = in_array( 'action_required', $flags, true ) || in_array( 'integration_error', $flags, true );

		return sprintf(
			'<a class="row-title" href="%4$s"><strong>%1$s %2$s</strong></a><span class="ppwb-email">%3$s</span>%5$s%6$s',
			esc_html( $item['number'] ),
			esc_html( $item['full_name'] ),
			esc_html( $item['email'] ),
			esc_url( RequestScreen::url( $item['id'] ) ),
			$before_shipment ? '<span class="ppwb-flag">' . esc_html__( 'Cancellation before shipment', 'primeplaced-withdrawal-button' ) . '</span>' : '',
			$attention ? '<span class="ppwb-flag">' . esc_html__( 'Action required', 'primeplaced-withdrawal-button' ) . '</span>' : ''
		);
	}

	/**
	 * Row checkbox.
	 *
	 * @param ListRow $item Row.
	 * @return string
	 */
	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="request_ids[]" value="%d">', (int) $item['id'] );
	}

	/**
	 * Bulk actions, like "Change status to ..." in the orders list.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return array(
			'mark_received'  => __( 'Change status to parcel received', 'primeplaced-withdrawal-button' ),
			'mark_completed' => __( 'Close without a refund', 'primeplaced-withdrawal-button' ),
		);
	}

	/**
	 * Lets extensions add controls next to the bulk actions.
	 *
	 * @param string $which "top" or "bottom".
	 * @return void
	 */
	protected function extra_tablenav( $which ): void { // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint -- WP_List_Table declares it untyped.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		$filters = array(
			'status' => isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '',
			's'      => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
		);
		// phpcs:enable

		/**
		 * Prints controls above or below the requests list.
		 *
		 * @param string                           $which   "top" or "bottom".
		 * @param array{status: string, s: string} $filters Current list filters.
		 */
		do_action( 'ppwb_requests_list_tablenav', (string) $which, $filters );
	}

	/**
	 * Submission date, relative within 24 hours like WooCommerce.
	 *
	 * @param ListRow $item Row.
	 * @return string
	 */
	protected function column_submitted( $item ): string {
		$timestamp = (int) strtotime( $item['submitted_at_gmt'] . ' UTC' );
		$label     = $timestamp > time() - DAY_IN_SECONDS
			/* translators: %s: human-readable time difference. */
			? sprintf( __( '%s ago', 'primeplaced-withdrawal-button' ), human_time_diff( $timestamp ) )
			: Dates::date( $timestamp );

		return sprintf(
			'<time datetime="%1$s" title="%2$s">%3$s</time>',
			esc_attr( gmdate( 'c', $timestamp ) ),
			esc_attr( Dates::datetime( $timestamp ) ),
			esc_html( $label )
		);
	}

	/**
	 * Status badge.
	 *
	 * @param ListRow $item Row.
	 * @return string
	 */
	protected function column_status( $item ): string {
		return StatusView::mark( $item['status'] );
	}

	/**
	 * Link to the order.
	 *
	 * @param ListRow $item Row.
	 * @return string
	 */
	protected function column_order( $item ): string {
		$order = wc_get_order( $item['order_id'] );
		if ( ! $order instanceof \WC_Order ) {
			return esc_html( '#' . $item['order_id'] );
		}

		return sprintf( '<a href="%1$s">#%2$s</a>', esc_url( $order->get_edit_order_url() ), esc_html( (string) $order->get_order_number() ) );
	}

	/**
	 * Estimated refund.
	 *
	 * @param ListRow $item Row.
	 * @return string
	 */
	protected function column_total( $item ): string {
		$decimals = wc_get_price_decimals();
		$amount   = Money::to_decimal( Money::to_minor( $item['estimated_total'], $decimals ), $decimals );

		return wp_kses_post( wc_price( (float) $amount, array( 'currency' => $item['currency'] ) ) );
	}

	/**
	 * Refund deadline (statutory 14 days), highlighted when close or overdue.
	 *
	 * @param ListRow $item Row.
	 * @return string
	 */
	protected function column_refund_due( $item ): string {
		$status = Status::tryFrom( $item['status'] );
		$due    = (int) strtotime( $item['refund_due_gmt'] . ' UTC' );
		$date   = Dates::date( $due );

		if ( null === $status || in_array( $status, array( Status::Refunded, Status::Completed, Status::Rejected, Status::Cancelled ), true ) ) {
			return '<span aria-hidden="true">-</span>';
		}

		$days_left = (int) floor( ( $due - time() ) / DAY_IN_SECONDS );
		$class     = $days_left < 0 ? 'is-overdue' : ( $days_left <= 3 ? 'is-soon' : '' );
		$note      = $days_left < 0
			? __( 'overdue', 'primeplaced-withdrawal-button' )
			/* translators: %d: number of days left to refund. */
			: sprintf( _n( '%d day left', '%d days left', $days_left, 'primeplaced-withdrawal-button' ), $days_left );

		return sprintf( '<span class="ppwb-due %1$s">%2$s<br><small>%3$s</small></span>', esc_attr( $class ), esc_html( $date ), esc_html( $note ) );
	}

	/**
	 * Products of the request, e.g. "Komoda x1".
	 *
	 * @param ListRow $item Row.
	 * @return string
	 */
	protected function column_products( $item ): string {
		return esc_html( $this->products[ $item['id'] ] ?? '' );
	}

	/**
	 * Builds one view link.
	 *
	 * @param string $url     Link URL.
	 * @param string $label   Label.
	 * @param int    $count   Count.
	 * @param bool   $current Whether the view is active.
	 * @return string
	 */
	private function view_link( string $url, string $label, int $count, bool $current ): string {
		return sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( $url ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_html( $label ),
			esc_html( number_format_i18n( $count ) )
		);
	}
}
