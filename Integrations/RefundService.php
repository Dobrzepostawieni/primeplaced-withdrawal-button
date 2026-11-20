<?php
/**
 * One-click refund through WooCommerce.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Integrations;

use PrimePlaced\WithdrawalButton\Data\EventLog;
use PrimePlaced\WithdrawalButton\Data\RequestRepository;
use PrimePlaced\WithdrawalButton\Data\Settings;
use PrimePlaced\WithdrawalButton\Domain\Money;
use PrimePlaced\WithdrawalButton\Domain\Status;
use RuntimeException;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;

/**
 * Class RefundService
 *
 * @phpstan-type RefundPlan array{amount_minor: int, line_items: array<int, array{qty: int, refund_total: string, refund_tax: array<int|string, string>}>, includes_shipping: bool, shipping_capped: bool, automatic: bool}
 */
final class RefundService {

	/**
	 * Refund meta with the request ID.
	 */
	public const REQUEST_META = '_ppwb_request_id';

	/**
	 * Creates the service.
	 *
	 * @param RequestRepository $requests Request storage.
	 * @param RequestWorkflow   $workflow Status changes.
	 * @param EventLog          $events   Request history.
	 */
	public function __construct(
		private RequestRepository $requests,
		private RequestWorkflow $workflow,
		private EventLog $events
	) {
	}

	/**
	 * Refund lines and amount for a stored request.
	 *
	 * @param int $request_id Request ID.
	 * @throws RuntimeException When the request or order is missing.
	 * @return RefundPlan
	 */
	public function plan( int $request_id ): array {
		[ , $order ] = $this->load( $request_id );

		$selection = array();
		foreach ( $this->requests->items( $request_id ) as $item ) {
			$selection[ $item['order_item_id'] ] = ( $selection[ $item['order_item_id'] ] ?? 0 ) + $item['qty'];
		}

		return $this->plan_for( $order, $selection, $request_id );
	}

