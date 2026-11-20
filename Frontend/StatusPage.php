<?php
/**
 * Customer status page: [ppwb_status].
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Frontend;

use PrimePlaced\WithdrawalButton\Backend\RequestScreen;
use PrimePlaced\WithdrawalButton\Backend\StatusView;
use PrimePlaced\WithdrawalButton\Data\EventLog;
use PrimePlaced\WithdrawalButton\Data\RequestRepository;
use PrimePlaced\WithdrawalButton\Domain\ReturnCarriers;
use PrimePlaced\WithdrawalButton\Domain\Status;
use PrimePlaced\WithdrawalButton\Engine\Module;
use PrimePlaced\WithdrawalButton\Integrations\RateLimiter;
use PrimePlaced\WithdrawalButton\Integrations\RequestWorkflow;
use PrimePlaced\WithdrawalButton\Pdf\ConfirmationPdf;
use PrimePlaced\WithdrawalButton\Pdf\PrivateStorage;
use Throwable;
use WC_Order;

/**
 * Class StatusPage
 *
 * Access is granted by request number + email (rate limited, constant-time
 * email check) or by a signed link from an email. Every later action carries
 * that signed reference.
 */
final class StatusPage implements Module {

	public const PAGE_OPTION = 'ppwb_status_page_id';

	public const NONCE = 'ppwb_status';

	private const MAX_UPLOAD = 5 * MB_IN_BYTES;

	/**
	 * View prepared before output.
	 *
	 * @var array<string, mixed>
	 */
	private array $view = array( 'step' => 'lookup' );

	/**
	 * Creates the page.
	 *
	 * @param RequestRepository $requests Request storage.
	 * @param EventLog          $events   Request history.
	 * @param RequestWorkflow   $workflow Status changes.
	 * @param FormToken         $tokens   Signatures.
	 * @param RateLimiter       $limiter  Lookup limiter.
	 * @param PrivateStorage    $storage  Private files.
	 * @param ConfirmationPdf   $pdf      Confirmation PDF.
	 */
	public function __construct(
		private RequestRepository $requests,
		private EventLog $events,
		private RequestWorkflow $workflow,
		private FormToken $tokens,
		private RateLimiter $limiter,
		private PrivateStorage $storage,
		private ConfirmationPdf $pdf
	) {
	}

	/**
	 * Signed link to a request's status, used in emails.
	 *
	 * @param int $request_id Request ID.
	 * @return string
	 */
	public static function url_for( int $request_id ): string {
		$page = (int) get_option( self::PAGE_OPTION, 0 );
		$url  = $page > 0 ? get_permalink( $page ) : false;
		if ( ! is_string( $url ) ) {
			return home_url( '/' );
		}

		return add_query_arg( 'ppwb_r', self::reference( $request_id ), $url );
	}

	/**
	 * Signed reference "ID.signature".
	 *
	 * @param int $request_id Request ID.
	 * @return string
	 */
	public static function reference( int $request_id ): string {
		return $request_id . '.' . ( new FormToken() )->sign( 'status|' . $request_id );
	}

