<?php
/**
 * Registers the plugin's WooCommerce emails and sends them on request events.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Emails;

use PrimePlaced\WithdrawalButton\Backend\RequestScreen;
use PrimePlaced\WithdrawalButton\Data\EventLog;
use PrimePlaced\WithdrawalButton\Data\RequestRepository;
use PrimePlaced\WithdrawalButton\Data\Settings;
use PrimePlaced\WithdrawalButton\Domain\Status;
use PrimePlaced\WithdrawalButton\Engine\Module;
use PrimePlaced\WithdrawalButton\Frontend\StatusPage;
use PrimePlaced\WithdrawalButton\Integrations\RefundService;

/**
 * Class Mailer
 *
 * A failed confirmation never loses the statement: the request stays stored,
 * gets the "action_required" flag and a history entry.
 */
final class Mailer implements Module {

	public const CONFIRMATION  = 'ppwb_customer_confirmation';
	public const NEW_REQUEST   = 'ppwb_new_request';
	public const CUSTOMER_NOTE = 'ppwb_customer_note';
	public const TRACKING      = 'ppwb_tracking_added';
	public const REMINDER      = 'ppwb_refund_reminder';
	public const REMINDER_HOOK = 'ppwb_daily_refund_reminder';

	/**
	 * Customer email per status.
	 *
	 * @var array<string, string>
	 */
	private const STATUS_EMAILS = array(
		'received'  => 'ppwb_customer_received',
		'refunded'  => 'ppwb_customer_refunded',
		'rejected'  => 'ppwb_customer_rejected',
		'cancelled' => 'ppwb_customer_cancelled',
	);

	/**
	 * Creates the mailer.
	 *
	 * @param RequestRepository $requests Request storage.
	 * @param EventLog          $events   Request history.
	 */
	public function __construct(
		private RequestRepository $requests,
		private EventLog $events
	) {
	}

	/**
	 * Adds the email classes and the event listeners.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_email_classes', array( $this, 'email_classes' ) );
		add_action( 'ppwb_request_created', array( $this, 'on_created' ) );
		add_action( 'ppwb_request_status_changed', array( $this, 'on_status_changed' ), 10, 4 );
		add_action( 'ppwb_customer_note_added', array( $this, 'on_customer_note' ), 10, 2 );
		add_action( 'ppwb_tracking_added', array( $this, 'on_tracking_added' ), 10, 2 );
		add_action( self::REMINDER_HOOK, array( $this, 'send_reminder' ) );
		add_filter( 'woocommerce_email_log_add_order_note', array( $this, 'email_order_note' ), 10, 2 );
		add_filter( 'woocommerce_email_enabled_customer_refunded_order', array( $this, 'woocommerce_refund_email' ), 10, 3 );
		// Activation schedules it; the admin restores it when it went missing.
		add_action( 'admin_init', array( self::class, 'schedule_daily' ) );
	}

	/**
	 * Schedules the daily task at 08:00 shop time, unless it is scheduled.
	 *
	 * @return void
	 */
	public static function schedule_daily(): void {
		// Before its data store is ready Action Scheduler only warns; admin_init retries.
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! did_action( 'action_scheduler_init' ) || as_has_scheduled_action( self::REMINDER_HOOK, array(), 'ppwb' ) ) {
			return;
		}

