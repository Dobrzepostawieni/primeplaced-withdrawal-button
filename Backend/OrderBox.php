<?php
/**
 * "Returns" box on the order edit screen.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Backend;

use DateTimeImmutable;
use PrimePlaced\WithdrawalButton\Data\RequestRepository;
use PrimePlaced\WithdrawalButton\Domain\Iban;
use PrimePlaced\WithdrawalButton\Domain\Money;
use PrimePlaced\WithdrawalButton\Domain\Status;
use PrimePlaced\WithdrawalButton\Engine\Module;
use PrimePlaced\WithdrawalButton\Integrations\Dates;
use PrimePlaced\WithdrawalButton\Integrations\RefundService;
use PrimePlaced\WithdrawalButton\Integrations\WithdrawalBlocked;
use PrimePlaced\WithdrawalButton\Integrations\WithdrawalService;
use Throwable;
use WC_Order;
use WP_Post;

/**
 * Class OrderBox
 *
 * Works with HPOS and legacy storage: the box is added to both order screens
 * and the order is always read through wc_get_order().
 */
final class OrderBox implements Module {

	private const NONCE = 'ppwb_order_box';

	public const ACTION = 'ppwb_manual_request';

	/**
	 * Creates the module.
	 *
	 * @param WithdrawalService $service  Withdrawal rules.
	 * @param RequestRepository $requests Request storage.
	 */
	public function __construct(
		private WithdrawalService $service,
		private RequestRepository $requests
	) {
	}

	/**
	 * Adds the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_delivery_date' ) );
		// After WooCommerce saved the order data (priority 40): a status set by
		// the new request must not be overwritten by the status field of the form.
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'manual_request' ), 60 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'woocommerce_after_order_refund_item_name', array( $this, 'refund_link' ) );
	}

	/**
	 * "See request ZW-..." under a refund made by the plugin.
	 *
	 * @param mixed $refund Refund row object.
	 * @return void
	 */
	public function refund_link( mixed $refund ): void {
		if ( ! $refund instanceof \WC_Order_Refund || ! current_user_can( Lifecycle::CAP_MANAGE_REQUESTS ) ) {
			return;
		}
		$request_id = absint( $refund->get_meta( RefundService::REQUEST_META ) );
		$request    = $request_id > 0 ? $this->requests->find( $request_id ) : null;
		if ( null === $request ) {
			return;
		}

		printf(
			'<br><a href="%1$s">%2$s</a>',
			esc_url( RequestScreen::url( $request_id ) ),
			/* translators: %s: request number. */
			esc_html( sprintf( __( 'See request %s', 'primeplaced-withdrawal-button' ), (string) $request['number'] ) )
		);
	}

