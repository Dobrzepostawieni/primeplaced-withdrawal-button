<?php
/**
 * Withdrawal use cases on top of WooCommerce orders.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Integrations;

use DateTimeImmutable;
use DateTimeZone;
use PrimePlaced\WithdrawalButton\Data\Counter;
use PrimePlaced\WithdrawalButton\Data\EventLog;
use PrimePlaced\WithdrawalButton\Data\RequestRepository;
use PrimePlaced\WithdrawalButton\Data\Settings;
use PrimePlaced\WithdrawalButton\Domain\Eligibility;
use PrimePlaced\WithdrawalButton\Domain\Money;
use PrimePlaced\WithdrawalButton\Domain\OrderDates;
use PrimePlaced\WithdrawalButton\Domain\OrderStage;
use PrimePlaced\WithdrawalButton\Domain\RequestNumber;
use PrimePlaced\WithdrawalButton\Domain\ReturnReasons;
use PrimePlaced\WithdrawalButton\Domain\Statement;
use PrimePlaced\WithdrawalButton\Domain\Status;
use RuntimeException;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Class WithdrawalService
 *
 * Orders are read only through wc_get_order(), so HPOS and legacy storage
 * behave the same.
 *
 * @phpstan-type OrderLine array{id: int, name: string, ordered: int, available: int, product_id: int, variation_id: int, net_minor: int, tax_minor: int, excluded: bool, exclusion_reason: string, note: string}
 */
final class WithdrawalService {

	/**
	 * Order meta with the delivery date (Unix timestamp) entered by the shop or a carrier integration.
	 */
	public const DELIVERED_META = '_ppwb_delivered_at';

	/**
	 * Request flag of a cancellation before shipment.
	 */
	public const BEFORE_SHIPMENT = 'before_shipment';

	/**
	 * Creates the service.
	 *
	 * @param Settings          $settings   Plugin settings.
	 * @param RequestRepository $requests   Request storage.
	 * @param Counter           $counter    Atomic number counter.
	 * @param Exclusions        $exclusions Product exclusions.
	 * @param Crypto            $crypto     Bank account encryption.
	 * @param RefundService     $refunds    Refund capabilities of the gateway.
	 * @param EventLog          $events     Request history.
	 */
	public function __construct(
		private Settings $settings,
		private RequestRepository $requests,
		private Counter $counter,
		private Exclusions $exclusions,
		private Crypto $crypto,
		private RefundService $refunds,
		private EventLog $events
	) {
	}

