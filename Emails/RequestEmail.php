<?php
/**
 * WooCommerce email about a withdrawal request.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Emails;

use PrimePlaced\WithdrawalButton\Data\RequestRepository;
use PrimePlaced\WithdrawalButton\Data\Settings;
use PrimePlaced\WithdrawalButton\Engine\Container;
use PrimePlaced\WithdrawalButton\Integrations\Dates;
use PrimePlaced\WithdrawalButton\Integrations\RefundService;
use PrimePlaced\WithdrawalButton\Integrations\WithdrawalService;
use PrimePlaced\WithdrawalButton\Pdf\ConfirmationPdf;
use PrimePlaced\WithdrawalButton\Pdf\PrivateStorage;
use Throwable;
use WC_Email;
use WC_Order;

/**
 * Class RequestEmail
 *
 * One class, several instances (confirmation, status changes, shop notices),
 * each with its own WooCommerce email settings. Loaded only from the
 * woocommerce_email_classes filter, when WC_Email exists.
 */
final class RequestEmail extends WC_Email {

	/**
	 * Request row for the current send.
	 *
	 * @var array<string, string|int|null>
	 */
	public array $request = array();

	/**
	 * Extra template data (note text, refund amount, reason).
	 *
	 * @var array<string, string>
	 */
	public array $extra = array();

	/**
	 * Intro paragraph shown above the request summary.
	 *
	 * @var string
	 */
	private string $intro;

	/**
	 * Intro for a cancellation before shipment, '' to use the regular one.
	 *
	 * @var string
	 */
	private string $intro_before_shipment;

	/**
	 * Default subject from the definition.
	 *
	 * @var string
	 */
	private string $subject_default = '';

	/**
	 * Default heading from the definition.
	 *
	 * @var string
	 */
	private string $heading_default = '';

	/**
	 * Whether the confirmation PDF is attached.
	 *
	 * @var bool
	 */
	private bool $attach_pdf;

	/**
	 * Creates one email type.
	 *
	 * @param array{id: string, title: string, description: string, subject: string, heading: string, intro: string, intro_before_shipment?: string, to_shop?: bool, attach_pdf?: bool, statement?: bool} $config Email definition.
	 */
	public function __construct( array $config ) {
		$this->id                      = $config['id'];
		$this->title                   = $config['title'];
		$this->description             = $config['description'];
		$this->customer_email          = empty( $config['to_shop'] );
		$this->intro                   = $config['intro'];
		$this->intro_before_shipment   = $config['intro_before_shipment'] ?? '';
		$this->attach_pdf              = ! empty( $config['attach_pdf'] );
		$this->template_html           = 'emails/request.php';
		$this->template_plain          = 'emails/plain/request.php';
		$this->template_base           = PPWB_PLUGIN_ROOT . 'templates/';
		$this->placeholders            = array(
			'{request_number}'      => '',
			'{order_number}'        => '',
			'{customer_name}'       => '',
			'{customer_first_name}' => '',
			'{refund_method}'       => '',
			'{refund_amount}'       => '',
			'{submitted_at}'        => '',
			'{return_address}'      => '',
			'{return_cost}'         => '',
			'{refund_due}'          => '',
		);
		$this->extra['show_statement'] = empty( $config['statement'] ) ? '' : '1';

		$this->subject_default = $config['subject'];
		$this->heading_default = $config['heading'];

		parent::__construct();

		if ( ! $this->customer_email ) {
			$this->recipient = $this->get_option( 'recipient', '' );
			if ( '' === trim( (string) $this->recipient ) ) {
				$this->recipient = ( new Settings() )->shop_email();
			}
		}
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject(): string {
		return $this->subject_default;
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading(): string {
		return $this->heading_default;
	}

	/**
	 * Sends the email for a request.
	 *
	 * @param int                   $request_id Request ID.
	 * @param array<string, string> $extra      Extra template data.
	 * @return bool Whether wp_mail reported success (true when the email is disabled).
	 */
	public function trigger( int $request_id, array $extra = array() ): bool {
		$this->setup_locale();

		$request = ( new RequestRepository() )->find( (int) $request_id );
		if ( null === $request || ! $this->is_enabled() ) {
			$this->restore_locale();
			return true;
		}

		$this->request = $request;
		$this->extra   = array_merge( $this->extra, $extra );
		$this->object  = wc_get_order( (int) $request['order_id'] );

		$this->placeholders['{request_number}'] = (string) $request['number'];
		$this->placeholders['{order_number}']   = $this->object instanceof WC_Order ? (string) $this->object->get_order_number() : (string) $request['order_id'];
		$this->placeholders['{customer_name}']  = (string) $request['full_name'];
		$this->placeholders['{submitted_at}']   = Dates::datetime_gmt( (string) $request['submitted_at_gmt'] );
		$this->placeholders['{return_address}'] = ( new Settings() )->return_address_text();
		$this->placeholders['{return_cost}']    = ( new Settings() )->return_cost_text();
		$this->placeholders['{refund_due}']     = Dates::date_gmt( (string) $request['refund_due_gmt'] );

		// The customer reads how and how much comes back, and is greeted by first name.
		$first_name                                  = $this->object instanceof WC_Order ? trim( (string) $this->object->get_billing_first_name() ) : '';
		$this->placeholders['{customer_first_name}'] = '' !== $first_name ? $first_name : (string) $request['full_name'];
		$this->placeholders['{refund_method}']       = $this->object instanceof WC_Order
			? ( new Container() )->get( RefundService::class )->customer_method_label( $this->object, ! empty( $request['bank_account'] ) )
			: '';
		$this->placeholders['{refund_amount}']       = isset( $request['estimated_total'] )
			? html_entity_decode( wp_strip_all_tags( wc_price( (float) $request['estimated_total'], array( 'currency' => (string) $request['currency'] ) ) ), ENT_QUOTES, 'UTF-8' )
			: '';

		if ( $this->customer_email ) {
			$this->recipient = (string) $request['email'];
		}

		$sent = $this->get_recipient()
			? (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() )
			: false;

		$this->restore_locale();

		return $sent;
	}

	/**
	 * PDF attachment for the confirmation email.
	 *
	 * @return array<int, string>
	 */
	public function get_attachments(): array {
		$attachments = parent::get_attachments();

		if ( $this->attach_pdf && isset( $this->request['id'] ) ) {
			try {
				$attachments[] = ( new ConfirmationPdf( new RequestRepository(), new PrivateStorage(), new Settings() ) )->attachment( (int) $this->request['id'] );
			} catch ( Throwable $error ) {
				// The email still carries the full statement text; the PDF stays available on the status page.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- PDF failures must reach the server log.
				error_log( '[primeplaced-withdrawal-button] PDF generation failed: ' . $error->getMessage() );
			}
		}

		return $attachments;
	}

	/**
	 * HTML content.
	 *
	 * @return string
	 */
	public function get_content_html(): string {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), 'primeplaced-withdrawal-button/', $this->template_base );
	}

