<?php
/**
 * My account: Returns tab, order buttons and the link in the "completed" email.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Frontend;

use PrimePlaced\WithdrawalButton\Backend\StatusView;
use PrimePlaced\WithdrawalButton\Data\RequestRepository;
use PrimePlaced\WithdrawalButton\Data\Settings;
use PrimePlaced\WithdrawalButton\Domain\Status;
use PrimePlaced\WithdrawalButton\Engine\Module;
use PrimePlaced\WithdrawalButton\Integrations\WithdrawalService;
use WC_Email;
use WC_Order;

/**
 * Class MyAccount
 */
final class MyAccount implements Module {

	public const ENDPOINT = 'returns';

	/**
	 * Creates the module.
	 *
	 * @param RequestRepository $requests Request storage.
	 * @param WithdrawalService $service  Withdrawal rules.
	 * @param Settings          $settings Plugin settings.
	 * @param FormToken         $tokens   Signatures.
	 */
	public function __construct(
		private RequestRepository $requests,
		private WithdrawalService $service,
		private Settings $settings,
		private FormToken $tokens
	) {
	}

	/**
	 * Registers the endpoint. Also called on activation before flushing rules.
	 *
	 * @return void
	 */
	public static function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Adds the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( self::class, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_returns' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'title' ) );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'order_action' ), 10, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'order_details_button' ) );
		// Late in the content, before the footer. Some email customizers drop the
		// customer details block, so the footer hook prints it when nothing did.
		add_action( 'woocommerce_email_customer_details', array( $this, 'email_button' ), 40, 4 );
		add_action( 'woocommerce_email_footer', array( $this, 'email_button_fallback' ), 5 );
	}

	/**
	 * Registers the query var with WooCommerce.
	 *
	 * @param array<string, string> $vars Query vars.
	 * @return array<string, string>
	 */
	public function query_vars( array $vars ): array {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;

		return $vars;
	}

	/**
	 * Adds "Returns" before "Log out".
	 *
	 * @param array<string, string> $items Menu items.
	 * @return array<string, string>
	 */
	public function menu_item( array $items ): array {
		$logout = $items['customer-logout'] ?? null;
		unset( $items['customer-logout'] );
		$items[ self::ENDPOINT ] = __( 'Returns', 'primeplaced-withdrawal-button' );
		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}

