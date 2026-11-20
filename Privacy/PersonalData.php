<?php
/**
 * GDPR tools: personal data export, erasure and privacy policy text.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Privacy;

use PrimePlaced\WithdrawalButton\Backend\StatusView;
use PrimePlaced\WithdrawalButton\Data\EventLog;
use PrimePlaced\WithdrawalButton\Data\RequestRepository;
use PrimePlaced\WithdrawalButton\Domain\Status;
use PrimePlaced\WithdrawalButton\Engine\Module;
use PrimePlaced\WithdrawalButton\Integrations\Crypto;
use PrimePlaced\WithdrawalButton\Integrations\Dates;
use PrimePlaced\WithdrawalButton\Pdf\PrivateStorage;

/**
 * Class PersonalData
 *
 * Erasure anonymizes a request instead of deleting it: the number, dates,
 * statuses and the statement hash stay as proof, the history records the
 * erasure.
 */
final class PersonalData implements Module {

	private const PER_PAGE = 50;

	/**
	 * Creates the module.
	 *
	 * @param RequestRepository $requests Request storage.
	 * @param EventLog          $events   Request history.
	 * @param PrivateStorage    $storage  Private files.
	 * @param Crypto            $crypto   Bank account decryption for the masked export.
	 */
	public function __construct(
		private RequestRepository $requests,
		private EventLog $events,
		private PrivateStorage $storage,
		private Crypto $crypto
	) {
	}

	/**
	 * Adds the WordPress privacy hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array<string, array<string, mixed>> $exporters Exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public function exporters( array $exporters ): array {
		$exporters['primeplaced-withdrawal-button'] = array(
			'exporter_friendly_name' => __( 'Withdrawal requests', 'primeplaced-withdrawal-button' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array<string, array<string, mixed>> $erasers Erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public function erasers( array $erasers ): array {
		$erasers['primeplaced-withdrawal-button'] = array(
			'eraser_friendly_name' => __( 'Withdrawal requests', 'primeplaced-withdrawal-button' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Requests of a person: by email and by the WordPress account with that email.
	 *
	 * @param string $email Email address.
	 * @return list<array<string, string|int|null>>
	 */
	private function requests_of( string $email ): array {
		$user = get_user_by( 'email', $email );

		return $this->requests->for_customer( $email, $user instanceof \WP_User ? $user->ID : 0 );
	}