	/**
	 * Finds the order for a guest. The email is compared case-insensitively in
	 * constant time; a wrong number and a wrong email look the same to the caller.
	 *
	 * @param string $order_number Order number typed by the customer.
	 * @param string $email        Email typed by the customer.
	 * @return WC_Order|null
	 */
	public function find_order( string $order_number, string $email ): ?WC_Order {
		$order_number = trim( ltrim( trim( $order_number ), '#' ) );
		if ( '' === $order_number ) {
			return null;
		}

		// Same filter as WooCommerce order tracking, so sequential-number plugins map numbers to IDs.
		$order_id = absint( apply_filters( 'woocommerce_shortcode_order_tracking_order_id', $order_number ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter.
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof WC_Order || (string) $order->get_order_number() !== $order_number ) {
			return null;
		}

		$expected = strtolower( trim( $order->get_billing_email() ) );
		$given    = strtolower( trim( $email ) );

		return '' !== $expected && hash_equals( $expected, $given ) ? $order : null;
	}

	/**
	 * Why the order cannot be withdrawn now, or null when it can.
	 *
	 * @param WC_Order $order Order.
	 * @return array{code: string, deadline?: DateTimeImmutable, status?: string}|null
	 */
	public function blocking_reason( WC_Order $order ): ?array {
		$stage = $this->stage( $order );
		if ( OrderStage::Closed === $stage ) {
			return array(
				'code'   => 'status',
				'status' => wc_get_order_status_name( $order->get_status() ),
			);
		}

		if ( 'block' === $this->settings->get( 'business_orders' ) && $this->is_business_order( $order ) ) {
			return array( 'code' => 'business' );
		}

		if ( OrderStage::BeforeShipment === $stage && 'message' === $this->settings->get( 'before_shipment' ) ) {
			return array(
				'code'   => 'before_shipment',
				'status' => wc_get_order_status_name( $order->get_status() ),
			);
		}

		if ( $this->is_late( $order ) && 'allow_flagged' !== $this->settings->get( 'late_requests' ) ) {
			return array(
				'code'     => 'expired',
				'deadline' => $this->deadline( $order ),
			);
		}

		$lines     = $this->lines( $order );
		$available = array_sum( array_column( $lines, 'available' ) );
		if ( 0 === $available ) {
			$only_excluded = array() !== $lines && count( array_filter( $lines, static fn ( array $line ): bool => $line['excluded'] ) ) === count( $lines );
			return array( 'code' => $only_excluded ? 'only_excluded' : 'nothing_left' );
		}

		return null;
	}

	/**
	 * Stage of the order: shipped, before shipment or closed.
	 *
	 * @param WC_Order $order Order.
	 * @return OrderStage
	 */
	public function stage( WC_Order $order ): OrderStage {
		$status = $order->get_status();
		$paid   = in_array( $status, wc_get_is_paid_statuses(), true ) || null !== $order->get_date_paid();
		$stage  = OrderStage::of( $status, $paid, null !== $order->get_date_completed() );

		/**
		 * Filters the stage of an order, e.g. to map a custom "awaiting shipment" status.
		 *
		 * @param string   $stage "shipped", "before_shipment" or "closed".
		 * @param WC_Order $order Order.
		 */
		$filtered = apply_filters( 'ppwb_order_stage', $stage->value, $order );

		return is_string( $filtered ) ? ( OrderStage::tryFrom( $filtered ) ?? $stage ) : $stage;
	}

	/**
	 * Tax ID (NIP) of a business order, '' for a consumer order. Reads the
	 * meta keys used by common Polish invoice and checkout plugins; the filter
	 * ppwb_order_tax_id handles others.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public function tax_id( WC_Order $order ): string {
		$tax_id = '';
		foreach ( array( '_billing_nip', 'billing_nip', '_billing_vat', '_billing_vat_number', 'vat_number', '_vat_number', '_billing_tax_id', '_billing_eu_vat_number' ) as $key ) {
			$value = trim( (string) $order->get_meta( $key ) );
			if ( '' !== $value ) {
				$tax_id = $value;
				break;
			}
		}

		/**
		 * Filters the tax ID of an order; '' means a consumer order.
		 *
		 * @param string   $tax_id Tax ID found in the order meta.
		 * @param WC_Order $order  Order.
		 */
		$filtered = apply_filters( 'ppwb_order_tax_id', $tax_id, $order );

		return is_string( $filtered ) ? trim( $filtered ) : $tax_id;
	}

	/**
	 * Whether the order carries a tax ID.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public function is_business_order( WC_Order $order ): bool {
		return '' !== $this->tax_id( $order );
	}

	/**
	 * Whether the form asks the customer to declare consumer status (the "ask" setting).
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public function asks_consumer_status( WC_Order $order ): bool {
		return 'ask' === $this->settings->get( 'business_orders' ) && $this->is_business_order( $order );
	}

	/**
	 * Whether a request for this order is a cancellation before shipment.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public function is_before_shipment( WC_Order $order ): bool {
		return OrderStage::BeforeShipment === $this->stage( $order );
	}

	/**
	 * Whether the withdrawal period has ended (Art. 29: the shop may still accept, flagged "late").
	 *
	 * @param WC_Order               $order Order.
	 * @param DateTimeImmutable|null $at    Moment of the statement; null for now.
	 * @return bool
	 */
	public function is_late( WC_Order $order, ?DateTimeImmutable $at = null ): bool {
		return ( $at ?? new DateTimeImmutable( 'now', wp_timezone() ) ) > $this->deadline( $order );
	}

	/**
	 * Whether the customer must give a bank account: cash on delivery or a
	 * gateway without refunds (Art. 32 ust. 2).
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public function needs_bank_account( WC_Order $order ): bool {
		return ! $this->refunds->gateway_refunds( $order );
	}

	/**
	 * End of the withdrawal period. Every check (button, form steps, final
	 * save) goes through this one method, and an order always has a deadline.
	 *
	 * @param WC_Order $order Order.
	 * @return DateTimeImmutable
	 */
	public function deadline( WC_Order $order ): DateTimeImmutable {
		$deadline = $this->settings->withdrawal_deadline()->deadline( self::order_dates( $order ), wp_timezone() );

		/**
		 * Filters the end of the withdrawal period for an order.
		 *
		 * @param DateTimeImmutable $deadline Last moment a statement is on time.
		 * @param WC_Order          $order    Order.
		 */
		$filtered = apply_filters( 'ppwb_withdrawal_deadline', $deadline, $order );

		return $filtered instanceof DateTimeImmutable ? $filtered : $deadline;
	}

