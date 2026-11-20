<?php
/**
 * Status changes, reopening, notes and customer shipping details.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Integrations;

use PrimePlaced\WithdrawalButton\Backend\RequestScreen;
use PrimePlaced\WithdrawalButton\Backend\StatusView;
use PrimePlaced\WithdrawalButton\Data\EventLog;
use PrimePlaced\WithdrawalButton\Data\RequestRepository;
use PrimePlaced\WithdrawalButton\Data\Settings;
use PrimePlaced\WithdrawalButton\Domain\Status;
use RuntimeException;
use WC_Order;

/**
 * Class RequestWorkflow
 *
 * Every change goes through Domain\Status and writes the history in the same
 * call.
 */
final class RequestWorkflow {

	/**
	 * Creates the workflow.
	 *
	 * @param RequestRepository $requests Request storage.
	 * @param EventLog          $events   Request history.
	 * @param Settings          $settings Plugin settings.
	 */
	public function __construct(
		private RequestRepository $requests,
		private EventLog $events,
		private Settings $settings
	) {
	}

	/**
	 * Changes the status when the transition is allowed.
	 *
	 * @param int    $request_id Request ID.
	 * @param Status $to         Target status.
	 * @param string $message    Reason or note; required for "rejected".
	 * @param string $actor_type EventLog actor type.
	 * @throws RuntimeException When the request is missing, the transition is not allowed or a reason is missing.
	 * @return void
	 */
	public function change_status( int $request_id, Status $to, string $message = '', string $actor_type = EventLog::ACTOR_USER ): void {
		$from = $this->current_status( $request_id );

		if ( ! $from->can_transition_to( $to ) ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
					/* translators: 1: current status, 2: target status. */
						__( 'A request cannot move from "%1$s" to "%2$s".', 'primeplaced-withdrawal-button' ),
						StatusView::label( $from ),
						StatusView::label( $to )
					)
				)
			);
		}

		if ( Status::Rejected === $to && '' === trim( $message ) ) {
			throw new RuntimeException( esc_html( __( 'A rejection needs a reason for the customer.', 'primeplaced-withdrawal-button' ) ) );
		}

		$this->apply( $request_id, $from, $to, $message, $actor_type, 'status_changed' );
	}

	/**
	 * Reopens a final request, restoring the status it had before closing.
	 *
	 * @param int $request_id Request ID.
	 * @throws RuntimeException When the request is not final.
	 * @return void
	 */
	public function reopen( int $request_id ): void {
		$from = $this->current_status( $request_id );
		if ( ! $from->can_reopen() ) {
			throw new RuntimeException( esc_html( __( 'Only requests closed without a refund, rejected or cancelled can be reopened.', 'primeplaced-withdrawal-button' ) ) );
		}

		$to = Status::New;
		foreach ( array_reverse( $this->events->for_request( $request_id ) ) as $event ) {
			if ( 'status_changed' === $event['type'] && ( $event['payload']['to'] ?? '' ) === $from->value ) {
				$to = Status::tryFrom( (string) ( $event['payload']['from'] ?? '' ) ) ?? Status::New;
				break;
			}
		}

		$this->apply( $request_id, $from, $to, '', EventLog::ACTOR_USER, 'reopened' );
	}

	/**
	 * Adds a private note or a note sent to the customer.
	 *
	 * @param int    $request_id  Request ID.
	 * @param string $note        Note text.
	 * @param bool   $to_customer Whether the customer gets it by email.
	 * @return void
	 */
	public function add_note( int $request_id, string $note, bool $to_customer ): void {
		$note = trim( $note );
		if ( '' === $note ) {
			return;
		}

		$this->events->add(
			$request_id,
			'note',
			array(
				'text'        => $note,
				'to_customer' => $to_customer,
			),
			EventLog::ACTOR_USER
		);

		if ( $to_customer ) {
			/**
			 * Fires when the shop adds a note for the customer.
			 *
			 * @param int    $request_id Request ID.
			 * @param string $note       Note text.
			 */
			do_action( 'ppwb_customer_note_added', $request_id, $note );
		}
	}

	/**
	 * Stores shipping details and moves the request to "in transit".
	 *
	 * @param int    $request_id Request ID.
	 * @param string $tracking   Tracking number.
	 * @param string $carrier    Carrier name.
	 * @param string $file       Private file name of the proof of shipment, or ''.
	 * @param string $actor_type EventLog actor type; "system" when a carrier scan reports the parcel.
	 * @return void
	 */
	public function add_shipping( int $request_id, string $tracking, string $carrier, string $file, string $actor_type = EventLog::ACTOR_CUSTOMER ): void {
		$this->requests->update(
			$request_id,
			array(
				'tracking_number'  => '' === $tracking ? null : $tracking,
				'tracking_carrier' => '' === $carrier ? null : $carrier,
			)
		);
		$this->events->add(
			$request_id,
			'shipping_added',
			array(
				'tracking' => $tracking,
				'carrier'  => $carrier,
				'file'     => $file,
			),
			$actor_type
		);

		$status = $this->current_status( $request_id );
		if ( $status->can_transition_to( Status::InTransit ) && Status::Received !== $status ) {
			$this->apply( $request_id, $status, Status::InTransit, '', $actor_type, 'status_changed' );
		}

		/**
		 * Fires when the customer adds shipping details or a carrier scan reports the parcel.
		 *
		 * @param int    $request_id Request ID.
		 * @param string $actor_type EventLog actor type of whoever added the details (customer or system).
		 */
		do_action( 'ppwb_tracking_added', $request_id, $actor_type );
	}

	/**
	 * Current status of a request.
	 *
	 * @param int $request_id Request ID.
	 * @throws RuntimeException When the request does not exist.
	 * @return Status
	 */
	public function current_status( int $request_id ): Status {
		$request = $this->requests->find( $request_id );
		$status  = null === $request ? null : Status::tryFrom( (string) $request['status'] );
		if ( null === $status ) {
			throw new RuntimeException( esc_html( __( 'Request not found.', 'primeplaced-withdrawal-button' ) ) );
		}

		return $status;
	}

	/**
	 * Writes the change, the history entry and an order note, then notifies listeners.
	 *
	 * @param int    $request_id Request ID.
	 * @param Status $from       Previous status.
	 * @param Status $to         New status.
	 * @param string $message    Reason or note.
	 * @param string $actor_type EventLog actor type.
	 * @param string $event      Event type.
	 * @return void
	 */
	private function apply( int $request_id, Status $from, Status $to, string $message, string $actor_type, string $event ): void {
		$this->requests->update( $request_id, array( 'status' => $to->value ) );
		$this->events->add(
			$request_id,
			$event,
			array(
				'from'    => $from->value,
				'to'      => $to->value,
				'message' => $message,
			),
			$actor_type
		);

		$request = $this->requests->find( $request_id );
		$order   = null === $request ? false : wc_get_order( (int) $request['order_id'] );
		// By default only key events reach the order notes; the rest stays in the request history.
		$key = in_array( $to, array( Status::Refunded, Status::Rejected, Status::Cancelled ), true );
		if ( $order instanceof WC_Order && null !== $request && ( $key || 'all' === $this->settings->get( 'order_notes' ) ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: request number (link), 2: new status. */
					__( 'Withdrawal request %1$s: %2$s', 'primeplaced-withdrawal-button' ),
					self::number_link( (int) $request['id'], (string) $request['number'] ),
					StatusView::label( $to )
				)
			);
		}

		/**
		 * Fires after a request changes status.
		 *
		 * @param int    $request_id Request ID.
		 * @param string $from       Previous status.
		 * @param string $to         New status.
		 * @param string $message    Reason or note for the customer.
		 */
		do_action( 'ppwb_request_status_changed', $request_id, $from->value, $to->value, $message );
	}

	/**
	 * Request number as a link to the request, for order notes. Order
	 * notes pass through wp_kses_post, so the link stays.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $number     Request number.
	 * @return string
	 */
	public static function number_link( int $request_id, string $number ): string {
		return sprintf( '<a href="%1$s">%2$s</a>', esc_url( RequestScreen::url( $request_id ) ), esc_html( $number ) );
	}
}