		return $items;
	}

	/**
	 * Endpoint title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Returns', 'primeplaced-withdrawal-button' );
	}

	/**
	 * Lists the customer's requests.
	 *
	 * @return void
	 */
	public function render_returns(): void {
		$user     = wp_get_current_user();
		$requests = $this->requests->for_customer( (string) $user->user_email, (int) $user->ID );

		Templates::render(
			'account/returns',
			array(
				'requests' => array_map(
					static fn ( array $request ): array => $request + array(
						'status_label' => null === Status::tryFrom( (string) $request['status'] ) ? (string) $request['status'] : StatusView::label( Status::from( (string) $request['status'] ) ),
						'status_url'   => StatusPage::url_for( (int) $request['id'] ),
						'order'        => wc_get_order( (int) $request['order_id'] ),
						'amount'       => wc_price( (float) $request['estimated_total'], array( 'currency' => (string) $request['currency'] ) ),
					),
					$requests
				),
				'form_url' => $this->form_url(),
			)
		);
	}

	/**
	 * "Withdraw from contract here" next to orders in the withdrawal period.
	 *
	 * @param array<string, array{url: string, name: string}> $actions Order actions.
	 * @param WC_Order                                        $order   Order.
	 * @return array<string, array{url: string, name: string}>
	 */
	public function order_action( array $actions, WC_Order $order ): array {
		if ( $this->settings->flag( 'button_in_account' ) && null === $this->service->blocking_reason( $order ) ) {
			$actions['ppwb-withdraw'] = array(
				'url'  => $this->account_link( $order ),
				'name' => $this->settings->button_label(),
			);
		}

		return $actions;
	}

	/**
	 * Same button on the order details page in My account.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function order_details_button( WC_Order $order ): void {
		if ( ! is_account_page() || ! $this->settings->flag( 'button_in_account' ) || null !== $this->service->blocking_reason( $order ) ) {
			return;
		}

		printf(
			'<p><a class="button ppwb-withdraw" href="%1$s">%2$s</a></p>',
			esc_url( $this->account_link( $order ) ),
			esc_html( $this->settings->button_label() )
		);
	}

	/**
	 * Emails that already got the button, so the fallback does not repeat it.
	 *
	 * @var array<int, true>
	 */
	private array $printed = array();

	/**
	 * Adds "Withdraw from contract here" with a tokenized link at the bottom of
	 * the chosen customer emails.
	 *
	 * Hook arguments are loosely typed by WooCommerce, so they are checked here.
	 *
	 * @param mixed $order         Order (WC_Order for order emails).
	 * @param mixed $sent_to_admin Whether the email goes to the shop.
	 * @param mixed $plain_text    Plain text email.
	 * @param mixed $email         Email object.
	 * @return void
	 */
	public function email_button( mixed $order, mixed $sent_to_admin, mixed $plain_text, mixed $email = null ): void {
		if ( $sent_to_admin || ! $order instanceof WC_Order || ! $email instanceof WC_Email || isset( $this->printed[ spl_object_id( $email ) ] ) ) {
			return;
		}
		if ( ! in_array( $email->id, $this->button_emails(), true ) || null !== $this->service->blocking_reason( $order ) ) {
			return;
		}
		$this->printed[ spl_object_id( $email ) ] = true;

		$url  = $this->email_link( $order );
		$text = sprintf(
			/* translators: %d: withdrawal period in days. */
			__( 'You have %d days to withdraw from the contract without giving a reason.', 'primeplaced-withdrawal-button' ),
			$this->settings->withdrawal_period()->days()
		);
		$label = $this->settings->button_label();

		if ( $plain_text ) {
			echo "\n" . esc_html( $text ) . "\n" . esc_html( $label ) . ': ' . esc_url_raw( $url ) . "\n";
			return;
		}

		printf(
			'<p style="margin:24px 0 8px;">%1$s</p><p style="margin:0 0 24px;"><a href="%2$s" style="display:inline-block;padding:10px 18px;border-radius:4px;background:#1d4ed8;color:#ffffff;text-decoration:none;font-weight:600;">%3$s</a></p>',
			esc_html( $text ),
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Prints the button from the footer hook when the template skipped the customer details.
	 *
	 * @param mixed $email Email object.
	 * @return void
	 */
	public function email_button_fallback( mixed $email = null ): void {
		if ( ! $email instanceof WC_Email || ! $email->object instanceof WC_Order ) {
			return;
		}

		$this->email_button( $email->object, ! $email->is_customer_email(), 'plain' === $email->get_email_type(), $email );
	}

	/**
	 * WooCommerce email IDs that carry the button.
	 *
	 * @return list<string>
	 */
	private function button_emails(): array {
		$ids = array();
		if ( $this->settings->flag( 'button_in_completed_email' ) ) {
			$ids[] = 'customer_completed_order';
		}
		if ( $this->settings->flag( 'button_in_processing_email' ) ) {
			$ids[] = 'customer_processing_order';
		}

		return $ids;
	}

	/**
	 * Verifies an email link "orderId.signature" and returns the order.
	 *
	 * @param string    $value  Link value.
	 * @param FormToken $tokens Signer.
	 * @return WC_Order|null
	 */
	public static function order_from_email_token( string $value, FormToken $tokens ): ?WC_Order {
		$parts = explode( '.', $value, 2 );
		$order = 2 === count( $parts ) ? wc_get_order( absint( $parts[0] ) ) : false;
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		return hash_equals( $tokens->sign( 'email|' . $order->get_id() ), $parts[1] ) ? $order : null;
	}

	/**
	 * Link for a logged-in owner: signed with the order and user ID.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function account_link( WC_Order $order ): string {
		$value = $order->get_id() . '.' . $this->tokens->sign( 'account|' . $order->get_id() . '|' . get_current_user_id() );

		return add_query_arg( 'ppwb_account', $value, $this->form_url() );
	}

	/**
	 * Signed link to the form with this order; the same in every email, so
	 * an earlier email keeps working. The form still checks the deadline.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function email_link( WC_Order $order ): string {
		return add_query_arg( 'ppwb_ot', $order->get_id() . '.' . $this->tokens->sign( 'email|' . $order->get_id() ), $this->form_url() );
	}

	/**
	 * Form page URL.
	 *
	 * @return string
	 */
	private function form_url(): string {
		$url = get_permalink( $this->settings->form_page_id() );

		return is_string( $url ) ? $url : home_url( '/' );
	}
}
