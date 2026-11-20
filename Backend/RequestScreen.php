<?php
/**
 * Request details, laid out like the WooCommerce order edit screen.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Backend;

use PrimePlaced\WithdrawalButton\Data\EventLog;
use PrimePlaced\WithdrawalButton\Data\RequestRepository;
use PrimePlaced\WithdrawalButton\Data\Settings;
use PrimePlaced\WithdrawalButton\Domain\Money;
use PrimePlaced\WithdrawalButton\Domain\Status;
use PrimePlaced\WithdrawalButton\Engine\Module;
use PrimePlaced\WithdrawalButton\Emails\Mailer;
use PrimePlaced\WithdrawalButton\Integrations\Crypto;
use PrimePlaced\WithdrawalButton\Integrations\Dates;
use PrimePlaced\WithdrawalButton\Integrations\RefundService;
use PrimePlaced\WithdrawalButton\Integrations\RequestWorkflow;
use PrimePlaced\WithdrawalButton\Integrations\WithdrawalService;
use PrimePlaced\WithdrawalButton\Pdf\ConfirmationPdf;
use PrimePlaced\WithdrawalButton\Pdf\PrivateStorage;
use Throwable;
use WC_Order;

/**
 * Class RequestScreen
 *
 * Shown on WooCommerce > Returns when ?request=ID is set. Every action posts
 * to admin-post.php with a nonce and a capability check.
 */
final class RequestScreen implements Module {

	private const ACTION = 'ppwb_request_action';

	/**
	 * Creates the screen.
	 *
	 * @param RequestRepository $requests Request storage.
	 * @param EventLog          $events   Request history.
	 * @param RequestWorkflow   $workflow Status changes.
	 * @param RefundService     $refunds  Refunds.
	 * @param Mailer            $mailer   Emails.
	 * @param ConfirmationPdf   $pdf      Confirmation PDF.
	 * @param PrivateStorage    $storage  Private files.
	 * @param Crypto            $crypto   Bank account encryption.
	 * @param Settings          $settings Plugin settings.
	 * @param WithdrawalService $withdrawals Withdrawal rules (tax ID).
	 */
	public function __construct(
		private RequestRepository $requests,
		private EventLog $events,
		private RequestWorkflow $workflow,
		private RefundService $refunds,
		private Mailer $mailer,
		private ConfirmationPdf $pdf,
		private PrivateStorage $storage,
		private Crypto $crypto,
		private Settings $settings,
		private WithdrawalService $withdrawals
	) {
	}

	/**
	 * URL of a request's details.
	 *
	 * @param int $request_id Request ID.
	 * @return string
	 */
	public static function url( int $request_id ): string {
		return admin_url( 'admin.php?page=' . AdminPage::SLUG . '&request=' . $request_id );
	}

	/**
	 * Adds the action handlers and meta boxes.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_ppwb_download', array( $this, 'download' ) );
		add_action( 'add_meta_boxes_' . AdminPage::SCREEN_ID, array( $this, 'meta_boxes' ) );
	}

	/**
	 * Registers the meta boxes, like the order edit screen.
	 *
	 * @return void
	 */
	public function meta_boxes(): void {
		$screen = AdminPage::SCREEN_ID;
		add_meta_box( 'ppwb-items', __( 'Products', 'primeplaced-withdrawal-button' ), array( $this, 'box_items' ), $screen, 'normal' );
		add_meta_box( 'ppwb-statement', __( 'Statement', 'primeplaced-withdrawal-button' ), array( $this, 'box_statement' ), $screen, 'normal' );
		add_meta_box( 'ppwb-history', __( 'History', 'primeplaced-withdrawal-button' ), array( $this, 'box_history' ), $screen, 'normal' );
		add_meta_box( 'ppwb-actions', __( 'Status and actions', 'primeplaced-withdrawal-button' ), array( $this, 'box_actions' ), $screen, 'side', 'high' );
		add_meta_box( 'ppwb-refund', __( 'Refund', 'primeplaced-withdrawal-button' ), array( $this, 'box_refund' ), $screen, 'side' );
		add_meta_box( 'ppwb-customer', __( 'Customer', 'primeplaced-withdrawal-button' ), array( $this, 'box_customer' ), $screen, 'side' );
		add_meta_box( 'ppwb-shipping', __( 'Return shipment', 'primeplaced-withdrawal-button' ), array( $this, 'box_shipping' ), $screen, 'side' );
		add_meta_box( 'ppwb-notes', __( 'Add note', 'primeplaced-withdrawal-button' ), array( $this, 'box_notes' ), $screen, 'side' );
	}

