<?php
/**
 * WooCommerce > Settings > Returns.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Backend\Settings;

use PrimePlaced\WithdrawalButton\Backend\BrandBanner;
use PrimePlaced\WithdrawalButton\Backend\SetupWizard;
use PrimePlaced\WithdrawalButton\Backend\Tools;
use PrimePlaced\WithdrawalButton\Data\Settings;
use PrimePlaced\WithdrawalButton\Domain\RequestNumber;
use PrimePlaced\WithdrawalButton\Domain\WithdrawalDeadline;
use PrimePlaced\WithdrawalButton\Domain\WithdrawalPeriod;
use PrimePlaced\WithdrawalButton\Emails\Mailer;
use PrimePlaced\WithdrawalButton\Frontend\StatusPage;
use PrimePlaced\WithdrawalButton\Integrations\Dates;
use WC_Settings_Page;

/**
 * Class ReturnsSettingsPage
 *
 * Loaded only from the woocommerce_get_settings_pages filter, when
 * WC_Settings_Page exists. Values are stored in the ppwb_settings array
 * option through WooCommerce's "option[key]" field IDs.
 */
final class ReturnsSettingsPage extends WC_Settings_Page {

	public const TAB = 'ppwb';

	/**
	 * Registers the tab.
	 */
	public function __construct() {
		$this->id    = self::TAB;
		$this->label = __( 'Returns', 'primeplaced-withdrawal-button' );

		parent::__construct();
	}