		$first = new \DateTimeImmutable( 'tomorrow 08:00', wp_timezone() );
		as_schedule_recurring_action( $first->getTimestamp(), DAY_IN_SECONDS, self::REMINDER_HOOK, array(), 'ppwb' );
	}

	/**
	 * Next run of the daily task, or null when it is not scheduled.
	 *
	 * @return int|null Unix timestamp.
	 */
	public static function next_reminder(): ?int {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return null;
		}
		$next = as_next_scheduled_action( self::REMINDER_HOOK, array(), 'ppwb' );

		return is_int( $next ) ? $next : null;
	}

	/**
	 * Deletes double-submit locks of requests stored more than $older_than
	 * seconds ago; 0 deletes every lock (uninstall).
	 *
	 * @param int $older_than Age in seconds.
	 * @return void
	 */
	public static function delete_submission_locks( int $older_than = HOUR_IN_SECONDS ): void {
		global $wpdb;

		$like = $wpdb->esc_like( 'ppwb_submission_' ) . '%';
		if ( 0 === $older_than ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup of our own options.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
			return;
		}

		// A lock holds the request ID once the request exists; "0" means a submission in progress and stays.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup of our own options.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE o FROM {$wpdb->options} o JOIN %i r ON r.id = CAST(o.option_value AS UNSIGNED) WHERE o.option_name LIKE %s AND r.created_at_gmt < %s",
				$wpdb->prefix . 'ppwb_requests',
				$like,
				gmdate( 'Y-m-d H:i:s', time() - $older_than )
			)
		);
	}

	/**
	 * Daily task: refund reminder for the shop and cleanup.
	 *
	 * @return void
	 */
	public function send_reminder(): void {
		self::delete_submission_locks();

		$due = $this->requests->refund_due_within( (int) ( new Settings() )->get( 'refund_reminder_days' ) );
		if ( array() === $due ) {
			return;
		}

		$emails = WC()->mailer()->get_emails();
		$email  = $emails[ self::REMINDER ] ?? null;
		if ( $email instanceof RefundReminderEmail ) {
			$email->trigger( $due );
		}
	}

	/**
	 * Email definitions, in English source.
	 *
	 * @return array<string, array{id: string, title: string, description: string, subject: string, heading: string, intro: string, intro_before_shipment?: string, to_shop?: bool, attach_pdf?: bool, statement?: bool}>
	 */
	public static function definitions(): array {
		$definitions = array(
			self::CONFIRMATION        => array(
				'id'                    => self::CONFIRMATION,
				'title'                 => __( 'Withdrawal confirmation', 'primeplaced-withdrawal-button' ),
				'description'           => __( 'Sent to the customer right after the withdrawal statement, with the PDF confirmation.', 'primeplaced-withdrawal-button' ),
				'subject'               => __( '[{request_number}] We confirm receipt of your statement of withdrawal from the contract', 'primeplaced-withdrawal-button' ),
				'heading'               => __( 'We have received your statement', 'primeplaced-withdrawal-button' ),
				'intro'                 => __( "Hi {customer_first_name},\n\nwe confirm that on {submitted_at} we received your statement of withdrawal from the contract.\n\nWhat next: send the products back within 14 days to {return_address}. {return_cost} We will refund the payment by {refund_due} at the latest, {refund_method}. Estimated refund: {refund_amount}.\n\nThe PDF confirmation is attached.", 'primeplaced-withdrawal-button' ),
				'intro_before_shipment' => __( "Hi {customer_first_name},\n\nwe confirm that on {submitted_at} we received your statement of withdrawal from the contract. We have not shipped the order yet, so we will cancel it and refund the payment by {refund_due} at the latest, {refund_method}. Estimated refund: {refund_amount}.\n\nIf the parcel has already left, refuse to accept it or send it back to {return_address}.\n\nThe PDF confirmation is attached.", 'primeplaced-withdrawal-button' ),
				'attach_pdf'            => true,
				'statement'             => true,
			),
			'ppwb_customer_received'  => array(
				'id'          => 'ppwb_customer_received',
				'title'       => __( 'Return parcel received', 'primeplaced-withdrawal-button' ),
				'description' => __( 'Sent when the returned products arrive at the shop.', 'primeplaced-withdrawal-button' ),
				'subject'     => __( '[{request_number}] Your parcel has reached us', 'primeplaced-withdrawal-button' ),
				'heading'     => __( 'Your parcel has reached us', 'primeplaced-withdrawal-button' ),
				'intro'       => __( "Hi {customer_first_name},\n\nyour parcel has reached us. We will refund the payment by {refund_due} at the latest.", 'primeplaced-withdrawal-button' ),
			),
			'ppwb_customer_refunded'  => array(
				'id'          => 'ppwb_customer_refunded',
				'title'       => __( 'Withdrawal refunded', 'primeplaced-withdrawal-button' ),
				'description' => __( 'Sent when the payment is refunded, with the amount and any deduction reason.', 'primeplaced-withdrawal-button' ),
				'subject'     => __( '[{request_number}] We have refunded your payment', 'primeplaced-withdrawal-button' ),
				'heading'     => __( 'We have refunded your payment', 'primeplaced-withdrawal-button' ),
				'intro'       => __( "Hi {customer_first_name},\n\nwe have refunded the payment for your withdrawal request, {refund_method}.", 'primeplaced-withdrawal-button' ),
			),
			'ppwb_customer_rejected'  => array(
				'id'          => 'ppwb_customer_rejected',
				'title'       => __( 'Withdrawal rejected', 'primeplaced-withdrawal-button' ),
				'description' => __( 'Sent when the shop rejects the request, with the reason.', 'primeplaced-withdrawal-button' ),
				'subject'     => __( '[{request_number}] Information about your request', 'primeplaced-withdrawal-button' ),
				'heading'     => __( 'Information about your request', 'primeplaced-withdrawal-button' ),
				'intro'       => __( "Hi {customer_first_name},\n\nwe could not accept your withdrawal request. The reason is below. If you have questions, reply to this email.", 'primeplaced-withdrawal-button' ),
			),
			'ppwb_customer_cancelled' => array(
				'id'          => 'ppwb_customer_cancelled',
				'title'       => __( 'Withdrawal cancelled', 'primeplaced-withdrawal-button' ),
				'description' => __( 'Sent when the request is withdrawn by the customer or cancelled by the shop.', 'primeplaced-withdrawal-button' ),
				'subject'     => __( '[{request_number}] Your request has been cancelled', 'primeplaced-withdrawal-button' ),
				'heading'     => __( 'Your request has been cancelled', 'primeplaced-withdrawal-button' ),
				'intro'       => __( "Hi {customer_first_name},\n\nyour withdrawal request has been cancelled.", 'primeplaced-withdrawal-button' ),
			),
			self::CUSTOMER_NOTE       => array(
				'id'          => self::CUSTOMER_NOTE,
				'title'       => __( 'Message about a return', 'primeplaced-withdrawal-button' ),
				'description' => __( 'Sent when the shop adds a note to the customer.', 'primeplaced-withdrawal-button' ),
				'subject'     => __( '[{request_number}] A message about your return', 'primeplaced-withdrawal-button' ),
				'heading'     => __( 'A message about your return', 'primeplaced-withdrawal-button' ),
				'intro'       => __( 'Hi {customer_first_name},', 'primeplaced-withdrawal-button' ),
			),
			self::NEW_REQUEST         => array(
				'id'                    => self::NEW_REQUEST,
				'title'                 => __( 'New withdrawal request', 'primeplaced-withdrawal-button' ),
				'description'           => __( 'Sent to the shop when a customer withdraws from a contract.', 'primeplaced-withdrawal-button' ),
				'subject'               => __( '[{request_number}] New withdrawal from contract, order {order_number}', 'primeplaced-withdrawal-button' ),
				'heading'               => __( 'New withdrawal request', 'primeplaced-withdrawal-button' ),
				'intro'                 => __( '{customer_name} has withdrawn from the contract for order {order_number}. Refund the payment by {refund_due} at the latest.', 'primeplaced-withdrawal-button' ),
				'intro_before_shipment' => __( '{customer_name} has cancelled order {order_number} before shipment. Do not ship the order. Cancel it and refund the payment by {refund_due} at the latest.', 'primeplaced-withdrawal-button' ),
				'to_shop'               => true,
				'statement'             => true,
			),
			self::TRACKING            => array(
				'id'          => self::TRACKING,
				'title'       => __( 'Return tracking number added', 'primeplaced-withdrawal-button' ),
				'description' => __( 'Sent to the shop when a customer adds a tracking number or proof of shipment.', 'primeplaced-withdrawal-button' ),
				'subject'     => __( '[{request_number}] The customer has sent the parcel', 'primeplaced-withdrawal-button' ),
				'heading'     => __( 'The customer has sent the parcel', 'primeplaced-withdrawal-button' ),
				'intro'       => __( '{customer_name} has added shipping details for the return.', 'primeplaced-withdrawal-button' ),
				'to_shop'     => true,
			),
		);

		/**
		 * Adds request emails. Each definition becomes a RequestEmail instance with its
		 * own WooCommerce settings and can be sent with Mailer::send().
		 *
		 * @param array<string, array{id: string, title: string, description: string, subject: string, heading: string, intro: string, intro_before_shipment?: string, to_shop?: bool, attach_pdf?: bool, statement?: bool}> $definitions Definitions keyed by email ID.
		 */
		return (array) apply_filters( 'ppwb_email_definitions', $definitions );
	}

	/**
	 * Adds the email instances to WooCommerce.
	 *
	 * @param array<string, object> $emails WooCommerce emails.
	 * @return array<string, object>
	 */
	public function email_classes( array $emails ): array {
		foreach ( self::definitions() as $key => $definition ) {
			$emails[ $key ] = new RequestEmail( $definition );
		}
		$emails[ self::REMINDER ] = new RefundReminderEmail();

		return $emails;
	}

	/**
	 * Sends one email and records the outcome.
	 *
	 * @param string                $key        Email key.
	 * @param int                   $request_id Request ID.
	 * @param array<string, string> $extra      Template data.
	 * @return bool
	 */
	public function send( string $key, int $request_id, array $extra = array() ): bool {
		$emails = WC()->mailer()->get_emails();
		$email  = $emails[ $key ] ?? null;
		if ( ! $email instanceof RequestEmail ) {
			return false;
		}

		$sent = $email->trigger( $request_id, $extra );
		$this->events->add( $request_id, $sent ? 'email_sent' : 'email_failed', array( 'email' => $key ) );

		return $sent;
	}

	/**
	 * Confirmation to the customer and notice to the shop.
	 *
	 * @param int $request_id Request ID.
	 * @return void
	 */
	public function on_created( int $request_id ): void {
		$sent = $this->send( self::CONFIRMATION, $request_id, array( 'status_url' => StatusPage::url_for( $request_id ) ) );
		if ( ! $sent ) {
			$this->requests->set_flag( $request_id, 'action_required', true );
		}

		$this->send( self::NEW_REQUEST, $request_id, array( 'admin_url' => RequestScreen::url( $request_id ) ) );
	}

	/**
	 * Customer email for statuses that have one.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $from       Previous status.
	 * @param string $to         New status.
	 * @param string $message    Reason or note shown to the customer.
	 * @return void
	 */
	public function on_status_changed( int $request_id, string $from, string $to, string $message = '' ): void {
		unset( $from );
		$key = self::STATUS_EMAILS[ $to ] ?? null;
		if ( null === $key ) {
			return;
		}
		// By default the customer gets only the WooCommerce "Refunded order" email.
		// When the shop switched that email off, the plugin email goes out instead.
		if ( 'ppwb_customer_refunded' === $key && 'woocommerce' === ( new Settings() )->get( 'refund_email' ) && $this->woocommerce_refund_email_enabled() ) {
			return;
		}

		$extra = array(
			'message'    => $message,
			'status_url' => StatusPage::url_for( $request_id ),
		);

		$request = $this->requests->find( $request_id );
		if ( Status::Refunded->value === $to && null !== $request ) {
			$extra['amount'] = wp_strip_all_tags( wc_price( (float) $request['refunded_total'], array( 'currency' => (string) $request['currency'] ) ) );
		}

		$this->send( $key, $request_id, $extra );
	}

	/**
	 * Whether WooCommerce sends its "Refunded order" email.
	 *
	 * @return bool
	 */
	private function woocommerce_refund_email_enabled(): bool {
		$email = WC()->mailer()->get_emails()['WC_Email_Customer_Refunded_Order'] ?? null;

		return $email instanceof \WC_Email && $email->is_enabled();
	}

	/**
	 * Keeps "email sent" notes of plugin emails out of the order notes;
	 * the request history records them.
	 *
	 * @param mixed $add      Whether WooCommerce adds the note.
	 * @param mixed $email_id Email ID.
	 * @return mixed
	 */
	public function email_order_note( mixed $add, mixed $email_id ): mixed {
		if ( is_string( $email_id ) && str_starts_with( $email_id, 'ppwb_' ) && 'all' !== ( new Settings() )->get( 'order_notes' ) ) {
			return false;
		}

		return $add;
	}

	/**
	 * WooCommerce "Refunded order" email for a refund made by the plugin:
	 * off when the shop chose the plugin email only.
	 *
	 * @param mixed $enabled Whether the email is enabled.
	 * @param mixed $order   Order.
	 * @param mixed $email   Email object.
	 * @return mixed
	 */
	public function woocommerce_refund_email( mixed $enabled, mixed $order = null, mixed $email = null ): mixed {
		unset( $order );
		if ( 'plugin' !== ( new Settings() )->get( 'refund_email' ) || ! is_object( $email ) || ! isset( $email->refund ) || ! $email->refund instanceof \WC_Order_Refund ) {
			return $enabled;
		}

		return '' === (string) $email->refund->get_meta( RefundService::REQUEST_META ) ? $enabled : false;
	}

	/**
	 * Note to the customer.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $note       Note text.
	 * @return void
	 */
	public function on_customer_note( int $request_id, string $note ): void {
		$this->send(
			self::CUSTOMER_NOTE,
			$request_id,
			array(
				'message'    => $note,
				'status_url' => StatusPage::url_for( $request_id ),
			)
		);
	}

	/**
	 * Shop notice about shipping details from the customer. Details a carrier
	 * scan added (system actor) do not email the shop.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $actor_type EventLog actor type of whoever added the details.
	 * @return void
	 */
	public function on_tracking_added( int $request_id, string $actor_type = EventLog::ACTOR_CUSTOMER ): void {
		if ( EventLog::ACTOR_SYSTEM === $actor_type ) {
			return;
		}

		$this->send( self::TRACKING, $request_id, array( 'admin_url' => RequestScreen::url( $request_id ) ) );
	}
}