	/**
	 * Prints the details screen.
	 *
	 * @param int $request_id Request ID.
	 * @return void
	 */
	public function render( int $request_id ): void {
		$request = $this->requests->find( $request_id );
		if ( null === $request ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Request not found.', 'primeplaced-withdrawal-button' ) . '</p></div>';
			return;
		}

		do_action( 'add_meta_boxes_' . AdminPage::SCREEN_ID, $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core meta box hook for our screen.
		$this->print_notice();
		?>
		<div class="wrap ppwb-request">
			<h1 class="wp-heading-inline">
				<?php
				/* translators: %s: request number. */
				printf( esc_html__( 'Request %s', 'primeplaced-withdrawal-button' ), esc_html( (string) $request['number'] ) );
				?>
			</h1>
			<?php echo StatusView::mark( (string) $request['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in StatusView::mark(). ?>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPage::SLUG ) ); ?>"><?php esc_html_e( 'Back to list', 'primeplaced-withdrawal-button' ); ?></a>
			<hr class="wp-header-end">
			<?php if ( in_array( WithdrawalService::BEFORE_SHIPMENT, explode( ',', (string) $request['flags'] ), true ) ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'Cancellation before shipment: the customer withdrew before the order was shipped. Do not ship the order. Cancel it and refund the payment without waiting for a parcel.', 'primeplaced-withdrawal-button' ); ?></p></div>
			<?php endif; ?>
			<?php if ( str_contains( (string) $request['flags'], 'integration_error' ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Integration error: a carrier or invoicing step failed, the history below says which. Use "Retry" next to the shipment or issue the credit note by hand.', 'primeplaced-withdrawal-button' ); ?></p></div>
			<?php endif; ?>
			<?php if ( str_contains( (string) $request['flags'], 'action_required' ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Action required: an email, a refund or an integration step failed, the history below says which. For a missing confirmation use "Resend confirmation" and check the email settings of the site.', 'primeplaced-withdrawal-button' ); ?></p></div>
				<?php if ( ! $this->settings->has_return_address() ) : ?>
					<div class="notice notice-error inline"><p><?php esc_html_e( 'The shop has no return address, so the customer did not get one. Send the return address in a note to the customer and fill it in the settings.', 'primeplaced-withdrawal-button' ); ?></p></div>
				<?php endif; ?>
			<?php endif; ?>
			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="postbox-container-1" class="postbox-container"><?php do_meta_boxes( AdminPage::SCREEN_ID, 'side', $request ); ?></div>
					<div id="postbox-container-2" class="postbox-container"><?php do_meta_boxes( AdminPage::SCREEN_ID, 'normal', $request ); ?></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Products box.
	 *
	 * @param array<string, string|int|null> $request Request.
	 * @return void
	 */
	public function box_items( array $request ): void {
		$decimals = wc_get_price_decimals();
		$currency = array( 'currency' => (string) $request['currency'] );
		?>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Product', 'primeplaced-withdrawal-button' ); ?></th><th><?php esc_html_e( 'Quantity', 'primeplaced-withdrawal-button' ); ?></th><th><?php esc_html_e( 'Unit price', 'primeplaced-withdrawal-button' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $this->requests->items( (int) $request['id'] ) as $item ) : ?>
					<?php $unit = Money::to_minor( $item['unit_total'], $decimals ) + Money::to_minor( $item['tax'], $decimals ); ?>
					<tr>
						<td><?php echo esc_html( $item['name'] ); ?></td>
						<td><?php echo esc_html( (string) $item['qty'] ); ?></td>
						<td><?php echo wp_kses_post( wc_price( (float) Money::to_decimal( $unit, $decimals ), $currency ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( ! empty( $request['reason_code'] ) || ! empty( $request['reason_text'] ) ) : ?>
			<p>
				<strong><?php esc_html_e( 'Reason given by the customer:', 'primeplaced-withdrawal-button' ); ?></strong>
				<?php echo esc_html( implode( ' - ', array_filter( array( empty( $request['reason_code'] ) ? '' : $this->settings->return_reason_label( (string) $request['reason_code'] ), (string) $request['reason_text'] ) ) ) ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Statement box.
	 *
	 * @param array<string, string|int|null> $request Request.
	 * @return void
	 */
	public function box_statement( array $request ): void {
		$submitted = (int) strtotime( $request['submitted_at_gmt'] . ' UTC' );
		?>
		<p><strong><?php esc_html_e( 'Submitted:', 'primeplaced-withdrawal-button' ); ?></strong> <span title="<?php echo esc_attr( (string) $request['submitted_at_gmt'] . ' UTC' ); ?>"><?php echo esc_html( Dates::datetime( $submitted ) ); ?></span></p>
		<blockquote class="ppwb-statement"><?php echo esc_html( (string) $request['statement_text'] ); ?></blockquote>
		<p class="description">SHA-256: <code><?php echo esc_html( (string) $request['statement_hash'] ); ?></code></p>
		<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ppwb_download&type=pdf&request=' . (int) $request['id'] ), 'ppwb_download_' . (int) $request['id'] ) ); ?>"><?php esc_html_e( 'Download PDF', 'primeplaced-withdrawal-button' ); ?></a></p>
		<?php
	}

	/**
	 * History box (read-only).
	 *
	 * @param array<string, string|int|null> $request Request.
	 * @return void
	 */
	public function box_history( array $request ): void {
		echo '<ul class="order_notes ppwb-history">';
		foreach ( array_reverse( $this->events->for_request( (int) $request['id'] ) ) as $event ) {
			$time  = (int) strtotime( $event['created_at_gmt'] . ' UTC' );
			$actor = $this->actor_label( $event['actor_type'], $event['actor_id'] );
			$class = 'note' === $event['type'] && ! empty( $event['payload']['to_customer'] ) ? 'note customer-note' : ( 'note' === $event['type'] ? 'note' : 'note system-note' );
			printf(
				'<li class="%1$s"><div class="note_content"><p>%2$s</p></div><p class="meta"><abbr class="exact-date" title="%3$s">%4$s</abbr> %5$s</p></li>',
				esc_attr( $class ),
				esc_html( $this->describe( $event ) ),
				esc_attr( gmdate( 'c', $time ) ),
				esc_html( Dates::datetime( $time ) ),
				esc_html( $actor )
			);
		}
		echo '</ul>';
	}

	/**
	 * Status and actions box.
	 *
	 * @param array<string, string|int|null> $request Request.
	 * @return void
	 */
	public function box_actions( array $request ): void {
		$status = Status::tryFrom( (string) $request['status'] ) ?? Status::New;
		$id     = (int) $request['id'];

		echo '<p>' . StatusView::mark( $status->value ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in StatusView::mark().

		$buttons = array(
			'in_transit' => __( 'Mark as in transit', 'primeplaced-withdrawal-button' ),
			'received'   => __( 'Mark as received', 'primeplaced-withdrawal-button' ),
			'completed'  => __( 'Close without a refund', 'primeplaced-withdrawal-button' ),
			'cancelled'  => __( 'Cancel request', 'primeplaced-withdrawal-button' ),
		);
		foreach ( $buttons as $target => $label ) {
			$to = Status::from( $target );
			if ( $status->can_transition_to( $to ) ) {
				$this->action_form( $id, 'status', $label, array( 'to' => $target ), 'received' === $target ? 'button-primary' : '' );
			}
		}

		if ( $status->can_transition_to( Status::Rejected ) ) {
			?>
			<details class="ppwb-reject">
				<summary class="button"><?php esc_html_e( 'Reject', 'primeplaced-withdrawal-button' ); ?></summary>
				<?php $this->form_open( $id, 'reject' ); ?>
					<p><label for="ppwb-reject-reason"><?php esc_html_e( 'Reason for the customer (required)', 'primeplaced-withdrawal-button' ); ?></label>
					<textarea id="ppwb-reject-reason" name="message" rows="3" class="widefat" required></textarea></p>
					<p class="description"><?php esc_html_e( 'A rejection does not change the effect of a statement made on time.', 'primeplaced-withdrawal-button' ); ?></p>
					<p><button type="submit" class="button"><?php esc_html_e( 'Reject request', 'primeplaced-withdrawal-button' ); ?></button></p>
				</form>
			</details>
			<?php
		}

		if ( $status->can_reopen() ) {
			$this->action_form( $id, 'reopen', __( 'Reopen', 'primeplaced-withdrawal-button' ) );
		}

		echo '<hr>';
		$this->action_form( $id, 'resend', __( 'Resend confirmation', 'primeplaced-withdrawal-button' ) );
	}

	/**
	 * Refund box: deadline, planned amount and the refund form.
	 *
	 * @param array<string, string|int|null> $request Request.
	 * @return void
	 */
	public function box_refund( array $request ): void {
		$id     = (int) $request['id'];
		$status = Status::tryFrom( (string) $request['status'] ) ?? Status::New;
		$due    = (int) strtotime( $request['refund_due_gmt'] . ' UTC' );
		$left   = (int) floor( ( $due - time() ) / DAY_IN_SECONDS );

		printf(
			'<p><strong>%1$s</strong> %2$s</p>',
			esc_html__( 'Refund due:', 'primeplaced-withdrawal-button' ),
			esc_html( Dates::date( $due ) )
		);

		if ( (float) $request['refunded_total'] > 0 ) {
			printf(
				'<p><strong>%1$s</strong> %2$s</p>',
				esc_html__( 'Refunded:', 'primeplaced-withdrawal-button' ),
				wp_kses_post( wc_price( (float) $request['refunded_total'], array( 'currency' => (string) $request['currency'] ) ) )
			);
		}

		if ( ! $status->can_transition_to( Status::Refunded ) ) {
			return;
		}

		if ( $left >= 0 ) {
			/* translators: %d: days left. */
			echo '<p>' . esc_html( sprintf( _n( '%d day left to refund.', '%d days left to refund.', $left, 'primeplaced-withdrawal-button' ), $left ) ) . '</p>';
		} else {
			echo '<p class="ppwb-due is-overdue">' . esc_html__( 'The statutory refund deadline has passed.', 'primeplaced-withdrawal-button' ) . '</p>';
		}

		if ( in_array( $status, array( Status::New, Status::InTransit ), true ) ) {
			echo '<p class="description">' . esc_html__( 'You may withhold the refund until you receive the products or proof that they were sent.', 'primeplaced-withdrawal-button' ) . '</p>';
		}

		try {
			$plan = $this->refunds->plan( $id );
		} catch ( Throwable $error ) {
			echo '<p>' . wp_kses_post( $error->getMessage() ) . '</p>';
			return;
		}

		$decimals = wc_get_price_decimals();
		$amount   = Money::to_decimal( $plan['amount_minor'], $decimals );

		$this->form_open( $id, 'refund' );
		?>
			<p>
				<label for="ppwb-refund-amount"><?php esc_html_e( 'Amount', 'primeplaced-withdrawal-button' ); ?></label>
				<input type="text" inputmode="decimal" id="ppwb-refund-amount" name="amount" class="widefat" value="<?php echo esc_attr( wc_format_localized_price( $amount ) ); ?>">
				<span class="description">
					<?php
					if ( $plan['shipping_capped'] ) {
						esc_html_e( 'Includes the cheapest standard delivery, because the whole order is returned (Art. 33: the customer chose a dearer delivery).', 'primeplaced-withdrawal-button' );
					} elseif ( $plan['includes_shipping'] ) {
						esc_html_e( 'Includes the delivery cost, because the whole order is returned.', 'primeplaced-withdrawal-button' );
					} else {
						esc_html_e( 'Products only; the delivery cost is refunded when the whole order is returned.', 'primeplaced-withdrawal-button' );
					}
					?>
				</span>
			</p>
			<p>
				<label for="ppwb-refund-reason"><?php esc_html_e( 'Deduction reason (required for a lower amount)', 'primeplaced-withdrawal-button' ); ?></label>
				<textarea id="ppwb-refund-reason" name="reason" rows="2" class="widefat"></textarea>
			</p>
			<p><label><input type="checkbox" name="restock" value="1"<?php checked( $this->settings->flag( 'restock_default' ) ); ?>> <?php esc_html_e( 'Return products to stock', 'primeplaced-withdrawal-button' ); ?></label></p>
			<p class="description">
				<?php
				echo $plan['automatic']
					? esc_html__( 'The payment gateway will refund the customer automatically.', 'primeplaced-withdrawal-button' )
					: esc_html__( 'This payment method has no automatic refunds: WooCommerce records the refund and you transfer the money yourself.', 'primeplaced-withdrawal-button' );
				?>
			</p>
			<?php if ( $plan['automatic'] ) : ?>
				<?php $failure = $this->last_refund_failure( $id ); ?>
				<?php if ( '' !== $failure ) : ?>
					<div class="notice notice-error inline">
						<p>
							<?php
							/* translators: %s: error message from the payment gateway. */
							printf( esc_html__( 'The payment gateway refused the last refund: %s', 'primeplaced-withdrawal-button' ), esc_html( $failure ) );
							?>
						</p>
						<p><?php esc_html_e( 'You can refund manually by bank transfer. Ask the customer for an account number with a note, for example: "We could not refund the payment automatically. Please reply with the bank account number for the refund."', 'primeplaced-withdrawal-button' ); ?></p>
					</div>
				<?php endif; ?>
				<p><label><input type="checkbox" name="manual" value="1"<?php checked( '' !== $failure ); ?>> <?php esc_html_e( 'Refund manually (record the refund only, without the gateway)', 'primeplaced-withdrawal-button' ); ?></label></p>
			<?php endif; ?>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Refund payment', 'primeplaced-withdrawal-button' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Gateway message of the last failed refund, '' when the last attempt did not fail.
	 *
	 * @param int $request_id Request ID.
	 * @return string
	 */
	private function last_refund_failure( int $request_id ): string {
		$failure = '';
		foreach ( $this->events->for_request( $request_id ) as $event ) {
			if ( 'refund_failed' === $event['type'] ) {
				$failure = (string) ( $event['payload']['error'] ?? '' );
			} elseif ( 'refunded' === $event['type'] ) {
				$failure = '';
			}
		}

		return $failure;
	}

	/**
	 * Customer box.
	 *
	 * @param array<string, string|int|null> $request Request.
	 * @return void
	 */
	public function box_customer( array $request ): void {
		$order = wc_get_order( (int) $request['order_id'] );
		?>
		<p>
			<?php echo esc_html( (string) $request['full_name'] ); ?><br>
			<a href="mailto:<?php echo esc_attr( (string) $request['email'] ); ?>"><?php echo esc_html( (string) $request['email'] ); ?></a>
		</p>
		<?php if ( $order instanceof WC_Order ) : ?>
			<p>
				<?php esc_html_e( 'Order:', 'primeplaced-withdrawal-button' ); ?>
				<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( (string) $order->get_order_number() ); ?></a>,
				<?php echo esc_html( $order->get_payment_method_title() ); ?>
			</p>
			<?php $ppwb_tax_id = $this->withdrawals->tax_id( $order ); ?>
			<?php if ( '' !== $ppwb_tax_id ) : ?>
				<p>
					<?php esc_html_e( 'Tax ID:', 'primeplaced-withdrawal-button' ); ?> <?php echo esc_html( $ppwb_tax_id ); ?>
					<?php if ( str_contains( (string) $request['flags'], 'consumer_declared' ) ) : ?>
						<br><span class="description"><?php esc_html_e( 'The customer confirmed buying as a consumer or a sole trader with consumer rights.', 'primeplaced-withdrawal-button' ); ?></span>
					<?php endif; ?>
				</p>
			<?php endif; ?>
			<p>
				<?php esc_html_e( 'Refund method:', 'primeplaced-withdrawal-button' ); ?>
				<?php echo esc_html( $this->refunds->method_label( $order, empty( $request['bank_account'] ) ? '' : $this->crypto->decrypt( (string) $request['bank_account'] ) ) ); ?>
			</p>
		<?php endif; ?>
		<?php if ( ! empty( $request['bank_account'] ) ) : ?>
			<?php
			$account  = $this->crypto->decrypt( (string) $request['bank_account'] );
			$revealed = isset( $_GET['reveal'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['reveal'] ) ), 'ppwb_reveal_' . (int) $request['id'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized above.
			if ( $revealed ) {
				$this->events->add( (int) $request['id'], 'bank_account_revealed', array(), EventLog::ACTOR_USER );
			}
			?>
			<p>
				<?php esc_html_e( 'Bank account for the refund:', 'primeplaced-withdrawal-button' ); ?><br>
				<code><?php echo esc_html( $revealed ? $account : Crypto::mask( $account ) ); ?></code>
				<?php if ( ! $revealed ) : ?>
					<a href="<?php echo esc_url( wp_nonce_url( self::url( (int) $request['id'] ), 'ppwb_reveal_' . (int) $request['id'], 'reveal' ) ); ?>"><?php esc_html_e( 'Show (logged)', 'primeplaced-withdrawal-button' ); ?></a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<?php if ( ! empty( $request['ip_hash'] ) ) : ?>
			<p class="description"><?php esc_html_e( 'IP (hash):', 'primeplaced-withdrawal-button' ); ?> <code><?php echo esc_html( substr( (string) $request['ip_hash'], 0, 12 ) ); ?></code></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Return shipment box.
	 *
	 * @param array<string, string|int|null> $request Request.
	 * @return void
	 */
	public function box_shipping( array $request ): void {
		if ( empty( $request['tracking_number'] ) && empty( $request['tracking_carrier'] ) ) {
			echo '<p>' . esc_html__( 'The customer has not added shipping details yet.', 'primeplaced-withdrawal-button' ) . '</p>';
		} else {
			printf( '<p>%1$s<br><code>%2$s</code></p>', esc_html( (string) $request['tracking_carrier'] ), esc_html( (string) $request['tracking_number'] ) );
		}

		foreach ( $this->events->for_request( (int) $request['id'] ) as $event ) {
			if ( 'shipping_added' === $event['type'] && ! empty( $event['payload']['file'] ) ) {
				printf(
					'<p><a href="%1$s">%2$s</a></p>',
					esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ppwb_download&type=proof&event=' . $event['id'] . '&request=' . (int) $request['id'] ), 'ppwb_download_' . (int) $request['id'] ) ),
					esc_html__( 'Proof of shipment', 'primeplaced-withdrawal-button' )
				);
			}
		}
	}

	/**
	 * Notes box.
	 *
	 * @param array<string, string|int|null> $request Request.
	 * @return void
	 */
	public function box_notes( array $request ): void {
		$this->form_open( (int) $request['id'], 'note' );
		?>
			<p><textarea name="message" rows="3" class="widefat" aria-label="<?php esc_attr_e( 'Note', 'primeplaced-withdrawal-button' ); ?>"></textarea></p>
			<p>
				<select name="to_customer" aria-label="<?php esc_attr_e( 'Note type', 'primeplaced-withdrawal-button' ); ?>">
					<option value="0"><?php esc_html_e( 'Private note', 'primeplaced-withdrawal-button' ); ?></option>
					<option value="1"><?php esc_html_e( 'Note to customer (sent by email)', 'primeplaced-withdrawal-button' ); ?></option>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Add', 'primeplaced-withdrawal-button' ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * Handles a posted action and redirects back.
	 *
	 * @return void
	 */
	public function handle(): void {
		$request_id = isset( $_POST['request'] ) ? absint( $_POST['request'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $request_id );

		if ( ! current_user_can( Lifecycle::CAP_MANAGE_REQUESTS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage withdrawal requests.', 'primeplaced-withdrawal-button' ), 403 );
		}

		$do      = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$notice  = 'saved';

		try {
			switch ( $do ) {
				case 'status':
					$to = Status::tryFrom( isset( $_POST['to'] ) ? sanitize_key( wp_unslash( $_POST['to'] ) ) : '' );
					if ( null === $to || Status::Refunded === $to || Status::Rejected === $to ) {
						$notice = $this->fail( __( 'Unknown action.', 'primeplaced-withdrawal-button' ) );
						break;
					}
					$this->workflow->change_status( $request_id, $to, $message );
					break;
				case 'reject':
					$this->workflow->change_status( $request_id, Status::Rejected, $message );
					break;
				case 'reopen':
					$this->workflow->reopen( $request_id );
					break;
				case 'note':
					$this->workflow->add_note( $request_id, $message, ! empty( $_POST['to_customer'] ) );
					break;
				case 'resend':
					$sent = $this->mailer->send( Mailer::CONFIRMATION, $request_id );
					$this->requests->set_flag( $request_id, 'action_required', ! $sent );
					$notice = $sent ? 'sent' : 'not_sent';
					break;
				case 'refund':
					$this->refunds->refund(
						$request_id,
						isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '',
						isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '',
						! empty( $_POST['restock'] ),
						EventLog::ACTOR_USER,
						! empty( $_POST['manual'] )
					);
					$notice = 'refunded';
					break;
				default:
					$notice = $this->fail( __( 'Unknown action.', 'primeplaced-withdrawal-button' ) );
			}
		} catch ( Throwable $error ) {
			$notice = $this->fail( $error->getMessage() );
		}

		wp_safe_redirect( add_query_arg( 'ppwb_notice', $notice, self::url( $request_id ) ) );
		exit;
	}

	/**
	 * Bulk status change from the list; requests that cannot move are skipped.
	 *
	 * @param list<int> $ids    Request IDs.
	 * @param string    $target Target status key.
	 * @return int Number of changed requests.
	 */
	public function bulk_status( array $ids, string $target ): int {
		$to = Status::tryFrom( $target );
		if ( null === $to || in_array( $to, array( Status::Refunded, Status::Rejected ), true ) ) {
			return 0;
		}

		$changed = 0;
		foreach ( $ids as $id ) {
			try {
				$this->workflow->change_status( $id, $to );
				++$changed;
			} catch ( Throwable $error ) {
				continue;
			}
		}

		return $changed;
	}

	/**
	 * Streams the confirmation PDF or a proof of shipment to an authorized user.
	 *
	 * @return void
	 */
	public function download(): void {
		$request_id = isset( $_GET['request'] ) ? absint( $_GET['request'] ) : 0;
		check_admin_referer( 'ppwb_download_' . $request_id );
		if ( ! current_user_can( Lifecycle::CAP_MANAGE_REQUESTS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage withdrawal requests.', 'primeplaced-withdrawal-button' ), 403 );
		}

		$request = $this->requests->find( $request_id );
		if ( null === $request ) {
			wp_die( esc_html__( 'Request not found.', 'primeplaced-withdrawal-button' ), 404 );
		}

		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'pdf';
		if ( 'proof' === $type ) {
			$event_id = isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0;
			foreach ( $this->events->for_request( $request_id ) as $event ) {
				if ( $event['id'] === $event_id && ! empty( $event['payload']['file'] ) ) {
					$name = (string) $event['payload']['file'];
					self::stream( (string) $this->storage->read( $name ), 'proof-' . $request_id . '.' . PrivateStorage::extension( $name ) );
				}
			}
			wp_die( esc_html__( 'File not found.', 'primeplaced-withdrawal-button' ), 404 );
		}

		if ( RequestRepository::is_anonymized( $request ) ) {
			wp_die( esc_html__( 'The personal data of this request was erased; the confirmation is not available any more.', 'primeplaced-withdrawal-button' ), 410 );
		}

		self::stream( $this->pdf->ensure( $request_id ), ConfirmationPdf::file_name( (string) $request['number'] ) );
	}

	/**
	 * Sends private file contents to the browser and stops.
	 *
	 * @param string $bytes     File contents.
	 * @param string $file_name Download name.
	 * @return void
	 */
	public static function stream( string $bytes, string $file_name ): void {
		if ( '' === $bytes ) {
			wp_die( esc_html__( 'File not found.', 'primeplaced-withdrawal-button' ), 404 );
		}

		$type = wp_check_filetype( $file_name )['type'];
		$type = false === $type || '' === $type ? 'application/octet-stream' : $type;
		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $file_name ) . '"' );
		header( 'Content-Length: ' . (string) strlen( $bytes ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file download.
		exit;
	}

	/**
	 * Opens an action form.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $action_key Action key.
	 * @return void
	 */
	private function form_open( int $request_id, string $action_key ): void {
		printf( '<form method="post" action="%s" class="ppwb-action-form">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( self::ACTION . '_' . $request_id );
		printf(
			'<input type="hidden" name="action" value="%1$s"><input type="hidden" name="request" value="%2$d"><input type="hidden" name="do" value="%3$s">',
			esc_attr( self::ACTION ),
			(int) $request_id,
			esc_attr( $action_key )
		);
	}

	/**
	 * One-button action form.
	 *
	 * @param int                  $request_id Request ID.
	 * @param string               $action_key   Action key.
	 * @param string               $label        Button label.
	 * @param array<string,string> $fields       Extra hidden fields.
	 * @param string               $button_class Extra button class.
	 * @return void
	 */
	private function action_form( int $request_id, string $action_key, string $label, array $fields = array(), string $button_class = '' ): void {
		$this->form_open( $request_id, $action_key );
		foreach ( $fields as $name => $value ) {
			printf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( $name ), esc_attr( $value ) );
		}
		printf( '<button type="submit" class="button %1$s">%2$s</button></form>', esc_attr( $button_class ), esc_html( $label ) );
	}

	/**
	 * Stores an error for the next screen and returns the notice code.
	 *
	 * @param string $message Error message.
	 * @return string
	 */
	private function fail( string $message ): string {
		set_transient( 'ppwb_error_' . get_current_user_id(), $message, 60 );

		return 'error';
	}

	/**
	 * Prints the result of the last action.
	 *
	 * @return void
	 */
	private function print_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = isset( $_GET['ppwb_notice'] ) ? sanitize_key( wp_unslash( $_GET['ppwb_notice'] ) ) : '';
		if ( '' === $notice ) {
			return;
		}

		if ( 'error' === $notice ) {
			$message = (string) get_transient( 'ppwb_error_' . get_current_user_id() );
			delete_transient( 'ppwb_error_' . get_current_user_id() );
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', wp_kses_post( $message ) );
			return;
		}

		$messages = array(
			'saved'    => __( 'Request updated.', 'primeplaced-withdrawal-button' ),
			'sent'     => __( 'Confirmation sent again.', 'primeplaced-withdrawal-button' ),
			'not_sent' => __( 'The email could not be sent. Check the email settings of the site.', 'primeplaced-withdrawal-button' ),
			'refunded' => __( 'Refund created.', 'primeplaced-withdrawal-button' ),
		);
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			'not_sent' === $notice ? 'warning' : 'success',
			esc_html( $messages[ $notice ] ?? $messages['saved'] )
		);
	}

	/**
	 * Human description of a history entry.
	 *
	 * @param array{type: string, actor_type: string, payload: array<string, mixed>} $event Event.
	 * @return string
	 */
	private function describe( array $event ): string {
		$payload = $event['payload'];
		$label   = static fn ( string $value ): string => null === Status::tryFrom( $value ) ? $value : StatusView::label( Status::from( $value ) );

		switch ( $event['type'] ) {
			case 'created':
				return __( 'Withdrawal statement submitted.', 'primeplaced-withdrawal-button' );
			case 'status_changed':
			case 'reopened':
				$text = sprintf(
					/* translators: 1: previous status, 2: new status. */
					__( 'Status changed from %1$s to %2$s.', 'primeplaced-withdrawal-button' ),
					$label( (string) ( $payload['from'] ?? '' ) ),
					$label( (string) ( $payload['to'] ?? '' ) )
				);
				return '' === (string) ( $payload['message'] ?? '' ) ? $text : $text . ' ' . (string) $payload['message'];
			case 'note':
				return (string) ( $payload['text'] ?? '' );
			case 'email_sent':
				/* translators: %s: email ID. */
				return sprintf( __( 'Email sent: %s.', 'primeplaced-withdrawal-button' ), $this->email_title( (string) ( $payload['email'] ?? '' ) ) );
			case 'email_failed':
				/* translators: %s: email ID. */
				return sprintf( __( 'Email failed: %s.', 'primeplaced-withdrawal-button' ), $this->email_title( (string) ( $payload['email'] ?? '' ) ) );
			case 'refunded':
				/* translators: %s: amount. */
				return sprintf( __( 'Refund of %s created in WooCommerce.', 'primeplaced-withdrawal-button' ), (string) ( $payload['amount'] ?? '' ) ) . ( empty( $payload['reason'] ) ? '' : ' ' . (string) $payload['reason'] );
			case 'refund_failed':
				/* translators: %s: error message. */
				return sprintf( __( 'Refund failed: %s', 'primeplaced-withdrawal-button' ), (string) ( $payload['error'] ?? '' ) );
			case 'shipping_added':
				if ( EventLog::ACTOR_SYSTEM === $event['actor_type'] ) {
					/* translators: 1: carrier, 2: tracking number. */
					return sprintf( __( 'The carrier scanned the return parcel: %1$s %2$s.', 'primeplaced-withdrawal-button' ), (string) ( $payload['carrier'] ?? '' ), (string) ( $payload['tracking'] ?? '' ) );
				}
				/* translators: 1: carrier, 2: tracking number. */
				return sprintf( __( 'Customer added shipping details: %1$s %2$s.', 'primeplaced-withdrawal-button' ), (string) ( $payload['carrier'] ?? '' ), (string) ( $payload['tracking'] ?? '' ) );
			case 'bank_account_revealed':
				return __( 'Bank account number displayed.', 'primeplaced-withdrawal-button' );
			case 'personal_data_erased':
				return __( 'Personal data erased on request.', 'primeplaced-withdrawal-button' );
			case 'manual_created':
				return empty( $payload['late'] )
					? __( 'Request recorded by the shop.', 'primeplaced-withdrawal-button' )
					: __( 'Request recorded by the shop after the withdrawal period (flagged as late).', 'primeplaced-withdrawal-button' );
			case 'contact_email_changed':
				/* translators: %s: email from the order. */
				return sprintf( __( 'The customer gave another email for the confirmation (order email: %s).', 'primeplaced-withdrawal-button' ), (string) ( $payload['order_email'] ?? '' ) );
			default:
				/**
				 * Describes a history entry type that Core does not know.
				 *
				 * @param string               $text    Description; the event type by default.
				 * @param string               $type    Event type.
				 * @param array<string, mixed> $payload Event payload.
				 */
				return (string) apply_filters( 'ppwb_history_event_description', $event['type'], $event['type'], $payload );
		}
	}

	/**
	 * Title of a plugin email.
	 *
	 * @param string $key Email key.
	 * @return string
	 */
	private function email_title( string $key ): string {
		return Mailer::definitions()[ $key ]['title'] ?? $key;
	}

	/**
	 * Who made a change.
	 *
	 * @param string   $type Actor type.
	 * @param int|null $id   User ID.
	 * @return string
	 */
	private function actor_label( string $type, ?int $id ): string {
		if ( EventLog::ACTOR_CUSTOMER === $type ) {
			return __( 'by the customer', 'primeplaced-withdrawal-button' );
		}
		if ( EventLog::ACTOR_USER === $type && null !== $id ) {
			$user = get_userdata( $id );
			/* translators: %s: user display name. */
			return false === $user ? '' : sprintf( __( 'by %s', 'primeplaced-withdrawal-button' ), $user->display_name );
		}

		return __( 'by the system', 'primeplaced-withdrawal-button' );
	}
}