	/**
	 * The one place that computes a refund: form estimate, stored
	 * estimate, emails, PDF and the refund itself. Products are refunded at the
	 * price paid. The delivery to the customer is added only when, with this
	 * request, every product line of the order is returned, counting earlier
	 * requests and WooCommerce refunds (Art. 32 ust. 1), and at most the cheapest
	 * standard delivery set by the shop (Art. 33).
	 *
	 * @param WC_Order        $order      Order.
	 * @param array<int, int> $selection  Order item ID => quantity in this request.
	 * @param int             $request_id Stored request ID, 0 for a request not saved yet.
	 * @return RefundPlan
	 */
	public function plan_for( WC_Order $order, array $selection, int $request_id = 0 ): array {
		$decimals   = wc_get_price_decimals();
		$line_items = array();
		$total      = 0;

		/**
		 * Order items left out of the refund, e.g. items exchanged for another variant.
		 * An excluded item counts as kept by the customer, so the delivery cost is not refunded with it.
		 *
		 * @param list<int>       $excluded   Order item IDs to leave out.
		 * @param WC_Order        $order      Order.
		 * @param array<int, int> $selection  Order item ID => quantity in this request.
		 * @param int             $request_id Stored request ID, 0 for a request not saved yet.
		 */
		$excluded  = array_map( 'absint', (array) apply_filters( 'ppwb_refund_excluded_items', array(), $order, $selection, $request_id ) );
		$selection = array_diff_key( $selection, array_flip( $excluded ) );

		foreach ( $selection as $item_id => $qty ) {
			$order_item = $order->get_item( $item_id );
			if ( ! $order_item instanceof WC_Order_Item_Product || $qty <= 0 ) {
				continue;
			}

			$ordered = max( 1, (int) $order_item->get_quantity() );
			$net     = Money::share( Money::to_minor( (string) $order_item->get_total(), $decimals ), $qty, $ordered );
			$taxes   = array();
			foreach ( (array) ( $order_item->get_taxes()['total'] ?? array() ) as $rate_id => $tax ) {
				$share             = Money::share( Money::to_minor( (string) $tax, $decimals ), $qty, $ordered );
				$taxes[ $rate_id ] = Money::to_decimal( $share, $decimals );
				$total            += $share;
			}

			$total                       += $net;
			$line_items[ (int) $item_id ] = array(
				'qty'          => $qty,
				'refund_total' => Money::to_decimal( $net, $decimals ),
				'refund_tax'   => $taxes,
			);
		}

		$includes_shipping = array() !== $line_items && $this->returns_everything( $order, $selection, $this->requests->requested_quantities( $order->get_id(), $request_id ) );
		$shipping_capped   = false;
		$shipping_left     = false;
		$shipping_lines    = 0;
		if ( $includes_shipping ) {
			$cap = $this->cheapest_shipping_minor();
			foreach ( $order->get_items( 'shipping' ) as $shipping_id => $shipping ) {
				if ( ! $shipping instanceof WC_Order_Item_Shipping ) {
					continue;
				}
				++$shipping_lines;
				$net      = Money::to_minor( (string) $shipping->get_total(), $decimals );
				$tax_rows = array();
				foreach ( (array) ( $shipping->get_taxes()['total'] ?? array() ) as $rate_id => $tax ) {
					$tax_rows[ $rate_id ] = Money::to_minor( (string) $tax, $decimals );
				}
				$gross = $net + array_sum( $tax_rows );

				// Art. 33: a dearer delivery chosen by the customer is refunded up to the cheapest standard one.
				if ( null !== $cap && $gross > $cap ) {
					$shipping_capped = true;
					$net             = Money::share( $net, $cap, $gross );
					foreach ( $tax_rows as $rate_id => $tax ) {
						$tax_rows[ $rate_id ] = Money::share( $tax, $cap, $gross );
					}
					$cap = 0;
				} elseif ( null !== $cap ) {
					$cap -= $gross;
				}

				// Delivery already refunded (by a later request refunded first, or by
				// the shop in WooCommerce) is not refunded again.
				$net = max( 0, $net - Money::to_minor( wc_format_decimal( $order->get_total_refunded_for_item( (int) $shipping_id, 'shipping' ) ), $decimals ) );
				foreach ( $tax_rows as $rate_id => $tax ) {
					$tax_rows[ $rate_id ] = max( 0, $tax - Money::to_minor( wc_format_decimal( $order->get_tax_refunded_for_item( (int) $shipping_id, (int) $rate_id, 'shipping' ) ), $decimals ) );
				}
				if ( 0 === $net + array_sum( $tax_rows ) ) {
					continue;
				}
				$shipping_left = true;

				$taxes = array();
				foreach ( $tax_rows as $rate_id => $tax ) {
					$taxes[ $rate_id ] = Money::to_decimal( $tax, $decimals );
					$total            += $tax;
				}
				$total                           += $net;
				$line_items[ (int) $shipping_id ] = array(
					'qty'          => 0,
					'refund_total' => Money::to_decimal( $net, $decimals ),
					'refund_tax'   => $taxes,
				);
			}
		}

		// An order with paid delivery that is fully refunded already adds nothing.
		if ( $shipping_lines > 0 && ! $shipping_left ) {
			$includes_shipping = false;
			$shipping_capped   = false;
		}

		return array(
			'amount_minor'      => $total,
			'line_items'        => $line_items,
			'includes_shipping' => $includes_shipping,
			'shipping_capped'   => $shipping_capped,
			'automatic'         => $this->gateway_refunds( $order ),
		);
	}

	/**
	 * Cheapest standard delivery (gross, minor units) from the settings, or null for "refund what was paid".
	 *
	 * @return int|null
	 */
	private function cheapest_shipping_minor(): ?int {
		$value = trim( (string) ( new Settings() )->get( 'cheapest_shipping' ) );

		return '' === $value ? null : max( 0, Money::to_minor( wc_format_decimal( $value ), wc_get_price_decimals() ) );
	}