	/**
	 * Exports the person's requests, 50 per page.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{data: list<array{group_id: string, group_label: string, item_id: string, data: list<array{name: string, value: string}>}>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$all   = $this->requests_of( $email );
		$slice = array_slice( $all, ( max( 1, $page ) - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$data  = array();

		foreach ( $slice as $request ) {
			$items   = array_map( static fn ( array $item ): string => $item['name'] . ' x ' . $item['qty'], $this->requests->items( (int) $request['id'] ) );
			$order   = wc_get_order( (int) $request['order_id'] );
			$status  = Status::tryFrom( (string) $request['status'] );
			$account = '' === (string) $request['bank_account'] ? '' : Crypto::mask( $this->crypto->decrypt( (string) $request['bank_account'] ) );
			$notes   = array();
			$proofs  = 0;
			$emails  = array();
			foreach ( $this->events->for_request( (int) $request['id'] ) as $event ) {
				if ( 'note' === $event['type'] && ! empty( $event['payload']['to_customer'] ) ) {
					$notes[] = (string) ( $event['payload']['text'] ?? '' );
				}
				if ( 'shipping_added' === $event['type'] && ! empty( $event['payload']['file'] ) ) {
					++$proofs;
				}
				if ( 'contact_email_changed' === $event['type'] && ! empty( $event['payload']['order_email'] ) ) {
					$emails[] = (string) $event['payload']['order_email'];
				}
			}

			$fields = array(
				__( 'Request number', 'primeplaced-withdrawal-button' ) => (string) $request['number'],
				__( 'Order', 'primeplaced-withdrawal-button' ) => $order instanceof \WC_Order ? '#' . $order->get_order_number() : (string) $request['order_id'],
				__( 'Status', 'primeplaced-withdrawal-button' ) => null === $status ? (string) $request['status'] : StatusView::label( $status ),
				__( 'Full name', 'primeplaced-withdrawal-button' ) => (string) $request['full_name'],
				__( 'Email address', 'primeplaced-withdrawal-button' ) => (string) $request['email'],
				__( 'Submitted', 'primeplaced-withdrawal-button' ) => Dates::datetime_gmt( (string) $request['submitted_at_gmt'] ),
				__( 'Products', 'primeplaced-withdrawal-button' ) => implode( ', ', $items ),
				__( 'Reason for return', 'primeplaced-withdrawal-button' ) => (string) $request['reason_text'],
				__( 'Statement text', 'primeplaced-withdrawal-button' ) => (string) $request['statement_text'],
				__( 'Tracking number', 'primeplaced-withdrawal-button' ) => trim( $request['tracking_carrier'] . ' ' . $request['tracking_number'] ),
				// Only the last digits: the export is an HTML file the person downloads.
				__( 'Bank account for the refund', 'primeplaced-withdrawal-button' ) => $account,
				__( 'Messages from the shop', 'primeplaced-withdrawal-button' ) => implode( "\n", $notes ),
				__( 'Email address of the order', 'primeplaced-withdrawal-button' ) => implode( ', ', array_unique( $emails ) ),
				/* translators: %d: number of files. */
				__( 'Proof of shipment', 'primeplaced-withdrawal-button' ) => 0 === $proofs ? '' : sprintf( _n( '%d file stored with the request; ask the shop for a copy.', '%d files stored with the request; ask the shop for a copy.', $proofs, 'primeplaced-withdrawal-button' ), $proofs ),
				__( 'IP address', 'primeplaced-withdrawal-button' ) => '' === (string) $request['ip_hash'] ? '' : __( 'Stored only as a salted hash, which cannot be turned back into the address.', 'primeplaced-withdrawal-button' ),
				__( 'Language', 'primeplaced-withdrawal-button' ) => (string) $request['locale'],
			);

			$rows = array();
			foreach ( $fields as $name => $value ) {
				if ( '' !== $value ) {
					$rows[] = array(
						'name'  => (string) $name,
						'value' => $value,
					);
				}
			}

			$data[] = array(
				'group_id'    => 'ppwb-requests',
				'group_label' => __( 'Withdrawal requests (PrimePlaced - Withdrawal Button)', 'primeplaced-withdrawal-button' ),
				'item_id'     => 'ppwb-request-' . (int) $request['id'],
				'data'        => $rows,
			);
		}

		return array(
			'data' => $data,
			'done' => count( $all ) <= max( 1, $page ) * self::PER_PAGE,
		);
	}

	/**
	 * Anonymizes the person's closed requests. Numbers, dates, amounts,
	 * statuses and the statement hash stay as proof (accounting duties).
	 * Requests still being handled keep their data until they are closed.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (unused, one pass).
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		unset( $page );
		$removed  = false;
		$retained = false;
		$messages = array();

		foreach ( $this->requests_of( $email ) as $request ) {
			$status = Status::tryFrom( (string) $request['status'] );
			if ( null !== $status && ! in_array( $status, array( Status::Completed, Status::Refunded, Status::Rejected, Status::Cancelled ), true ) ) {
				$retained   = true;
				$messages[] = sprintf(
					/* translators: %s: request number. */
					__( 'Request %s is still being handled; its data is kept until it is closed.', 'primeplaced-withdrawal-button' ),
					(string) $request['number']
				);
				continue;
			}

			$this->storage->delete( (string) $request['pdf_path'] );
			foreach ( $this->events->for_request( (int) $request['id'] ) as $event ) {
				if ( 'shipping_added' === $event['type'] && ! empty( $event['payload']['file'] ) ) {
					$this->storage->delete( (string) $event['payload']['file'] );
				}
			}
			$this->requests->anonymize( (int) $request['id'] );
			$this->events->redact( (int) $request['id'] );
			$this->events->add( (int) $request['id'], 'personal_data_erased' );
			$removed = true;
		}

		if ( $removed ) {
			$messages[] = __( 'Withdrawal requests were anonymized; numbers, dates, amounts and statement hashes were kept as proof.', 'primeplaced-withdrawal-button' );
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Suggested privacy policy text.
	 *
	 * @return void
	 */
	public function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			'PrimePlaced - Withdrawal Button',
			wp_kses_post(
				wpautop(
					__( 'When you withdraw from a contract, we store your name, email address, order number, the products you return, the optional reason, the text of your statement with the date and time it was made, a hash of your IP address, the tracking number you add and, when the payment method cannot refund automatically (cash on delivery, bank transfer), the bank account number for the refund (encrypted). We use this data to handle the withdrawal and the refund, and keep it as long as the related order. On an erasure request we anonymize closed requests and keep only the request number, dates, amounts and a hash of the statement as proof; requests still being handled are anonymized after they are closed. The data stays on our server and is not sent to third parties.', 'primeplaced-withdrawal-button' )
				)
			)
		);
	}
}