	/**
	 * Plain text content.
	 *
	 * @return string
	 */
	public function get_content_plain(): string {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), 'primeplaced-withdrawal-button/', $this->template_base );
	}

	/**
	 * Settings fields; shop emails also get a recipient field.
	 *
	 * @return void
	 */
	public function init_form_fields(): void {
		parent::init_form_fields();

		// The confirmation is the durable medium the law requires: it cannot be switched off.
		if ( Mailer::CONFIRMATION === $this->id ) {
			$this->form_fields['enabled'] = array(
				'title'       => __( 'Enable/Disable', 'primeplaced-withdrawal-button' ),
				'type'        => 'title',
				'description' => __( 'This email is always sent: it is the confirmation on a durable medium required by law. You can change the subject, heading and additional content; the statement text, its date and time and the request number are always included.', 'primeplaced-withdrawal-button' ),
			);
		}

		if ( ! $this->customer_email ) {
			$this->form_fields = array_merge(
				array_slice( $this->form_fields, 0, 1, true ),
				array(
					'recipient' => array(
						'title'       => __( 'Recipient(s)', 'primeplaced-withdrawal-button' ),
						'type'        => 'text',
						/* translators: %s: admin email. */
						'description' => sprintf( __( 'Enter recipients (comma separated). Defaults to %s.', 'primeplaced-withdrawal-button' ), esc_html( (string) get_option( 'admin_email' ) ) ),
						'placeholder' => '',
						'default'     => '',
						'desc_tip'    => true,
					),
				),
				array_slice( $this->form_fields, 1, null, true )
			);
		}
	}

	/**
	 * The confirmation is always enabled; other emails follow their setting.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return Mailer::CONFIRMATION === $this->id ? true : parent::is_enabled();
	}

	/**
	 * Whether the current request is a cancellation before shipment.
	 *
	 * @return bool
	 */
	private function is_before_shipment(): bool {
		return in_array( WithdrawalService::BEFORE_SHIPMENT, explode( ',', (string) ( $this->request['flags'] ?? '' ) ), true );
	}

	/**
	 * Template variables.
	 *
	 * @param bool $plain_text Plain text variant.
	 * @return array<string, mixed>
	 */
	private function template_args( bool $plain_text ): array {
		$request_id = (int) ( $this->request['id'] ?? 0 );

		// The shop email shows the estimated amount, the refund method and links the order.
		if ( ! $this->customer_email && $this->object instanceof WC_Order && isset( $this->request['estimated_total'] ) ) {
			$this->extra['estimated'] = wp_strip_all_tags( wc_price( (float) $this->request['estimated_total'], array( 'currency' => (string) $this->request['currency'] ) ) );
			$this->extra['method']    = ( new Container() )->get( RefundService::class )->method_label( $this->object );
			$this->extra['order_url'] = $this->object->get_edit_order_url();
		}

		return array(
			'email'              => $this,
			'email_heading'      => $this->get_heading(),
			'intro'              => $this->format_string( '' !== $this->intro_before_shipment && $this->is_before_shipment() ? $this->intro_before_shipment : $this->intro ),
			'request'            => $this->request,
			'items'              => $request_id > 0 ? ( new RequestRepository() )->items( $request_id ) : array(),
			'order'              => $this->object,
			'extra'              => $this->extra,
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => ! $this->customer_email,
			'plain_text'         => $plain_text,
		);
	}
}