	/**
	 * Adds the box to the HPOS and the legacy order screen.
	 *
	 * @return void
	 */
	public function add(): void {
		if ( ! current_user_can( Lifecycle::CAP_MANAGE_REQUESTS ) ) {
			return;
		}

		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'ppwb-order-returns', __( 'Returns (PrimePlaced)', 'primeplaced-withdrawal-button' ), array( $this, 'render' ), $screen, 'side', 'default' );
		}
	}

	/**
	 * Box content: deadline, delivery date, requests, manual request.
	 *
	 * @param WP_Post|WC_Order|mixed $post_or_order Legacy post or HPOS order.
	 * @return void
	 */
	public function render( mixed $post_or_order ): void {
		$order = $post_or_order instanceof WP_Post ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		wp_nonce_field( self::NONCE, '_ppwb_order_box_nonce' );
		$deadline = $this->service->deadline( $order );
		$now      = new DateTimeImmutable( 'now', wp_timezone() );
		$days     = (int) floor( ( $deadline->getTimestamp() - $now->getTimestamp() ) / DAY_IN_SECONDS );

		echo '<p><strong>' . esc_html__( 'Withdrawal period:', 'primeplaced-withdrawal-button' ) . '</strong> ';
		if ( $deadline < $now ) {
			/* translators: %s: date. */
			echo '<span style="color:#b32d2e;font-weight:600;">' . esc_html( sprintf( __( 'ended on %s', 'primeplaced-withdrawal-button' ), Dates::date( $deadline ) ) ) . '</span>';
		} else {
			/* translators: 1: date, 2: days left. */
			echo esc_html( sprintf( _n( 'until %1$s, %2$d day left', 'until %1$s, %2$d days left', $days, 'primeplaced-withdrawal-button' ), Dates::date( $deadline ), $days ) );
		}
		echo '</p>';
		if ( $this->service->is_before_shipment( $order ) ) {
			echo '<p class="description">' . esc_html__( 'Not shipped yet: a request now is a cancellation before shipment.', 'primeplaced-withdrawal-button' ) . '</p>';
		}

		$delivered = absint( $order->get_meta( WithdrawalService::DELIVERED_META ) );
		?>
		<p>
			<label for="ppwb-delivered-at"><?php esc_html_e( 'Delivery date', 'primeplaced-withdrawal-button' ); ?></label><br>
			<input type="date" id="ppwb-delivered-at" name="ppwb_delivered_at" value="<?php echo esc_attr( $delivered > 0 ? (string) wp_date( 'Y-m-d', $delivered ) : '' ); ?>" max="<?php echo esc_attr( $now->format( 'Y-m-d' ) ); ?>">
			<span class="description"><?php esc_html_e( 'When you know it, the period counts from this date. Saved with the order.', 'primeplaced-withdrawal-button' ); ?></span>
		</p>
		<?php
		$this->render_requests( $order );
		$this->render_manual_form( $order );
	}

	/**
	 * Requests of the order with links.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	private function render_requests( WC_Order $order ): void {
		$requests = $this->requests->for_order( $order->get_id() );
		if ( array() === $requests ) {
			echo '<p>' . esc_html__( 'No withdrawal requests for this order.', 'primeplaced-withdrawal-button' ) . '</p>';
			return;
		}

		echo '<ul class="ppwb-order-requests">';
		foreach ( $requests as $request ) {
			$items  = array_map( static fn ( array $item ): string => $item['name'] . ' x' . $item['qty'], $this->requests->items( (int) $request['id'] ) );
			$status = Status::tryFrom( (string) $request['status'] );
			printf(
				'<li><a href="%1$s"><strong>%2$s</strong></a> %3$s<br>%4$s, %5$s</li>',
				esc_url( RequestScreen::url( (int) $request['id'] ) ),
				esc_html( (string) $request['number'] ),
				null === $status ? '' : StatusView::mark( $status->value ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in StatusView::mark().
				wp_kses_post( wc_price( (float) Money::to_decimal( Money::to_minor( (string) $request['estimated_total'], wc_get_price_decimals() ), wc_get_price_decimals() ), array( 'currency' => (string) $request['currency'] ) ) ),
				esc_html( implode( ', ', $items ) )
			);
		}
		echo '</ul>';
	}

	/**
	 * "Create a request manually" for a statement made by email, phone or letter.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	private function render_manual_form( WC_Order $order ): void {
		$lines = array_filter( $this->service->lines( $order ), static fn ( array $line ): bool => $line['available'] > 0 );
		if ( array() === $lines ) {
			return;
		}
		$today = ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );
		?>
		<details class="ppwb-manual">
			<summary class="button"><?php esc_html_e( 'Create a request manually', 'primeplaced-withdrawal-button' ); ?></summary>
			<p class="description"><?php esc_html_e( 'For a statement the customer made by email, phone or letter. Also possible after the period; such a request is flagged as late.', 'primeplaced-withdrawal-button' ); ?></p>
			<?php foreach ( $lines as $line ) : ?>
				<p>
					<label>
						<?php echo esc_html( $line['name'] ); ?>
						<input type="number" name="ppwb_manual_qty[<?php echo esc_attr( (string) $line['id'] ); ?>]" min="0" max="<?php echo esc_attr( (string) $line['available'] ); ?>" value="<?php echo esc_attr( (string) $line['available'] ); ?>" class="small-text">
					</label>
				</p>
			<?php endforeach; ?>
			<p>
				<label for="ppwb-manual-name"><?php esc_html_e( 'Customer name', 'primeplaced-withdrawal-button' ); ?></label>
				<input type="text" id="ppwb-manual-name" name="ppwb_manual_name" class="widefat" value="<?php echo esc_attr( trim( $order->get_formatted_billing_full_name() ) ); ?>">
			</p>
			<p>
				<label for="ppwb-manual-date"><?php esc_html_e( 'Date of the customer\'s statement', 'primeplaced-withdrawal-button' ); ?></label><br>
				<input type="date" id="ppwb-manual-date" name="ppwb_manual_date" value="<?php echo esc_attr( $today ); ?>" max="<?php echo esc_attr( $today ); ?>">
				<span class="description"><?php esc_html_e( 'The day the customer sent the email or letter, or called. The period and the refund deadline count from this day.', 'primeplaced-withdrawal-button' ); ?></span>
			</p>
			<?php if ( $this->service->needs_bank_account( $order ) ) : ?>
				<p>
					<label for="ppwb-manual-iban"><?php esc_html_e( 'Bank account for the refund (optional)', 'primeplaced-withdrawal-button' ); ?></label>
					<input type="text" id="ppwb-manual-iban" name="ppwb_manual_iban" class="widefat" autocomplete="off">
				</p>
			<?php endif; ?>
			<?php
			// Submits the order form: the request is created while the order is saved (the form has its own "action" field).
			?>
			<p><button type="submit" class="button button-primary" name="ppwb_manual_submit" value="1"><?php esc_html_e( 'Create request', 'primeplaced-withdrawal-button' ); ?></button></p>
		</details>
		<?php
	}

	/**
	 * Saves the delivery date with the order (HPOS and legacy).
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function save_delivery_date( int $order_id ): void {
		if ( ! isset( $_POST['_ppwb_order_box_nonce'], $_POST['ppwb_delivered_at'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_ppwb_order_box_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! current_user_can( Lifecycle::CAP_MANAGE_REQUESTS ) ) {
			return;
		}

		$value = sanitize_text_field( wp_unslash( $_POST['ppwb_delivered_at'] ) );
		$date  = '' === $value ? false : DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
		$old   = absint( $order->get_meta( WithdrawalService::DELIVERED_META ) );

		if ( false === $date ) {
			if ( $old > 0 ) {
				$order->delete_meta_data( WithdrawalService::DELIVERED_META );
				$order->save_meta_data();
			}
			return;
		}

		// Noon shop time, so the day stays the same in every timezone.
		$timestamp = $date->setTime( 12, 0 )->getTimestamp();
		if ( $timestamp !== $old && $timestamp <= time() + DAY_IN_SECONDS ) {
			$order->update_meta_data( WithdrawalService::DELIVERED_META, (string) $timestamp );
			$order->save_meta_data();
		}
	}

	/**
	 * Stores a request recorded by the shop when "Create request" saved the order.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function manual_request( int $order_id ): void {
		if ( empty( $_POST['ppwb_manual_submit'] ) || ! isset( $_POST['_ppwb_order_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_ppwb_order_box_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! current_user_can( Lifecycle::CAP_MANAGE_REQUESTS ) ) {
			return;
		}

		$selection = array();
		foreach ( isset( $_POST['ppwb_manual_qty'] ) && is_array( $_POST['ppwb_manual_qty'] ) ? wp_unslash( $_POST['ppwb_manual_qty'] ) : array() as $item_id => $qty ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cast to int below.
			$selection[ absint( $item_id ) ] = absint( $qty );
		}
		$name = isset( $_POST['ppwb_manual_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ppwb_manual_name'] ) ) : '';
		$iban = isset( $_POST['ppwb_manual_iban'] ) ? Iban::normalize( sanitize_text_field( wp_unslash( $_POST['ppwb_manual_iban'] ) ) ) : '';

		// An earlier day counts as noon shop time; today (or nothing) means now.
		$posted = isset( $_POST['ppwb_manual_date'] ) ? sanitize_text_field( wp_unslash( $_POST['ppwb_manual_date'] ) ) : '';
		$day    = '' === $posted ? false : DateTimeImmutable::createFromFormat( '!Y-m-d', $posted, wp_timezone() );
		$stated = false !== $day && $day->format( 'Y-m-d' ) < ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' ) ? $day->setTime( 12, 0 ) : null;

		$result = array( 'code' => 'failed' );
		try {
			if ( '' !== $iban && ! Iban::is_valid( $iban ) ) {
				$result = array( 'code' => 'iban' );
			} else {
				$request_id = $this->service->submit( $order, $selection, '' !== $name ? $name : trim( $order->get_formatted_billing_full_name() ), '', '', $iban, 'manual', '', '', $stated );
				$result     = array(
					'code' => 'created',
					'id'   => $request_id,
				);
			}
		} catch ( WithdrawalBlocked $blocked ) {
			unset( $blocked );
			$result = array( 'code' => 'nothing' );
		} catch ( Throwable $error ) {
			unset( $error );
		}

		set_transient( self::ACTION . '_' . get_current_user_id(), $result, MINUTE_IN_SECONDS );
	}

	/**
	 * Result of "Create request" after the order screen reloads.
	 *
	 * @return void
	 */
	public function notice(): void {
		$key    = self::ACTION . '_' . get_current_user_id();
		$result = get_transient( $key );
		if ( ! is_array( $result ) ) {
			return;
		}
		delete_transient( $key );

		if ( 'created' === ( $result['code'] ?? '' ) ) {
			$request = $this->requests->find( (int) ( $result['id'] ?? 0 ) );
			printf(
				'<div class="notice notice-success is-dismissible"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'The withdrawal request was created.', 'primeplaced-withdrawal-button' ),
				esc_url( RequestScreen::url( (int) ( $result['id'] ?? 0 ) ) ),
				esc_html( (string) ( $request['number'] ?? '' ) )
			);
			return;
		}

		$text = match ( $result['code'] ?? '' ) {
			'iban'    => __( 'The bank account number is not valid. The request was not created.', 'primeplaced-withdrawal-button' ),
			'nothing' => __( 'Nothing left to withdraw in this order. The request was not created.', 'primeplaced-withdrawal-button' ),
			default   => __( 'The request could not be created. Choose at least one product.', 'primeplaced-withdrawal-button' ),
		};
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}
}
