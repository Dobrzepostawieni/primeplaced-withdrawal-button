<?php
/**
 * Confirmation PDF on a durable medium.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Pdf;

use PrimePlaced\WithdrawalButton\Data\RequestRepository;
use PrimePlaced\WithdrawalButton\Data\Settings;
use PrimePlaced\WithdrawalButton\Frontend\Templates;
use PrimePlaced\WithdrawalButton\Vendor\Dompdf\Dompdf;
use PrimePlaced\WithdrawalButton\Vendor\Dompdf\Options;
use RuntimeException;
use WC_Order;

/**
 * Class ConfirmationPdf
 *
 * Generated once at submission and stored; later downloads serve the same
 * file, so the document never changes.
 */
final class ConfirmationPdf {

	/**
	 * Creates the generator.
	 *
	 * @param RequestRepository $requests Request storage.
	 * @param PrivateStorage    $storage  Private file storage.
	 * @param Settings          $settings Plugin settings.
	 */
	public function __construct(
		private RequestRepository $requests,
		private PrivateStorage $storage,
		private Settings $settings
	) {
	}

	/**
	 * PDF bytes, generated and stored on first call.
	 *
	 * @param int $request_id Request ID.
	 * @throws RuntimeException When the request is missing or rendering fails.
	 * @return string
	 */
	public function ensure( int $request_id ): string {
		$request = $this->requests->find( $request_id );
		if ( null === $request ) {
			throw new RuntimeException( esc_html( 'Request not found.' ) );
		}

		// After a GDPR erasure the statement text is gone; a new PDF would carry
		// a hash that does not match its own text.
		if ( RequestRepository::is_anonymized( $request ) ) {
			throw new RuntimeException( esc_html( __( 'The personal data of this request was erased; the confirmation is not available any more.', 'primeplaced-withdrawal-button' ) ) );
		}

		$existing = $this->storage->read( (string) $request['pdf_path'] );
		if ( null !== $existing ) {
			return $existing;
		}

		$bytes = $this->render( $request );
		$this->requests->update( $request_id, array( 'pdf_path' => $this->storage->put( $bytes, 'pdf' ) ) );

		return $bytes;
	}

	/**
	 * Temporary copy for an email attachment, removed at the end of the request.
	 *
	 * @param int $request_id Request ID.
	 * @throws RuntimeException When the PDF cannot be produced.
	 * @return string Absolute path.
	 */
	public function attachment( int $request_id ): string {
		$request = $this->requests->find( $request_id );
		$dir     = trailingslashit( get_temp_dir() ) . 'ppwb-' . wp_generate_password( 12, false );
		wp_mkdir_p( $dir );
		$path = $dir . '/' . self::file_name( (string) ( $request['number'] ?? $request_id ) );

		file_put_contents( $path, $this->ensure( $request_id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporary attachment.
		register_shutdown_function(
			static function () use ( $path, $dir ): void {
				wp_delete_file( $path );
				@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best-effort cleanup of our temp directory.
			}
		);

		return $path;
	}

	/**
	 * Download file name, e.g. potwierdzenie-ZW-2026-000001.pdf.
	 *
	 * @param string $number Request number.
	 * @return string
	 */
	public static function file_name( string $number ): string {
		/* translators: %s: request number. Used as the PDF file name. */
		return sanitize_file_name( sprintf( __( 'confirmation-%s.pdf', 'primeplaced-withdrawal-button' ), $number ) );
	}

	/**
	 * "What next" lines, the same as on the success screen.
	 *
	 * @param array<string, string|int|null> $request Request row.
	 * @return list<string>
	 */
	private function next_steps( array $request ): array {
		$due     = \PrimePlaced\WithdrawalButton\Integrations\Dates::date_gmt( (string) $request['refund_due_gmt'] );
		$address = $this->settings->return_address_text();

		if ( in_array( 'before_shipment', explode( ',', (string) $request['flags'] ), true ) ) {
			return array(
				/* translators: %s: refund due date. */
				sprintf( __( 'We have not shipped the order yet. We will cancel it and refund the payment by %s.', 'primeplaced-withdrawal-button' ), $due ),
				/* translators: %s: return address. */
				sprintf( __( 'If the parcel has already left, refuse to accept it or send it back to: %s.', 'primeplaced-withdrawal-button' ), $address ),
			);
		}

		return array(
			/* translators: %s: return address. */
			sprintf( __( 'Send the products back within 14 days to: %s.', 'primeplaced-withdrawal-button' ), $address ),
			/* translators: %s: request number. */
			sprintf( __( 'Write the request number %s on the parcel.', 'primeplaced-withdrawal-button' ), (string) $request['number'] ),
			/* translators: %s: refund due date. */
			sprintf( __( 'We will refund the payment by %s. We may withhold the refund until we receive the products or proof that they were sent.', 'primeplaced-withdrawal-button' ), $due ),
		);
	}

	/**
	 * Shop logo from the WooCommerce email settings as a data URI (Dompdf loads
	 * no remote files), or '' when there is none or it is not a local upload.
	 *
	 * @return string
	 */
	private static function logo_data_uri(): string {
		$url  = (string) get_option( 'woocommerce_email_header_image', '' );
		$id   = '' === $url ? 0 : attachment_url_to_postid( $url );
		$file = $id > 0 ? get_attached_file( $id ) : false;
		if ( false === $file || ! is_readable( $file ) || filesize( $file ) > 512 * KB_IN_BYTES ) {
			return '';
		}

		$type = wp_check_filetype( $file )['type'];
		if ( ! in_array( $type, array( 'image/png', 'image/jpeg', 'image/gif' ), true ) ) {
			return '';
		}

		return 'data:' . $type . ';base64,' . base64_encode( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Local upload embedded in the PDF.
	}

	/**
	 * Renders the PDF bytes.
	 *
	 * @param array<string, string|int|null> $request Request row.
	 * @return string
	 */
	private function render( array $request ): string {
		$order     = wc_get_order( (int) $request['order_id'] );
		$created   = $order instanceof WC_Order ? $order->get_date_created() : null;
		$submitted = (int) strtotime( $request['submitted_at_gmt'] . ' UTC' );

		ob_start();
		Templates::render(
			'pdf/confirmation',
			array(
				'request'      => $request,
				'items'        => $this->requests->items( (int) $request['id'] ),
				'shop'         => $this->settings->shop_details(),
				'order_number' => $order instanceof WC_Order ? (string) $order->get_order_number() : (string) $request['order_id'],
				'order_date'   => null === $created ? '' : \PrimePlaced\WithdrawalButton\Integrations\Dates::date( $created ),
				'submitted'    => \PrimePlaced\WithdrawalButton\Integrations\Dates::datetime( $submitted ),
				'generated'    => \PrimePlaced\WithdrawalButton\Integrations\Dates::datetime( time() ),
				'logo'         => self::logo_data_uri(),
				'next_steps'   => $this->next_steps( $request ),
			)
		);
		$html = (string) ob_get_clean();

		$options = new Options();
		$options->setIsRemoteEnabled( false );
		$options->setDefaultFont( 'DejaVu Sans' );
		$options->setTempDir( get_temp_dir() );
		$options->setFontCache( get_temp_dir() );
		$options->setChroot( PPWB_PLUGIN_ROOT );

		$dompdf = new Dompdf( $options );
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->setPaper( 'A4' );
		$dompdf->render();

		return (string) $dompdf->output();
	}
}