	/**
	 * Creates the WooCommerce refund and moves the request to "refunded".
	 *
	 * @param int    $request_id Request ID.
	 * @param string $amount     Amount to refund (decimal string); '' for the full planned amount.
	 * @param string $reason     Deduction reason, required when the amount is lower than planned.
	 * @param bool   $restock    Whether to return items to stock.
	 * @param string $actor_type EventLog actor type; automations pass "system".
	 * @param bool   $manual     Record the refund only, without the gateway (after a gateway error).
	 * @throws RuntimeException When validation or the refund fails.
	 * @return void
	 */
	public function refund( int $request_id, string $amount, string $reason, bool $restock, string $actor_type = EventLog::ACTOR_USER, bool $manual = false ): void {
		[ $request, $order ] = $this->load( $request_id );
		$status              = $this->workflow->current_status( $request_id );
		if ( ! $status->can_transition_to( Status::Refunded ) ) {
			throw new RuntimeException( esc_html( __( 'This request cannot be refunded in its current status.', 'primeplaced-withdrawal-button' ) ) );
		}

		$decimals = wc_get_price_decimals();
		$plan     = $this->plan( $request_id );
		$minor    = '' === trim( $amount ) ? $plan['amount_minor'] : Money::to_minor( wc_format_decimal( $amount ), $decimals );
		$left     = Money::to_minor( (string) $order->get_total(), $decimals ) - Money::to_minor( (string) $order->get_total_refunded(), $decimals );

		if ( $minor <= 0 || $minor > $plan['amount_minor'] || $minor > $left ) {
			throw new RuntimeException( esc_html( __( 'The refund amount must be positive and cannot exceed the planned amount or what is left to refund on the order.', 'primeplaced-withdrawal-button' ) ) );
		}

		$deduction = $minor < $plan['amount_minor'];
		if ( $deduction && '' === trim( $reason ) ) {
			throw new RuntimeException( esc_html( __( 'A lower amount needs a reason (for example reduced value of the goods).', 'primeplaced-withdrawal-button' ) ) );
		}

		$line_items = $plan['line_items'];
		if ( $deduction ) {
			// With a deduction the amount is set by hand; lines keep quantities for restocking only.
			foreach ( $line_items as $id => $line ) {
				$line_items[ $id ] = array(
					'qty'          => $line['qty'],
					'refund_total' => '0',
					'refund_tax'   => array(),
				);
			}
		}

		// The meta must exist before WooCommerce sends its refund email, which
		// happens inside wc_create_refund(), so it is set on "refund created".
		$tag = static function ( int $refund_id ) use ( $request_id ): void {
			$created = wc_get_order( $refund_id );
			if ( $created instanceof \WC_Order_Refund ) {
				$created->update_meta_data( self::REQUEST_META, (string) $request_id );
				$created->save_meta_data();
			}
		};
		add_action( 'woocommerce_refund_created', $tag );
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => Money::to_decimal( $minor, $decimals ),
				/* translators: 1: request number, 2: optional deduction reason. */
				'reason'         => trim( sprintf( __( 'Withdrawal %1$s %2$s', 'primeplaced-withdrawal-button' ), (string) $request['number'], $reason ) ),
				'line_items'     => $line_items,
				'refund_payment' => $plan['automatic'] && ! $manual,
				'restock_items'  => $restock,
			)
		);

		remove_action( 'woocommerce_refund_created', $tag );

		if ( is_wp_error( $refund ) ) {
			// No refund exists now; the shop sees the gateway message and may refund manually.
			$this->events->add( $request_id, 'refund_failed', array( 'error' => $refund->get_error_message() ), $actor_type );
			$this->requests->set_flag( $request_id, 'action_required', true );
			throw new RuntimeException( esc_html( $refund->get_error_message() ) );
		}

		$ids   = array_filter( explode( ',', (string) $request['wc_refund_ids'] ) );
		$ids[] = (string) $refund->get_id();
		$total = Money::to_minor( (string) $request['refunded_total'], $decimals ) + $minor;

		$this->requests->update(
			$request_id,
			array(
				'refunded_total' => Money::to_decimal( $total, $decimals ),
				'wc_refund_ids'  => implode( ',', $ids ),
			)
		);
		$this->events->add(
			$request_id,
			'refunded',
			array(
				'amount'    => Money::to_decimal( $minor, $decimals ),
				'refund_id' => $refund->get_id(),
				'automatic' => $plan['automatic'] && ! $manual,
				'reason'    => $reason,
			),
			$actor_type
		);

		/**
		 * Fires after a WooCommerce refund was created for a request.
		 *
		 * @param int    $request_id Request ID.
		 * @param int    $refund_id  WooCommerce refund ID.
		 * @param string $amount     Refunded amount (decimal string).
		 */
		do_action( 'ppwb_refund_created', $request_id, $refund->get_id(), Money::to_decimal( $minor, $decimals ) );

		$message = $deduction
			/* translators: %s: deduction reason. */
			? sprintf( __( 'The amount was reduced. Reason: %s', 'primeplaced-withdrawal-button' ), $reason )
			: '';
		$this->workflow->change_status( $request_id, Status::Refunded, $message, $actor_type );
	}

	/**
	 * Whether the payment gateway refunds automatically. Cash on delivery
	 * and bank transfer never do; neither does a gateway that was switched off
	 * or removed after the purchase.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public function gateway_refunds( WC_Order $order ): bool {
		if ( in_array( $order->get_payment_method(), array( 'cod', 'bacs', 'cheque', '' ), true ) ) {
			return false;
		}

		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway  = $gateways[ $order->get_payment_method() ] ?? null;

		return null !== $gateway && 'yes' === $gateway->enabled && $gateway->supports( 'refunds' );
	}

	/**
	 * How the money goes back, for the panel and the customer.
	 *
	 * @param WC_Order $order        Order.
	 * @param string   $bank_account Decrypted account number, '' when none.
	 * @return string
	 */
	public function method_label( WC_Order $order, string $bank_account = '' ): string {
		$title = $order->get_payment_method_title();
		if ( $this->gateway_refunds( $order ) ) {
			/* translators: %s: payment method name. */
			return sprintf( __( 'Automatically through %s', 'primeplaced-withdrawal-button' ), '' !== $title ? $title : $order->get_payment_method() );
		}

		if ( '' !== $bank_account ) {
			/* translators: %s: last digits of the bank account. */
			return sprintf( __( 'Manual bank transfer to account ...%s', 'primeplaced-withdrawal-button' ), substr( preg_replace( '/\s+/', '', $bank_account ) ?? '', -4 ) );
		}

		return __( 'Manual bank transfer', 'primeplaced-withdrawal-button' );
	}

	/**
	 * How the money goes back, as the end of a sentence addressed to the customer
	 * (success screen and emails).
	 *
	 * @param WC_Order $order            Order.
	 * @param bool     $has_bank_account Whether the customer gave an account number.
	 * @return string
	 */
	public function customer_method_label( WC_Order $order, bool $has_bank_account ): string {
		if ( $has_bank_account || ! $this->gateway_refunds( $order ) ) {
			return __( 'by bank transfer to the account you gave', 'primeplaced-withdrawal-button' );
		}

		$title = $order->get_payment_method_title();

		/* translators: %s: payment method name. */
		return sprintf( __( 'to the account you paid from (%s)', 'primeplaced-withdrawal-button' ), '' !== $title ? $title : $order->get_payment_method() );
	}

	/**
	 * Whether this request, earlier open requests and WooCommerce refunds
	 * together cover every product line the customer may return.
	 *
	 * @param WC_Order        $order   Order.
	 * @param array<int, int> $covered Order item ID => quantity in this request.
	 * @param array<int, int> $earlier Order item ID => quantity in earlier requests not refunded yet.
	 * @return bool
	 */
	private function returns_everything( WC_Order $order, array $covered, array $earlier ): bool {
		foreach ( $order->get_items() as $item_id => $item ) {
			$left = (int) $item->get_quantity() - absint( $order->get_qty_refunded_for_item( $item_id ) ) - ( $earlier[ (int) $item_id ] ?? 0 );
			if ( $left > ( $covered[ (int) $item_id ] ?? 0 ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Request row and order.
	 *
	 * @param int $request_id Request ID.
	 * @throws RuntimeException When either is missing.
	 * @return array{0: array<string, string|int|null>, 1: WC_Order}
	 */
	private function load( int $request_id ): array {
		$request = $this->requests->find( $request_id );
		$order   = null === $request ? false : wc_get_order( (int) $request['order_id'] );
		if ( null === $request || ! $order instanceof WC_Order ) {
			throw new RuntimeException( esc_html( __( 'Request or order not found.', 'primeplaced-withdrawal-button' ) ) );
		}

		return array( $request, $order );
	}
}