	/**
	 * Prints the brand banner above WooCommerce's own fields.
	 *
	 * @return void
	 */
	public function output(): void {
		global $current_section;

		BrandBanner::render();
		if ( 'tools' === $current_section ) {
			$GLOBALS['hide_save_button'] = true; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce settings flag.
			( new Tools( new Settings() ) )->render();
			return;
		}
		if ( 'pro' === $current_section ) {
			$GLOBALS['hide_save_button'] = true; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce settings flag.
			$this->render_pro();
			return;
		}

		// "Copy" next to the shortcodes; printed in the footer.
		wp_register_script( 'ppwb-settings', false, array(), PPWB_VERSION, true );
		wp_enqueue_script( 'ppwb-settings' );
		wp_add_inline_script(
			'ppwb-settings',
			'document.addEventListener("click",function(e){var b=e.target.closest&&e.target.closest(".ppwb-copy-code");if(!b||!navigator.clipboard){return;}navigator.clipboard.writeText(b.dataset.copy).then(function(){b.textContent=b.dataset.copied;});});'
		);

		$settings = new Settings();
		if ( ! $settings->flag( 'button_in_footer' ) && ! $settings->flag( 'button_in_account' ) && ! $settings->flag( 'button_in_completed_email' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The withdrawal button is switched off everywhere. The law requires it to be easy to find for the whole withdrawal period: keep at least one place, or add the block or the [ppwb_button] shortcode yourself.', 'primeplaced-withdrawal-button' ) . '</p></div>';
		}

		if ( 'message' === $settings->get( 'before_shipment' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Orders before shipment: customers of orders that are not shipped yet see only a message. The consumer may withdraw from the contract also before delivery (Directive 2011/83/EU, art. 9 sec. 3), so this setting may be seen as making withdrawal harder. This is not legal advice.', 'primeplaced-withdrawal-button' ) . '</p></div>';
		}

		parent::output();
	}

	/**
	 * Sections: settings and tools (export, import, diagnostics).
	 *
	 * @return array<string, string>
	 */
	protected function get_own_sections(): array {
		$sections = array(
			''      => __( 'Settings', 'primeplaced-withdrawal-button' ),
			'tools' => __( 'Tools', 'primeplaced-withdrawal-button' ),
		);
		if ( self::shows_upsell() ) {
			$sections['pro'] = 'Pro';
		}

		return $sections;
	}

	/**
	 * The only place with the Pro offer: no "Upgrade" menu item.
	 *
	 * @return bool
	 */
	private static function shows_upsell(): bool {
		return function_exists( 'ppwb_fs' ) && null !== ppwb_fs() && ppwb_fs()->is_not_paying();
	}

	/**
	 * "Pro" section: what Pro adds, with one purchase button.
	 *
	 * @return void
	 */
	private function render_pro(): void {
		$freemius = function_exists( 'ppwb_fs' ) ? ppwb_fs() : null;
		if ( null === $freemius || ! $freemius->is_not_paying() ) {
			return;
		}
		$features = array(
			__( 'Return labels and parcel locker codes (InPost, Sendcloud) with tracking', 'primeplaced-withdrawal-button' ),
			__( 'Correction invoices from your invoicing plugin', 'primeplaced-withdrawal-button' ),
			__( 'Returns report in WooCommerce Analytics and CSV export', 'primeplaced-withdrawal-button' ),
			__( 'Automations, reply templates, webhooks and complaints', 'primeplaced-withdrawal-button' ),
			__( 'White label, settings export and import, WP-CLI commands', 'primeplaced-withdrawal-button' ),
		);
		?>
		<h2><?php esc_html_e( 'PrimePlaced - Withdrawal Button Pro', 'primeplaced-withdrawal-button' ); ?></h2>
		<p><?php esc_html_e( 'The free version handles the whole statutory withdrawal process without limits. Pro adds integrations and tools that save time on many returns:', 'primeplaced-withdrawal-button' ); ?></p>
		<ul class="ul-disc">
			<?php foreach ( $features as $feature ) : ?>
				<li><?php echo esc_html( $feature ); ?></li>
			<?php endforeach; ?>
		</ul>
		<p><?php esc_html_e( 'One Pro plan includes everything above. You only choose the number of sites.', 'primeplaced-withdrawal-button' ); ?></p>
		<p><a class="button button-primary" href="<?php echo esc_url( $freemius->get_upgrade_url() ); ?>"><?php esc_html_e( 'Buy Pro', 'primeplaced-withdrawal-button' ); ?></a></p>
		<?php
	}

	/**
	 * Fields of the default section.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function get_settings_for_default_section(): array {
		$option = Settings::OPTION;

		return array(
			array(
				'id'    => 'ppwb_general',
				'type'  => 'title',
				'title' => __( 'Withdrawal', 'primeplaced-withdrawal-button' ),
				'desc'  => __( 'This plugin does not provide legal advice. Check your obligations with a lawyer.', 'primeplaced-withdrawal-button' ) . ' <a href="' . esc_url( SetupWizard::url() ) . '">' . __( 'Open the setup wizard', 'primeplaced-withdrawal-button' ) . '</a>',
			),
			array(
				'id'                => $option . '[withdrawal_days]',
				'type'              => 'number',
				'title'             => __( 'Withdrawal period (days)', 'primeplaced-withdrawal-button' ),
				'desc'              => __( 'The law requires at least 14 days, counted from delivery. When the delivery date is unknown, the period counts from the change to Completed plus the days for delivery.', 'primeplaced-withdrawal-button' ),
				'default'           => (string) WithdrawalPeriod::MIN_DAYS,
				'custom_attributes' => array(
					'min'  => WithdrawalPeriod::MIN_DAYS,
					'step' => 1,
				),
				'css'               => 'width: 80px;',
			),
			array(
				'id'      => $option . '[deadline_from]',
				'type'    => 'select',
				'title'   => __( 'Count the period from', 'primeplaced-withdrawal-button' ),
				'desc'    => __( 'A delivery date entered in the order always takes priority.', 'primeplaced-withdrawal-button' ),
				'options' => array(
					WithdrawalDeadline::FROM_COMPLETED => __( 'The change to Completed', 'primeplaced-withdrawal-button' ),
					WithdrawalDeadline::FROM_PAID      => __( 'The payment date (shops that do not use Completed)', 'primeplaced-withdrawal-button' ),
				),
				'default' => WithdrawalDeadline::FROM_COMPLETED,
			),
			array(
				'id'                => $option . '[delivery_days]',
				'type'              => 'number',
				'title'             => __( 'Extra days for delivery', 'primeplaced-withdrawal-button' ),
				'desc'              => __( 'Most shops mark an order Completed when it ships, not when it arrives. A longer period is allowed, a shorter one is not.', 'primeplaced-withdrawal-button' ),
				'default'           => (string) WithdrawalDeadline::DEFAULT_DELIVERY_DAYS,
				'custom_attributes' => array(
					'min'  => 0,
					'max'  => WithdrawalDeadline::MAX_DAYS,
					'step' => 1,
				),
				'css'               => 'width: 80px;',
			),
			array(
				'id'                => $option . '[unfulfilled_days]',
				'type'              => 'number',
				'title'             => __( 'Max days since payment', 'primeplaced-withdrawal-button' ),
				'desc'              => __( 'Default 30. A paid order that does not have the status Completed closes this many days after payment, and never sooner than the period plus the days for delivery. 0 means no limit - use it only if your shop always sets the status Completed when it ships the order.', 'primeplaced-withdrawal-button' ),
				'default'           => (string) WithdrawalDeadline::DEFAULT_UNFULFILLED_DAYS,
				'custom_attributes' => array(
					'min'  => 0,
					'max'  => WithdrawalDeadline::MAX_DAYS,
					'step' => 1,
				),
				'css'               => 'width: 80px;',
			),
			array(
				'id'      => $option . '[return_shipping_paid_by]',
				'type'    => 'select',
				'title'   => __( 'Return shipping paid by', 'primeplaced-withdrawal-button' ),
				'desc'    => __( 'Cost of sending the products back to the shop (Art. 34). It does not change the refund of the delivery the customer paid for when ordering: that is refunded when the whole order is returned.', 'primeplaced-withdrawal-button' ),
				'options' => array(
					'customer' => __( 'The customer', 'primeplaced-withdrawal-button' ),
					'shop'     => __( 'The shop', 'primeplaced-withdrawal-button' ),
				),
				'default' => 'customer',
			),
			array(
				'id'       => $option . '[cheapest_shipping]',
				'type'     => 'text',
				'title'    => __( 'Cheapest standard delivery (gross)', 'primeplaced-withdrawal-button' ),
				'desc'     => __( 'Optional. When the whole order is returned, the delivery cost is refunded up to this amount; a dearer delivery chosen by the customer is refunded only up to it (Art. 33). Empty: the full delivery cost paid is refunded.', 'primeplaced-withdrawal-button' ),
				'desc_tip' => false,
				'default'  => '',
				'css'      => 'width: 100px;',
			),
			array(
				'id'      => $option . '[late_requests]',
				'type'    => 'select',
				'title'   => __( 'After the withdrawal period', 'primeplaced-withdrawal-button' ),
				'options' => array(
					'block'         => __( 'Show a message with the shop contact', 'primeplaced-withdrawal-button' ),
					'allow_flagged' => __( 'Accept the request, flagged as late', 'primeplaced-withdrawal-button' ),
				),
				'default' => 'block',
			),
			array(
				'id'       => $option . '[before_shipment]',
				'type'     => 'select',
				'title'    => __( 'Orders before shipment', 'primeplaced-withdrawal-button' ),
				'desc'     => __( 'Orders "Processing" or "On hold". The consumer may withdraw before delivery too, so blocking the form may be seen as making withdrawal harder.', 'primeplaced-withdrawal-button' ),
				'desc_tip' => false,
				'options'  => array(
					'cancel'  => __( 'Accept the request as a cancellation before shipment (recommended)', 'primeplaced-withdrawal-button' ),
					'message' => __( 'Show only the order status and the shop contact', 'primeplaced-withdrawal-button' ),
				),
				'default'  => 'cancel',
			),
			array(
				'id'      => $option . '[reason_list]',
				'type'    => 'checkbox',
				'title'   => __( 'Return reasons', 'primeplaced-withdrawal-button' ),
				'desc'    => __( 'Let customers pick a reason from a list. Giving a reason stays optional.', 'primeplaced-withdrawal-button' ),
				'default' => 'yes',
			),
			array(
				'id'       => $option . '[return_reasons]',
				'type'     => 'textarea',
				'title'    => __( 'Reason list', 'primeplaced-withdrawal-button' ),
				'desc'     => __( 'One reason per line, optionally as code|Label. Keep codes unchanged so statistics stay comparable. Empty: the default list. "Other" with a comment field is always added.', 'primeplaced-withdrawal-button' ),
				'default'  => '',
				'css'      => 'min-height: 110px;',
				'desc_tip' => false,
			),
			array(
				'id'          => $option . '[shop_email]',
				'type'        => 'email',
				'title'       => __( 'Shop email for notifications', 'primeplaced-withdrawal-button' ),
				'desc'        => __( 'New requests, tracking numbers and refund reminders. Empty: the site administrator email. A recipient set in WooCommerce > Settings > Emails wins.', 'primeplaced-withdrawal-button' ),
				'desc_tip'    => false,
				'placeholder' => (string) get_option( 'admin_email' ),
				'default'     => '',
			),
			array(
				'id'      => $option . '[restock_default]',
				'type'    => 'checkbox',
				'title'   => __( 'Stock', 'primeplaced-withdrawal-button' ),
				'desc'    => __( 'Return products to stock with a refund (preselected in the refund box)', 'primeplaced-withdrawal-button' ),
				'default' => 'yes',
			),
			array(
				'id'       => $option . '[business_orders]',
				'type'     => 'select',
				'title'    => __( 'Orders with a tax ID (NIP)', 'primeplaced-withdrawal-button' ),
				'desc'     => __( 'A sole trader buying outside their profession has consumer rights (Art. 38a), and a tax ID alone does not tell. "Exclude" may refuse a right the customer has. The tax ID is read from common invoice plugins (filter ppwb_order_tax_id).', 'primeplaced-withdrawal-button' ),
				'desc_tip' => false,
				'options'  => array(
					'consumer' => __( 'Treat like consumer orders (recommended)', 'primeplaced-withdrawal-button' ),
					'ask'      => __( 'Ask the customer to confirm consumer status', 'primeplaced-withdrawal-button' ),
					'block'    => __( 'Exclude from the form', 'primeplaced-withdrawal-button' ),
				),
				'default'  => 'consumer',
			),
			array(
				'id'      => $option . '[order_notes]',
				'type'    => 'select',
				'title'   => __( 'Order notes', 'primeplaced-withdrawal-button' ),
				'desc'    => __( 'Key events: the statement, the refund, a rejection or a cancellation. Everything else (emails, other statuses, tracking) stays in the request history.', 'primeplaced-withdrawal-button' ),
				'options' => array(
					'key' => __( 'Key events only', 'primeplaced-withdrawal-button' ),
					'all' => __( 'All events', 'primeplaced-withdrawal-button' ),
				),
				'default' => 'key',
			),
			array(
				'id'      => $option . '[refund_email]',
				'type'    => 'select',
				'title'   => __( 'Email after a refund', 'primeplaced-withdrawal-button' ),
				'desc'    => __( 'Which email the customer gets when you refund from the request. The WooCommerce email shows the whole order and the line "Withdrawal ZW-..." with the refund.', 'primeplaced-withdrawal-button' ),
				'options' => array(
					'woocommerce' => __( 'WooCommerce "Refunded order" email', 'primeplaced-withdrawal-button' ),
					'plugin'      => __( 'Plugin "Payment refunded" email', 'primeplaced-withdrawal-button' ),
					'both'        => __( 'Both', 'primeplaced-withdrawal-button' ),
				),
				'default' => 'woocommerce',
			),
			array(
				'id'      => $option . '[set_order_status]',
				'type'    => 'checkbox',
				'title'   => __( 'Order status', 'primeplaced-withdrawal-button' ),
				'desc'    => __( 'Change the order status to "Withdrawal from contract" when a request arrives.', 'primeplaced-withdrawal-button' ),
				'default' => 'no',
			),
			array(
				'id'                => $option . '[refund_reminder_days]',
				'type'              => 'number',
				'title'             => __( 'Refund reminder (days before the deadline)', 'primeplaced-withdrawal-button' ),
				'desc'              => __( 'Daily email to the shop about refunds due within this many days and refunds already late. No email when there is nothing to refund.', 'primeplaced-withdrawal-button' ) . ' ' . $this->next_reminder_text(),
				'desc_tip'          => false,
				'default'           => '3',
				'custom_attributes' => array(
					'min'  => 0,
					'step' => 1,
				),
				'css'               => 'width: 80px;',
			),
			array(
				'id'      => $option . '[date_style]',
				'type'    => 'select',
				'title'   => __( 'Date format', 'primeplaced-withdrawal-button' ),
				'desc'    => __( 'Used in emails, the PDF, the form and the panel, always in the shop timezone.', 'primeplaced-withdrawal-button' ),
				'options' => array(
					''          => __( 'Automatic (Polish 24 h for a Polish site)', 'primeplaced-withdrawal-button' ),
					'polish'    => __( 'Polish 24 h: 9 listopada 2026, 08:51', 'primeplaced-withdrawal-button' ),
					'wordpress' => __( 'WordPress date and time format', 'primeplaced-withdrawal-button' ),
				),
				'default' => '',
			),
			array(
				'id'   => 'ppwb_general',
				'type' => 'sectionend',
			),
			array(
				'id'    => 'ppwb_address',
				'type'  => 'title',
				'title' => __( 'Return address and seller', 'primeplaced-withdrawal-button' ),
				'desc'  => $this->address_preview(),
			),
			array(
				'id'      => $option . '[return_company]',
				'type'    => 'text',
				'title'   => __( 'Return address: company or name', 'primeplaced-withdrawal-button' ),
				'default' => '',
			),
			array(
				'id'      => $option . '[return_street]',
				'type'    => 'text',
				'title'   => __( 'Street and number', 'primeplaced-withdrawal-button' ),
				'default' => '',
			),
			array(
				'id'      => $option . '[return_postcode]',
				'type'    => 'text',
				'title'   => __( 'Postcode', 'primeplaced-withdrawal-button' ),
				'default' => '',
				'css'     => 'width: 100px;',
			),
			array(
				'id'      => $option . '[return_city]',
				'type'    => 'text',
				'title'   => __( 'City', 'primeplaced-withdrawal-button' ),
				'default' => '',
			),
			array(
				'id'      => $option . '[return_country]',
				'type'    => 'select',
				'title'   => __( 'Country', 'primeplaced-withdrawal-button' ),
				'class'   => 'wc-enhanced-select',
				'options' => array( '' => __( 'Same as the store', 'primeplaced-withdrawal-button' ) ) + ( function_exists( 'WC' ) ? WC()->countries->get_countries() : array() ),
				'default' => '',
			),
			array(
				'id'       => $option . '[seller_name]',
				'type'     => 'text',
				'title'    => __( 'Seller name', 'primeplaced-withdrawal-button' ),
				'desc'     => __( 'Full company name for the PDF confirmation. Empty: the site name.', 'primeplaced-withdrawal-button' ),
				'desc_tip' => false,
				'default'  => '',
			),
			array(
				'id'      => $option . '[shop_tax_id]',
				'type'    => 'text',
				'title'   => __( 'Tax ID (NIP)', 'primeplaced-withdrawal-button' ),
				'desc'    => __( 'Printed in the PDF confirmation.', 'primeplaced-withdrawal-button' ),
				'default' => '',
			),
			array(
				'id'   => 'ppwb_address',
				'type' => 'sectionend',
			),
			array(
				'id'    => 'ppwb_button',
				'type'  => 'title',
				'title' => __( 'Withdrawal button', 'primeplaced-withdrawal-button' ),
				'desc'  => $this->shortcodes_help(),
			),
			array(
				'id'            => $option . '[button_in_footer]',
				'type'          => 'checkbox',
				'title'         => __( 'Show the button', 'primeplaced-withdrawal-button' ),
				'desc'          => __( 'In the footer of every page', 'primeplaced-withdrawal-button' ),
				'default'       => 'yes',
				'checkboxgroup' => 'start',
			),
			array(
				'id'            => $option . '[button_in_account]',
				'type'          => 'checkbox',
				'desc'          => __( 'Next to orders in My account', 'primeplaced-withdrawal-button' ),
				'default'       => 'yes',
				'checkboxgroup' => '',
			),
			array(
				'id'            => $option . '[button_in_completed_email]',
				'type'          => 'checkbox',
				'desc'          => __( 'In the "Completed order" email', 'primeplaced-withdrawal-button' ),
				'default'       => 'yes',
				'checkboxgroup' => '',
			),
			array(
				'id'            => $option . '[button_in_processing_email]',
				'type'          => 'checkbox',
				'desc'          => __( 'In the "Processing order" email (cancellation before shipment)', 'primeplaced-withdrawal-button' ),
				'default'       => 'yes',
				'checkboxgroup' => 'end',
			),
			array(
				'id'      => $option . '[exclusion_notice]',
				'type'    => 'checkbox',
				'title'   => __( 'Excluded products', 'primeplaced-withdrawal-button' ),
				'desc'    => __( 'Tell customers on the product page that the right of withdrawal does not apply (statutory exceptions, Art. 38). The law requires this information before the purchase.', 'primeplaced-withdrawal-button' ),
				'default' => 'yes',
			),
			array(
				'id'       => $option . '[button_label]',
				'type'     => 'text',
				'title'    => __( 'Button label', 'primeplaced-withdrawal-button' ),
				'desc'     => __( 'Empty: "Withdraw from contract here". The law requires an unambiguous label that says the customer withdraws from the contract; avoid "Returns" or "Help".', 'primeplaced-withdrawal-button' ),
				'desc_tip' => false,
				'default'  => '',
			),
			array(
				'id'       => Settings::FORM_PAGE_OPTION,
				'type'     => 'single_select_page',
				'title'    => __( 'Withdrawal form page', 'primeplaced-withdrawal-button' ),
				'desc'     => __( 'The page with the [ppwb_form] shortcode, created on activation.', 'primeplaced-withdrawal-button' ),
				'desc_tip' => false,
				'class'    => 'wc-page-search',
			),
			array(
				'id'       => StatusPage::PAGE_OPTION,
				'type'     => 'single_select_page',
				'title'    => __( 'Request status page', 'primeplaced-withdrawal-button' ),
				'desc'     => __( 'The page with the [ppwb_status] shortcode, created on activation.', 'primeplaced-withdrawal-button' ),
				'desc_tip' => false,
				'class'    => 'wc-page-search',
			),
			array(
				'id'   => 'ppwb_button',
				'type' => 'sectionend',
			),
			array(
				'id'    => 'ppwb_numbering',
				'type'  => 'title',
				'title' => __( 'Request numbers', 'primeplaced-withdrawal-button' ),
				'desc'  => $this->next_number_text(),
			),
			array(
				'id'       => $option . '[number_format]',
				'type'     => 'text',
				'title'    => __( 'Number format', 'primeplaced-withdrawal-button' ),
				'desc'     => __( 'Placeholders: {RRRR} year, {RR} short year, {MM} month, {NNNNNN} counter (number of N = digits), {ZAMOWIENIE} order number. A new format applies to new requests only.', 'primeplaced-withdrawal-button' ),
				'default'  => RequestNumber::DEFAULT_FORMAT,
				'desc_tip' => false,
			),
			array(
				'id'      => $option . '[counter_scope]',
				'type'    => 'select',
				'title'   => __( 'Counter', 'primeplaced-withdrawal-button' ),
				'options' => array(
					'yearly' => __( 'Yearly (restarts on 1 January)', 'primeplaced-withdrawal-button' ),
					'global' => __( 'Continuous', 'primeplaced-withdrawal-button' ),
				),
				'default' => 'yearly',
			),
			array(
				'id'                => $option . '[counter_start]',
				'type'              => 'number',
				'title'             => __( 'Start counter at', 'primeplaced-withdrawal-button' ),
				'desc'              => __( 'Used when a new counter starts, for example 1000.', 'primeplaced-withdrawal-button' ),
				'default'           => '1',
				'custom_attributes' => array(
					'min'  => 1,
					'step' => 1,
				),
				'css'               => 'width: 100px;',
			),
			array(
				'id'   => 'ppwb_numbering',
				'type' => 'sectionend',
			),
			array(
				'id'    => 'ppwb_privacy',
				'type'  => 'title',
				'title' => __( 'Data', 'primeplaced-withdrawal-button' ),
			),
			array(
				'id'      => $option . '[delete_data_on_uninstall]',
				'type'    => 'checkbox',
				'title'   => __( 'Delete data on uninstall', 'primeplaced-withdrawal-button' ),
				'desc'    => __( 'Remove all requests, settings and the form page when the plugin is deleted. Deactivation never removes data.', 'primeplaced-withdrawal-button' ),
				'default' => 'no',
			),
			array(
				'id'   => 'ppwb_privacy',
				'type' => 'sectionend',
			),
		);
	}

	/**
	 * The four shortcodes with "Copy" buttons.
	 *
	 * @return string
	 */
	private function shortcodes_help(): string {
		$codes = array(
			'[ppwb_button]' => __( 'withdrawal button', 'primeplaced-withdrawal-button' ),
			'[ppwb_info]'   => __( 'information about the right of withdrawal', 'primeplaced-withdrawal-button' ),
			'[ppwb_form]'   => __( 'withdrawal form', 'primeplaced-withdrawal-button' ),
			'[ppwb_status]' => __( 'request status lookup', 'primeplaced-withdrawal-button' ),
		);
		$html  = esc_html__( 'Also available as the "Withdrawal button" block and as shortcodes:', 'primeplaced-withdrawal-button' ) . '<br>';
		foreach ( $codes as $code => $label ) {
			$html .= sprintf(
				'<code>%1$s</code> %2$s <button type="button" class="button-link ppwb-copy-code" data-copy="%1$s" data-copied="%4$s">%3$s</button><br>',
				esc_html( $code ),
				esc_html( $label ),
				esc_html__( 'Copy', 'primeplaced-withdrawal-button' ),
				esc_attr__( 'Copied', 'primeplaced-withdrawal-button' )
			);
		}

		return $html;
	}

	/**
	 * "Next number: ZW-2026-000001" under the numbering title.
	 *
	 * @return string
	 */
	private function next_number_text(): string {
		$settings = new Settings();
		$now      = new \DateTimeImmutable( 'now', wp_timezone() );
		$scope    = 'yearly' === $settings->get( 'counter_scope' ) ? $now->format( 'Y' ) : 'global';
		$counter  = ( new \PrimePlaced\WithdrawalButton\Data\Counter() )->peek( $scope, (int) $settings->get( 'counter_start' ) );
		$number   = RequestNumber::format( (string) $settings->get( 'number_format' ), $counter, $now, '123' );

		/* translators: %s: request number. */
		return sprintf( esc_html__( 'Next number: %s (saved changes apply to new requests).', 'primeplaced-withdrawal-button' ), '<strong>' . esc_html( $number ) . '</strong>' );
	}

	/**
	 * "Next reminder: ..." under the reminder field.
	 *
	 * @return string
	 */
	private function next_reminder_text(): string {
		$next = Mailer::next_reminder();

		return null === $next
			? __( 'The daily task is not scheduled yet; it is restored on the next admin page load.', 'primeplaced-withdrawal-button' )
			/* translators: %s: date and time. */
			: sprintf( __( 'Next reminder: %s.', 'primeplaced-withdrawal-button' ), Dates::datetime( $next ) );
	}

	/**
	 * Preview of the return address customers get, or a warning when there is none.
	 *
	 * @return string
	 */
	private function address_preview(): string {
		$settings = new Settings();
		$address  = $settings->return_address();
		if ( '' === $address ) {
			return '<span class="ppwb-warning">' . esc_html__( 'Customers will not get a return address: fill in the fields below or the store address in WooCommerce > Settings > General.', 'primeplaced-withdrawal-button' ) . '</span>';
		}

		$text = sprintf(
			/* translators: %s: return address. */
			esc_html__( 'Customers get this return address: %s. Empty fields: the store address from WooCommerce > Settings > General.', 'primeplaced-withdrawal-button' ),
			'<strong>' . esc_html( $address ) . '</strong>'
		);
		if ( '' === trim( (string) $settings->get( 'shop_tax_id' ) ) ) {
			$text .= '<br><span class="ppwb-warning">' . esc_html__( 'The tax ID (NIP) is empty, so the PDF confirmation shows no tax ID.', 'primeplaced-withdrawal-button' ) . '</span>';
		}

		return $text;
	}

	/**
	 * Saves, then enforces the statutory minimum on the period.
	 *
	 * @return void
	 */
	public function save(): void {
		global $current_section;
		if ( 'tools' === $current_section ) {
			return;
		}

		parent::save();

		// Server-side limits: the HTML min/max attributes are only a hint.
		$stored = get_option( Settings::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return;
		}
		$clean = $stored;
		if ( isset( $clean['withdrawal_days'] ) ) {
			$clean['withdrawal_days'] = (string) max( WithdrawalPeriod::MIN_DAYS, min( WithdrawalDeadline::MAX_DAYS, (int) $clean['withdrawal_days'] ) );
		}
		foreach ( array( 'delivery_days', 'unfulfilled_days' ) as $key ) {
			if ( isset( $clean[ $key ] ) ) {
				$clean[ $key ] = (string) WithdrawalDeadline::clamp_days( (int) $clean[ $key ] );
			}
		}
		if ( isset( $clean['cheapest_shipping'] ) ) {
			$amount                     = trim( (string) $clean['cheapest_shipping'] );
			$clean['cheapest_shipping'] = '' === $amount ? '' : (string) max( 0, (float) wc_format_decimal( $amount ) );
		}
		if ( isset( $clean['before_shipment'] ) && 'message' !== $clean['before_shipment'] ) {
			$clean['before_shipment'] = 'cancel';
		}
		if ( isset( $clean['deadline_from'] ) && WithdrawalDeadline::FROM_PAID !== $clean['deadline_from'] ) {
			$clean['deadline_from'] = WithdrawalDeadline::FROM_COMPLETED;
		}
		if ( $clean !== $stored ) {
			update_option( Settings::OPTION, $clean );
		}
	}
}