	/**
	 * Adds the shortcode and handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'ppwb_status', array( $this, 'render' ) );
		add_action( 'template_redirect', array( $this, 'handle' ) );
	}

	/**
	 * Processes lookups, downloads and customer actions before output.
	 *
	 * @return void
	 */
	public function handle(): void {
		$page = (int) get_option( self::PAGE_OPTION, 0 );
		if ( $page <= 0 || ! is_page( $page ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Signed references; actions below verify a nonce.
		$reference = isset( $_REQUEST['ppwb_r'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ppwb_r'] ) ) : '';
		$download  = isset( $_GET['ppwb_pdf'] );
		// phpcs:enable

		if ( '' !== $reference ) {
			$request_id = $this->verify( $reference );
			if ( null === $request_id ) {
				$this->view = array(
					'step'  => 'lookup',
					'error' => __( 'This link is not valid. Enter the request number and email.', 'primeplaced-withdrawal-button' ),
				);
				return;
			}

			$request = $this->requests->find( $request_id );
			// No PDF after a GDPR erasure: the page shows what is left of the request.
			if ( $download && null !== $request && ! RequestRepository::is_anonymized( $request ) ) {
				RequestScreen::stream( $this->pdf->ensure( $request_id ), ConfirmationPdf::file_name( (string) ( $request['number'] ?? '' ) ) );
			}

			$error      = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ? $this->act( $request_id ) : '';
			$this->view = $this->details( $request_id, $error );
			return;
		}

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['ppwb_status_lookup'] ) ) {
			$nonce = isset( $_POST['_ppwb_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_ppwb_nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
				$this->view = array(
					'step'  => 'lookup',
					'error' => __( 'The form has expired. Please start again.', 'primeplaced-withdrawal-button' ),
				);
				return;
			}

			$this->view = $this->lookup(
				isset( $_POST['ppwb_number'] ) ? sanitize_text_field( wp_unslash( $_POST['ppwb_number'] ) ) : '',
				isset( $_POST['ppwb_email'] ) ? sanitize_email( wp_unslash( $_POST['ppwb_email'] ) ) : ''
			);
		}
	}

	/**
	 * Renders the current view.
	 *
	 * @return string
	 */
	public function render(): string {
		$view = $this->view + array(
			'page_url' => self::page_url(),
			'nonce'    => wp_nonce_field( self::NONCE, '_ppwb_nonce', false, false ),
			'error'    => '',
			'notice'   => '',
		);

		ob_start();
		Templates::render( 'status/' . ( is_string( $view['step'] ) ? $view['step'] : 'lookup' ), $view );

		return (string) ob_get_clean();
	}

	/**
	 * Status page URL.
	 *
	 * @return string
	 */
	public static function page_url(): string {
		$url = get_permalink( (int) get_option( self::PAGE_OPTION, 0 ) );

		return is_string( $url ) ? $url : home_url( '/' );
	}

	/**
	 * Number + email lookup.
	 *
	 * @param string $number Request number.
	 * @param string $email  Email.
	 * @return array<string, mixed>
	 */
	private function lookup( string $number, string $email ): array {
		$key = 'status|' . ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
		if ( $this->limiter->is_blocked( $key, 5 ) ) {
			return array(
				'step'  => 'lookup',
				'error' => __( 'Too many attempts. Please try again in a few minutes.', 'primeplaced-withdrawal-button' ),
			);
		}

		$request = $this->requests->find_by_number_and_email( $number, $email );
		if ( null === $request ) {
			$this->limiter->hit( $key, 900 );
			return array(
				'step'   => 'lookup',
				'error'  => __( 'We could not find a request for these details. Check the number and the email address.', 'primeplaced-withdrawal-button' ),
				'number' => $number,
				'email'  => $email,
			);
		}

		return $this->details( (int) $request['id'] );
	}

	/**
	 * Handles a customer action on a verified request.
	 *
	 * @param int $request_id Request ID.
	 * @return string Error message or ''.
	 */
	private function act( int $request_id ): string {
		$nonce = isset( $_POST['_ppwb_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_ppwb_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return __( 'The form has expired. Please start again.', 'primeplaced-withdrawal-button' );
		}

		$do = isset( $_POST['ppwb_do'] ) ? sanitize_key( wp_unslash( $_POST['ppwb_do'] ) ) : '';

		try {
			if ( 'cancel' === $do ) {
				if ( empty( $_POST['ppwb_confirm_cancel'] ) ) {
					return __( 'Tick the box to confirm that you want to withdraw the request.', 'primeplaced-withdrawal-button' );
				}
				$this->workflow->change_status( $request_id, Status::Cancelled, __( 'Withdrawn by the customer.', 'primeplaced-withdrawal-button' ), EventLog::ACTOR_CUSTOMER );
				return '';
			}

			if ( 'shipping' === $do ) {
				$tracking = isset( $_POST['ppwb_tracking'] ) ? sanitize_text_field( wp_unslash( $_POST['ppwb_tracking'] ) ) : '';
				$carrier  = isset( $_POST['ppwb_carrier'] ) ? sanitize_text_field( wp_unslash( $_POST['ppwb_carrier'] ) ) : '';
				$other    = isset( $_POST['ppwb_carrier_other'] ) ? sanitize_text_field( wp_unslash( $_POST['ppwb_carrier_other'] ) ) : '';
				if ( '' !== $other && ( '' === $carrier || __( 'Other', 'primeplaced-withdrawal-button' ) === $carrier ) ) {
					$carrier = $other;
				}
				$file = $this->store_upload();
				if ( '' === $tracking && '' === $file ) {
					return __( 'Enter a tracking number or attach a proof of shipment.', 'primeplaced-withdrawal-button' );
				}
				$this->workflow->add_shipping( $request_id, mb_substr( $tracking, 0, 64 ), mb_substr( $carrier, 0, 64 ), $file );
				return '';
			}

			if ( '' !== $do ) {
				/**
				 * Handles a customer action that Core does not know. The nonce and the
				 * signed reference are already verified; the request ID is trusted.
				 *
				 * @param string|null $error      Error message, '' on success, null when no handler took the action.
				 * @param string      $do         Action key from the form (ppwb_do).
				 * @param int         $request_id Request ID.
				 */
				$error = apply_filters( 'ppwb_status_page_action', null, $do, $request_id );
				return is_string( $error ) ? $error : '';
			}
		} catch ( \RuntimeException $error ) {
			// Messages of the plugin's own checks are written for the customer.
			return $error->getMessage();
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Unexpected failures must reach the server log, not the customer.
			error_log( '[primeplaced-withdrawal-button] Status page action failed: ' . $error->getMessage() );
			return __( 'Something went wrong. Please try again or contact the shop.', 'primeplaced-withdrawal-button' );
		}

		return '';
	}

	/**
	 * Saves an uploaded proof of shipment (jpg, png, pdf up to 5 MB).
	 *
	 * @throws \RuntimeException When the file is invalid.
	 * @return string Private file name or ''.
	 */
	private function store_upload(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in act(); path checked by is_uploaded_file().
		if ( empty( $_FILES['ppwb_proof']['tmp_name'] ) || ! is_uploaded_file( (string) $_FILES['ppwb_proof']['tmp_name'] ) ) {
			return '';
		}

		$file = $_FILES['ppwb_proof']; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated below.
		if ( (int) $file['size'] > self::MAX_UPLOAD ) {
			throw new \RuntimeException( esc_html( __( 'The file is too large (maximum 5 MB).', 'primeplaced-withdrawal-button' ) ) );
		}

		$allowed = array(
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'pdf'      => 'application/pdf',
		);
		$check   = wp_check_filetype_and_ext( (string) $file['tmp_name'], sanitize_file_name( (string) $file['name'] ), $allowed );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			throw new \RuntimeException( esc_html( __( 'Only JPG, PNG or PDF files are accepted.', 'primeplaced-withdrawal-button' ) ) );
		}

		return $this->storage->put( (string) file_get_contents( (string) $file['tmp_name'] ), (string) $check['ext'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Uploaded temp file.
	}

	/**
	 * Details view of a verified request.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $error      Error from an action.
	 * @return array<string, mixed>
	 */
	private function details( int $request_id, string $error = '' ): array {
		$request = $this->requests->find( $request_id );
		if ( null === $request ) {
			return array( 'step' => 'lookup' );
		}

		$status   = Status::tryFrom( (string) $request['status'] ) ?? Status::New;
		$timeline = array(
			array(
				'label' => StatusView::label( Status::New ),
				'time'  => (string) $request['submitted_at_gmt'],
			),
		);
		foreach ( $this->events->for_request( $request_id ) as $event ) {
			if ( in_array( $event['type'], array( 'status_changed', 'reopened' ), true ) ) {
				$to         = Status::tryFrom( (string) ( $event['payload']['to'] ?? '' ) );
				$timeline[] = array(
					'label' => null === $to ? '' : StatusView::label( $to ),
					'time'  => $event['created_at_gmt'],
					'note'  => (string) ( $event['payload']['message'] ?? '' ),
				);
			}
		}

		$reference = self::reference( $request_id );

		return array(
			'step'         => 'details',
			'request'      => $request,
			'status_label' => StatusView::label( $status ),
			'items'        => $this->requests->items( $request_id ),
			'timeline'     => $timeline,
			'reference'    => $reference,
			'pdf_url'      => add_query_arg(
				array(
					'ppwb_r'   => $reference,
					'ppwb_pdf' => 1,
				),
				self::page_url()
			),
			'can_cancel'   => $status->can_transition_to( Status::Cancelled ),
			'can_ship'     => in_array( $status, array( Status::New, Status::InTransit ), true ),
			'carriers'     => $this->carriers( $request_id, (int) $request['order_id'] ),
			'error'        => $error,
		);
	}

	/**
	 * Carriers for the shipping form, for the country the customer sends from.
	 *
	 * @param int $request_id Request ID.
	 * @param int $order_id   Order ID.
	 * @return list<string>
	 */
	private function carriers( int $request_id, int $order_id ): array {
		$order   = wc_get_order( $order_id );
		$country = '';
		if ( $order instanceof WC_Order ) {
			$country = '' === $order->get_shipping_country() ? $order->get_billing_country() : $order->get_shipping_country();
		}
		if ( '' === $country ) {
			$country = WC()->countries->get_base_country();
		}

		/**
		 * Filters the carriers the customer can pick on the status page; "Other" is always added after them.
		 *
		 * @param list<string> $carriers   Carrier names, national ones first.
		 * @param string       $country    Country the customer sends from (ISO 3166-1 alpha-2).
		 * @param int          $request_id Request ID.
		 */
		$carriers = apply_filters( 'ppwb_return_carriers', ReturnCarriers::for_country( $country ), $country, $request_id );

		return array_values( array_filter( array_map( 'strval', (array) $carriers ), static fn ( string $name ): bool => '' !== trim( $name ) ) );
	}

	/**
	 * Verifies a signed reference.
	 *
	 * @param string $reference "ID.signature".
	 * @return int|null
	 */
	private function verify( string $reference ): ?int {
		$parts = explode( '.', $reference, 2 );
		if ( 2 !== count( $parts ) || ! hash_equals( $this->tokens->sign( 'status|' . (int) $parts[0] ), $parts[1] ) ) {
			return null;
		}

		return (int) $parts[0];
	}
}
