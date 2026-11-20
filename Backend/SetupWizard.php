<?php
/**
 * Setup wizard after activation.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Backend;

use PrimePlaced\WithdrawalButton\Data\Settings;
use PrimePlaced\WithdrawalButton\Domain\RequestNumber;
use PrimePlaced\WithdrawalButton\Domain\WithdrawalPeriod;
use PrimePlaced\WithdrawalButton\Engine\Module;

/**
 * Class SetupWizard
 *
 * One WooCommerce-style form with the settings a shop needs on day one. It can
 * be skipped and reopened from WooCommerce > Settings > Returns.
 */
final class SetupWizard implements Module {

	public const SLUG = 'ppwb-setup';

	/**
	 * Creates the wizard.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * URL of the wizard.
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/**
	 * Adds the page, the redirect after activation and the reminder notice.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'redirect_after_activation' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_post_ppwb_setup', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'admin_title', array( $this, 'title' ) );
	}

	/**
	 * Browser title for the hidden wizard page.
	 *
	 * @param string $title Admin title.
	 * @return string
	 */
	public function title( string $title ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page check.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return self::SLUG === $page ? __( 'Returns setup', 'primeplaced-withdrawal-button' ) . ' ' . $title : $title;
	}

	/**
	 * Registers the page without a menu entry.
	 *
	 * @return void
	 */
	public function add_page(): void {
		add_submenu_page( '', __( 'Returns setup', 'primeplaced-withdrawal-button' ), '', Lifecycle::CAP_MANAGE_SETTINGS, self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Opens the wizard once after the first activation.
	 *
	 * @return void
	 */
	public function redirect_after_activation(): void {
		if ( ! get_transient( 'ppwb_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'ppwb_activation_redirect' );

		if ( wp_doing_ajax() || is_network_admin() || ! current_user_can( Lifecycle::CAP_MANAGE_SETTINGS ) || isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core flag, read only.
			return;
		}

		// Freemius shows its opt-in first; the notice below links to the wizard afterwards.
		$freemius = function_exists( 'ppwb_fs' ) ? ppwb_fs() : null;
		if ( null !== $freemius && $freemius->is_activation_mode() ) {
			return;
		}

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Reminder until the wizard is finished or skipped, on the Plugins screen and
	 * the plugin's own screens only.
	 *
	 * @return void
	 */
	public function notice(): void {
		$this->address_notice();
		$id = self::screen_id();
		if ( 'no' !== $this->settings->get( 'wizard_done' ) || ! current_user_can( Lifecycle::CAP_MANAGE_SETTINGS ) || str_contains( $id, self::SLUG ) || ! self::is_own_screen( $id, true ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p><strong>%6$s:</strong> %1$s <a class="button button-primary" href="%2$s">%3$s</a> <a href="%4$s">%5$s</a></p></div>',
			esc_html__( 'Set up the return address, the withdrawal period and the button placement.', 'primeplaced-withdrawal-button' ),
			esc_url( self::url() ),
			esc_html__( 'Start setup', 'primeplaced-withdrawal-button' ),
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ppwb_setup&skip=1' ), 'ppwb_setup' ) ),
			esc_html__( 'Skip', 'primeplaced-withdrawal-button' ),
			esc_html( BrandBanner::name() )
		);
	}

	/**
	 * Warns when customers get no return address. Shown only on the plugin's
	 * own screens.
	 *
	 * @return void
	 */
	private function address_notice(): void {
		if ( ! current_user_can( Lifecycle::CAP_MANAGE_SETTINGS ) || 'no' === $this->settings->get( 'wizard_done' ) || ! self::is_own_screen( self::screen_id(), false ) ) {
			return;
		}

		$link = admin_url( 'admin.php?page=wc-settings&tab=ppwb' );
		if ( ! $this->settings->has_return_address() ) {
			printf(
				'<div class="notice notice-error"><p><strong>%1$s:</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
				esc_html( BrandBanner::name() ),
				esc_html__( 'Customers will not get a return address. Fill in the return address.', 'primeplaced-withdrawal-button' ),
				esc_url( $link ),
				esc_html__( 'Open settings', 'primeplaced-withdrawal-button' )
			);
		}
	}

	/**
	 * Current admin screen ID, '' outside a screen.
	 *
	 * @return string
	 */
	private static function screen_id(): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return null === $screen ? '' : (string) $screen->id;
	}

	/**
	 * Whether a screen belongs to the plugin: the returns screens, the wizard and
	 * the Returns tab of the WooCommerce settings, optionally the Plugins screen.
	 *
	 * @param string $id           Screen ID.
	 * @param bool   $with_plugins Whether the Plugins screen counts.
	 * @return bool
	 */
	private static function is_own_screen( string $id, bool $with_plugins ): bool {
		if ( $with_plugins && 'plugins' === $id ) {
			return true;
		}
		if ( str_contains( $id, 'ppwb' ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab check.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return str_contains( $id, 'wc-settings' ) && 'ppwb' === $tab;
	}

	/**
	 * Loads the banner style on the wizard.
	 *
	 * @param string $hook_suffix Hook suffix.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( str_contains( $hook_suffix, self::SLUG ) ) {
			BrandBanner::enqueue_style();
		}
	}

	/**
	 * Prints the wizard.
	 *
	 * @return void
	 */
	public function render(): void {
		$get = fn ( string $key ): string => (string) $this->settings->get( $key );
		BrandBanner::render();
		?>
		<div class="wrap woocommerce">
			<h1><?php esc_html_e( 'Returns setup', 'primeplaced-withdrawal-button' ); ?></h1>
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
			$test = isset( $_GET['ppwb_test'] ) ? sanitize_key( wp_unslash( $_GET['ppwb_test'] ) ) : '';
			?>
			<?php if ( '' !== $test ) : ?>
				<div class="notice notice-<?php echo '1' === $test ? 'success' : 'error'; ?>"><p>
					<?php echo '1' === $test ? esc_html__( 'Test email sent. Check the administrator mailbox.', 'primeplaced-withdrawal-button' ) : esc_html__( 'The test email could not be sent. Configure email delivery (for example an SMTP plugin).', 'primeplaced-withdrawal-button' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=ppwb&section=tools' ) ) . '">' . esc_html__( 'Open diagnostics', 'primeplaced-withdrawal-button' ) . '</a>'; ?>
				</p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Five things and you are ready. You can change everything later in WooCommerce > Settings > Returns.', 'primeplaced-withdrawal-button' ); ?></p>
			<?php if ( in_array( wp_timezone_string(), array( 'UTC', '+00:00' ), true ) ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'The site timezone is UTC. Statement times in emails and the PDF use the shop timezone, so set your city (for example Warsaw) in Settings > General.', 'primeplaced-withdrawal-button' ); ?>
					<a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>"><?php esc_html_e( 'Open Settings > General', 'primeplaced-withdrawal-button' ); ?></a>
				</p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ppwb_setup' ); ?>
				<input type="hidden" name="action" value="ppwb_setup">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ppwb-return-address">1. <?php esc_html_e( 'Return address', 'primeplaced-withdrawal-button' ); ?></label></th>
						<td>
							<?php $ppwb_store = $this->settings->store_address_parts(); ?>
							<p><input type="text" id="ppwb-return-address" name="return_company" class="regular-text" aria-label="<?php esc_attr_e( 'Company or name', 'primeplaced-withdrawal-button' ); ?>" placeholder="<?php echo esc_attr( $ppwb_store['company'] ); ?>" value="<?php echo esc_attr( $get( 'return_company' ) ); ?>"></p>
							<p><input type="text" name="return_street" class="regular-text" aria-label="<?php esc_attr_e( 'Street and number', 'primeplaced-withdrawal-button' ); ?>" placeholder="<?php echo esc_attr( '' !== $ppwb_store['street'] ? $ppwb_store['street'] : __( 'Street and number', 'primeplaced-withdrawal-button' ) ); ?>" value="<?php echo esc_attr( $get( 'return_street' ) ); ?>"></p>
							<p>
								<input type="text" name="return_postcode" class="small-text" aria-label="<?php esc_attr_e( 'Postcode', 'primeplaced-withdrawal-button' ); ?>" placeholder="<?php echo esc_attr( '' !== $ppwb_store['postcode'] ? $ppwb_store['postcode'] : __( 'Postcode', 'primeplaced-withdrawal-button' ) ); ?>" value="<?php echo esc_attr( $get( 'return_postcode' ) ); ?>">
								<input type="text" name="return_city" aria-label="<?php esc_attr_e( 'City', 'primeplaced-withdrawal-button' ); ?>" placeholder="<?php echo esc_attr( '' !== $ppwb_store['city'] ? $ppwb_store['city'] : __( 'City', 'primeplaced-withdrawal-button' ) ); ?>" value="<?php echo esc_attr( $get( 'return_city' ) ); ?>">
							</p>
							<p class="description"><?php esc_html_e( 'Leave empty to use the store address from WooCommerce settings.', 'primeplaced-withdrawal-button' ); ?></p>
							<?php if ( ! $this->settings->has_return_address() ) : ?>
								<p class="ppwb-warning"><?php esc_html_e( 'The store address in WooCommerce > Settings > General is empty too. Customers would not get a return address.', 'primeplaced-withdrawal-button' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">2. <?php esc_html_e( 'Who pays for return shipping', 'primeplaced-withdrawal-button' ); ?></th>
						<td>
							<label><input type="radio" name="return_shipping_paid_by" value="customer" <?php checked( 'customer', $get( 'return_shipping_paid_by' ) ); ?>> <?php esc_html_e( 'The customer', 'primeplaced-withdrawal-button' ); ?></label><br>
							<label><input type="radio" name="return_shipping_paid_by" value="shop" <?php checked( 'shop', $get( 'return_shipping_paid_by' ) ); ?>> <?php esc_html_e( 'The shop', 'primeplaced-withdrawal-button' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ppwb-days">3. <?php esc_html_e( 'Withdrawal period (days)', 'primeplaced-withdrawal-button' ); ?></label></th>
						<td><input type="number" id="ppwb-days" name="withdrawal_days" min="<?php echo esc_attr( (string) WithdrawalPeriod::MIN_DAYS ); ?>" step="1" value="<?php echo esc_attr( $get( 'withdrawal_days' ) ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row">4. <?php esc_html_e( 'Withdrawal button', 'primeplaced-withdrawal-button' ); ?></th>
						<td>
							<?php
							$places = array(
								'button_in_footer'  => __( 'In the footer of every page', 'primeplaced-withdrawal-button' ),
								'button_in_account' => __( 'Next to orders in My account', 'primeplaced-withdrawal-button' ),
								'button_in_completed_email' => __( 'In the "Completed order" email', 'primeplaced-withdrawal-button' ),
							);
							foreach ( $places as $key => $label ) {
								printf( '<label><input type="checkbox" name="%1$s" value="yes" %2$s> %3$s</label><br>', esc_attr( $key ), checked( $this->settings->flag( $key ), true, false ), esc_html( $label ) );
							}
							?>
							<p class="description"><?php esc_html_e( 'The law requires the button to be easy to find for the whole withdrawal period. Keep the footer button on unless your theme shows it elsewhere.', 'primeplaced-withdrawal-button' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ppwb-format">5. <?php esc_html_e( 'Number format', 'primeplaced-withdrawal-button' ); ?></label></th>
						<td><input type="text" id="ppwb-format" name="number_format" value="<?php echo esc_attr( '' !== $get( 'number_format' ) ? $get( 'number_format' ) : RequestNumber::DEFAULT_FORMAT ); ?>" class="regular-text"></td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" name="finish" value="1" class="button button-primary"><?php esc_html_e( 'Save and finish', 'primeplaced-withdrawal-button' ); ?></button>
					<button type="submit" name="test" value="1" class="button"><?php esc_html_e( 'Save and send a test email', 'primeplaced-withdrawal-button' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Saves the wizard, sends a test email or skips it.
	 *
	 * @return void
	 */
	public function save(): void {
		check_admin_referer( 'ppwb_setup' );
		if ( ! current_user_can( Lifecycle::CAP_MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'primeplaced-withdrawal-button' ), 403 );
		}

		$stored = get_option( Settings::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		if ( isset( $_GET['skip'] ) ) {
			$stored['wizard_done'] = 'skipped';
			update_option( Settings::OPTION, $stored );
			$referer = wp_get_referer();
			wp_safe_redirect( false === $referer ? admin_url() : $referer );
			exit;
		}

		foreach ( array( 'return_company', 'return_street', 'return_postcode', 'return_city' ) as $key ) {
			$stored[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		}
		$stored['return_shipping_paid_by'] = isset( $_POST['return_shipping_paid_by'] ) && 'shop' === $_POST['return_shipping_paid_by'] ? 'shop' : 'customer';
		$stored['withdrawal_days']         = (string) max( WithdrawalPeriod::MIN_DAYS, isset( $_POST['withdrawal_days'] ) ? absint( $_POST['withdrawal_days'] ) : 0 );
		$stored['number_format']           = isset( $_POST['number_format'] ) && '' !== trim( sanitize_text_field( wp_unslash( $_POST['number_format'] ) ) ) ? sanitize_text_field( wp_unslash( $_POST['number_format'] ) ) : RequestNumber::DEFAULT_FORMAT;
		foreach ( array( 'button_in_footer', 'button_in_account', 'button_in_completed_email' ) as $key ) {
			$stored[ $key ] = empty( $_POST[ $key ] ) ? 'no' : 'yes';
		}
		$stored['wizard_done'] = 'yes';
		update_option( Settings::OPTION, $stored );

		if ( ! empty( $_POST['test'] ) ) {
			$sent = wc_mail(
				(string) get_option( 'admin_email' ),
				/* translators: %s: plugin name. */
				sprintf( __( '%s: test email', 'primeplaced-withdrawal-button' ), BrandBanner::name() ),
				esc_html__( 'Email delivery works. Withdrawal confirmations will be sent to customers in the same way.', 'primeplaced-withdrawal-button' )
			);
			wp_safe_redirect( add_query_arg( 'ppwb_test', $sent ? '1' : '0', self::url() ) );
			exit;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . AdminPage::SLUG ) );
		exit;
	}
}
