<?php
/**
 * Daily reminder about refunds close to the statutory 14-day deadline.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Emails;

use PrimePlaced\WithdrawalButton\Backend\RequestScreen;
use WC_Email;

/**
 * Class RefundReminderEmail
 *
 * Loaded only from the woocommerce_email_classes filter.
 */
final class RefundReminderEmail extends WC_Email {

	/**
	 * Requests listed in the current send.
	 *
	 * @var list<array<string, string|int|null>>
	 */
	public array $due = array();

	/**
	 * Defines the email.
	 */
	public function __construct() {
		$this->id             = Mailer::REMINDER;
		$this->title          = __( 'Refund deadline reminder', 'primeplaced-withdrawal-button' );
		$this->description    = __( 'Daily summary of withdrawal requests whose refund deadline is close or has passed.', 'primeplaced-withdrawal-button' );
		$this->customer_email = false;
		$this->template_html  = 'emails/refund-reminder.php';
		$this->template_plain = 'emails/plain/refund-reminder.php';
		$this->template_base  = PPWB_PLUGIN_ROOT . 'templates/';

		parent::__construct();

		$this->recipient = $this->get_option( 'recipient', '' );
		if ( '' === trim( (string) $this->recipient ) ) {
			$this->recipient = ( new \PrimePlaced\WithdrawalButton\Data\Settings() )->shop_email();
		}
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject(): string {
		return __( 'Refunds due soon: {count} withdrawal requests', 'primeplaced-withdrawal-button' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading(): string {
		return __( 'Refunds due soon', 'primeplaced-withdrawal-button' );
	}

	/**
	 * Sends the summary when there is anything to report.
	 *
	 * @param list<array<string, string|int|null>> $due Requests due soon.
	 * @return bool
	 */
	public function trigger( array $due ): bool {
		if ( array() === $due || ! $this->is_enabled() || ! $this->get_recipient() ) {
			return false;
		}

		$this->setup_locale();
		$this->due                     = $due;
		$this->placeholders['{count}'] = (string) count( $due );
		$sent                          = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), array() );
		$this->restore_locale();

		return $sent;
	}

	/**
	 * HTML content.
	 *
	 * @return string
	 */
	public function get_content_html(): string {
		return wc_get_template_html( $this->template_html, $this->args( false ), 'primeplaced-withdrawal-button/', $this->template_base );
	}

	/**
	 * Plain content.
	 *
	 * @return string
	 */
	public function get_content_plain(): string {
		return wc_get_template_html( $this->template_plain, $this->args( true ), 'primeplaced-withdrawal-button/', $this->template_base );
	}

	/**
	 * Settings fields with a recipient.
	 *
	 * @return void
	 */
	public function init_form_fields(): void {
		parent::init_form_fields();
		$this->form_fields = array_merge(
			array_slice( $this->form_fields, 0, 1, true ),
			array(
				'recipient' => array(
					'title'   => __( 'Recipient(s)', 'primeplaced-withdrawal-button' ),
					'type'    => 'text',
					'default' => '',
				),
			),
			array_slice( $this->form_fields, 1, null, true )
		);
	}

	/**
	 * Template variables.
	 *
	 * @param bool $plain_text Plain text variant.
	 * @return array<string, mixed>
	 */
	private function args( bool $plain_text ): array {
		$rows = array();
		foreach ( $this->due as $request ) {
			$due    = (int) strtotime( $request['refund_due_gmt'] . ' UTC' );
			$rows[] = array(
				'number' => (string) $request['number'],
				'name'   => (string) $request['full_name'],
				'due'    => \PrimePlaced\WithdrawalButton\Integrations\Dates::date( $due ),
				'late'   => $due < time(),
				'url'    => RequestScreen::url( (int) $request['id'] ),
			);
		}

		return array(
			'email'         => $this,
			'email_heading' => $this->get_heading(),
			'rows'          => $rows,
			'sent_to_admin' => true,
			'plain_text'    => $plain_text,
		);
	}
}