	/**
	 * Dates of the order that may start the withdrawal period.
	 *
	 * @param WC_Order $order Order.
	 * @return OrderDates
	 */
	public static function order_dates( WC_Order $order ): OrderDates {
		$moment = static fn ( ?\WC_DateTime $date ): ?DateTimeImmutable => null === $date ? null : new DateTimeImmutable( '@' . $date->getTimestamp() );

		$delivered = absint( $order->get_meta( self::DELIVERED_META ) );
		$completed = $moment( $order->get_date_completed() );

		return new OrderDates(
			$moment( $order->get_date_created() ) ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ),
			$moment( $order->get_date_paid() ),
			$completed,
			$delivered > 0 ? new DateTimeImmutable( '@' . $delivered ) : null,
			null !== $completed || 'completed' === $order->get_status()
		);
	}

	/**
	 * Order lines with the quantity still available for withdrawal.
	 *
	 * @param WC_Order $order Order.
	 * @return list<OrderLine>
	 */
	public function lines( WC_Order $order ): array {
		$requested = $this->requests->requested_quantities( $order->get_id() );
		$lines     = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$ordered     = (int) $item->get_quantity();
			$refunded    = absint( $order->get_qty_refunded_for_item( $item_id ) );
			$eligibility = $this->exclusions->for_item( $item );
			$excluded    = Exclusions::EXCLUDED === $eligibility['state'];

			$lines[] = array(
				'id'               => (int) $item_id,
				'name'             => $item->get_name(),
				'ordered'          => $ordered,
				'available'        => $excluded ? 0 : Eligibility::available_quantity( $ordered, $refunded, $requested[ (int) $item_id ] ?? 0 ),
				'excluded'         => $excluded,
				'exclusion_reason' => $excluded ? $eligibility['reason'] : '',
				'note'             => Exclusions::WITH_NOTE === $eligibility['state'] ? $eligibility['note'] : '',
				'net_minor'        => Money::to_minor( (string) $item->get_total(), wc_get_price_decimals() ),
				'tax_minor'        => Money::to_minor( (string) $item->get_total_tax(), wc_get_price_decimals() ),
				'product_id'       => $item->get_product_id(),
				'variation_id'     => $item->get_variation_id(),
			);
		}

		return $lines;
	}

	/**
	 * Keeps only valid selections: known lines, 1..available.
	 *
	 * @param WC_Order        $order      Order.
	 * @param array<int, int> $quantities Order item ID => quantity chosen by the customer.
	 * @return array<int, int>
	 */
	public function valid_selection( WC_Order $order, array $quantities ): array {
		$valid = array();
		foreach ( $this->lines( $order ) as $line ) {
			$qty = (int) ( $quantities[ $line['id'] ] ?? 0 );
			if ( $qty > 0 && $qty <= $line['available'] ) {
				$valid[ $line['id'] ] = $qty;
			}
		}

		return $valid;
	}

	/**
	 * Estimated refund for a selection, gross, in minor units.
	 *
	 * @param WC_Order        $order     Order.
	 * @param array<int, int> $selection Valid selection.
	 * @return int
	 */
	public function estimated_refund_minor( WC_Order $order, array $selection ): int {
		return $this->refunds->plan_for( $order, $selection )['amount_minor'];
	}

	/**
	 * Builds the statement text for a selection.
	 *
	 * @param WC_Order               $order        Order.
	 * @param array<int, int>        $selection    Valid selection.
	 * @param string                 $full_name    Customer name.
	 * @param DateTimeImmutable|null $submitted_at Submission moment; null for the preview before "Confirm".
	 * @param string                 $email        Contact email; '' for the order email.
	 * @return string
	 */
	public function statement_text( WC_Order $order, array $selection, string $full_name, ?DateTimeImmutable $submitted_at, string $email = '' ): string {
		$names = array();
		foreach ( $this->lines( $order ) as $line ) {
			if ( isset( $selection[ $line['id'] ] ) ) {
				$names[] = sprintf( '%s x %d', $line['name'], $selection[ $line['id'] ] );
			}
		}

		$created = $order->get_date_created();

		return Statement::render(
			/* translators: Statutory withdrawal statement. Keep every {placeholder} unchanged. */
			__( 'I, {full_name}, hereby give notice that I withdraw from the contract of sale of the following goods: {items}, order no. {order_number} of {order_date}. Contact email: {email}. Date and time of the statement: {submitted_at}.', 'primeplaced-withdrawal-button' ),
			array(
				'full_name'    => $full_name,
				'items'        => implode( ', ', $names ),
				'order_number' => (string) $order->get_order_number(),
				'order_date'   => null === $created ? '' : Dates::date( $created ),
				'email'        => '' !== $email ? $email : $order->get_billing_email(),
				'submitted_at' => null === $submitted_at
					? __( '[filled in when you confirm]', 'primeplaced-withdrawal-button' )
					: Dates::datetime( $submitted_at ),
			)
		);
	}

	/**
	 * Stores the withdrawal statement and returns the new request ID.
	 *
	 * @param WC_Order               $order         Order.
	 * @param array<int, int>        $selection     Valid selection, at least one line.
	 * @param string                 $full_name     Customer name (editable in the form).
	 * @param string                 $reason        Optional reason.
	 * @param string                 $ip            Client IP, stored only as a salted hash.
	 * @param string                 $iban          Bank account for refunds, '' when not needed.
	 * @param string                 $via           Entry point: form, account, email_link, or manual (recorded by the shop).
	 * @param string                 $reason_code   Code from the shop's reason list or "other"; anything else is dropped.
	 * @param string                 $contact_email Email for the confirmation when the customer changed it in step 3, '' for the order email.
	 * @param DateTimeImmutable|null $stated_at     Only for "manual": when the customer made the statement by email, phone or letter; null or a future moment means now.
	 * @throws WithdrawalBlocked|RuntimeException When the order cannot be withdrawn now (deadline, status, nothing left), the selection is empty or storage fails.
	 * @return int
	 */
	public function submit( WC_Order $order, array $selection, string $full_name, string $reason, string $ip, string $iban = '', string $via = 'form', string $reason_code = '', string $contact_email = '', ?DateTimeImmutable $stated_at = null ): int {
		// One request per order at a time: two forms sent at the same moment must
		// not both pass the quantity check before either is stored.
		if ( ! $this->requests->lock_order( $order->get_id() ) ) {
			throw new RuntimeException( esc_html( 'Another request for this order is being stored.' ) );
		}

		try {
			return $this->store( $order, $selection, $full_name, $reason, $ip, $iban, $via, $reason_code, $contact_email, $stated_at );
		} finally {
			$this->requests->unlock_order( $order->get_id() );
		}
	}

	/**
	 * Checks and stores the statement; submit() holds the order lock.
	 *
	 * @param WC_Order               $order         Order.
	 * @param array<int, int>        $selection     Selection.
	 * @param string                 $full_name     Customer name.
	 * @param string                 $reason        Optional reason.
	 * @param string                 $ip            Client IP.
	 * @param string                 $iban          Bank account or ''.
	 * @param string                 $via           Entry point.
	 * @param string                 $reason_code   Reason code.
	 * @param string                 $contact_email Contact email or ''.
	 * @param DateTimeImmutable|null $stated_at     Statement moment of a manual request.
	 * @throws WithdrawalBlocked When the order cannot be withdrawn now.
	 * @throws RuntimeException When the selection is empty or storage fails.
	 * @return int
	 */
	private function store( WC_Order $order, array $selection, string $full_name, string $reason, string $ip, string $iban, string $via, string $reason_code, string $contact_email, ?DateTimeImmutable $stated_at ): int {
		// The final, mandatory check: the form steps only mirror it for convenience.
		// A request recorded by the shop may pass the deadline and the
		// order status; it is flagged instead. Nothing left stays a hard stop.
		$blocking = $this->blocking_reason( $order );
		if ( null !== $blocking && ( 'manual' !== $via || in_array( $blocking['code'], array( 'nothing_left', 'only_excluded' ), true ) ) ) {
			throw new WithdrawalBlocked( $blocking ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exception escapes its own message.
		}

		$selection = $this->valid_selection( $order, $selection );
		if ( array() === $selection ) {
			throw new RuntimeException( esc_html( 'Nothing selected.' ) );
		}

		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		// A statement the shop records later counts from the day the customer
		// made it: the "late" flag, the statement date and the refund deadline.
		$stated        = 'manual' === $via && null !== $stated_at && $stated_at < $now ? $stated_at->setTimezone( new DateTimeZone( 'UTC' ) ) : $now;
		$shop_now      = $now->setTimezone( wp_timezone() );
		$scope         = 'yearly' === $this->settings->get( 'counter_scope' ) ? $shop_now->format( 'Y' ) : 'global';
		$counter       = $this->counter->next( $scope, (int) $this->settings->get( 'counter_start' ) );
		$number        = RequestNumber::format( (string) $this->settings->get( 'number_format' ), $counter, $shop_now, (string) $order->get_order_number() );
		$contact_email = is_email( $contact_email ) ? $contact_email : $order->get_billing_email();
		$statement     = $this->statement_text( $order, $selection, $full_name, $stated, $contact_email );

		$before_shipment = $this->is_before_shipment( $order );
		$flags           = array_keys(
			array_filter(
				array(
					'late'                => $this->is_late( $order, $stated ),
					self::BEFORE_SHIPMENT => $before_shipment,
					// The customer got no return address, the shop must send it.
					'action_required'     => ! $before_shipment && ! $this->settings->has_return_address(),
				)
			)
		);

		$decimals = wc_get_price_decimals();
		$items    = array();
		foreach ( $this->lines( $order ) as $line ) {
			if ( ! isset( $selection[ $line['id'] ] ) ) {
				continue;
			}
			$items[] = array(
				'order_item_id' => $line['id'],
				'product_id'    => $line['product_id'],
				'variation_id'  => $line['variation_id'],
				'name'          => $line['name'],
				'qty'           => $selection[ $line['id'] ],
				'unit_total'    => Money::to_decimal( Money::share( $line['net_minor'], 1, $line['ordered'] ), $decimals ),
				'tax'           => Money::to_decimal( Money::share( $line['tax_minor'], 1, $line['ordered'] ), $decimals ),
			);
		}

		$request_id = $this->requests->create(
			array(
				'number'           => $number,
				'order_id'         => $order->get_id(),
				'customer_id'      => $order->get_customer_id() > 0 ? $order->get_customer_id() : null,
				'email'            => $contact_email,
				'full_name'        => $full_name,
				'status'           => Status::New->value,
				'reason_code'      => ReturnReasons::is_valid( $reason_code, $this->settings->return_reasons() ) ? $reason_code : null,
				'reason_text'      => '' === $reason ? null : $reason,
				'statement_text'   => $statement,
				'statement_hash'   => Statement::hash( $statement ),
				'submitted_at_gmt' => $stated->format( 'Y-m-d H:i:s' ),
				'refund_due_gmt'   => $stated->modify( '+14 days' )->format( 'Y-m-d H:i:s' ),
				'currency'         => $order->get_currency(),
				'estimated_total'  => Money::to_decimal( $this->estimated_refund_minor( $order, $selection ), $decimals ),
				'ip_hash'          => '' === $ip ? null : hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ),
				'bank_account'     => '' === $iban ? null : $this->crypto->encrypt( $iban ),
				'flags'            => array() === $flags ? null : implode( ',', $flags ),
				'locale'           => determine_locale(),
				'created_via'      => $via,
				'created_at_gmt'   => $now->format( 'Y-m-d H:i:s' ),
				'updated_at_gmt'   => $now->format( 'Y-m-d H:i:s' ),
			),
			$items
		);

		$order->add_order_note(
			$before_shipment
				? sprintf(
					/* translators: %s: request number. */
					__( 'The customer cancelled the order before shipment: %s. Do not ship the order; cancel it and refund the payment.', 'primeplaced-withdrawal-button' ),
					RequestWorkflow::number_link( $request_id, $number )
				)
				: sprintf(
					/* translators: %s: request number. */
					__( 'Withdrawal statement received: %s', 'primeplaced-withdrawal-button' ),
					RequestWorkflow::number_link( $request_id, $number )
				)
		);

		/**
		 * Fires after a withdrawal request is stored.
		 *
		 * @param int      $request_id Request ID.
		 * @param WC_Order $order      Order.
		 */
		if ( 0 !== strcasecmp( $contact_email, $order->get_billing_email() ) ) {
			$this->events->add( $request_id, 'contact_email_changed', array( 'order_email' => $order->get_billing_email() ), EventLog::ACTOR_CUSTOMER );
		}

		if ( 'manual' === $via ) {
			$this->events->add(
				$request_id,
				'manual_created',
				array(
					'late'            => in_array( 'late', $flags, true ),
					'stated_at_gmt'   => $stated->format( 'Y-m-d H:i:s' ),
					'recorded_at_gmt' => $now->format( 'Y-m-d H:i:s' ),
				),
				EventLog::ACTOR_USER
			);
		}

		do_action( 'ppwb_request_created', $request_id, $order );

		return $request_id;
	}
}
