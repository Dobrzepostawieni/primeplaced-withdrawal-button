<?php
/**
 * The diagnostic report. Settings export/import is Pro.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Backend;

use PrimePlaced\WithdrawalButton\Data\Schema;
use PrimePlaced\WithdrawalButton\Data\Settings;
use PrimePlaced\WithdrawalButton\Emails\Mailer;
use PrimePlaced\WithdrawalButton\Frontend\StatusPage;
use PrimePlaced\WithdrawalButton\Pdf\PrivateStorage;

/**
 * Class Tools
 *
 * Shown as the "Tools" section of WooCommerce > Settings > Returns.
 */
final class Tools {

	/**
	 * Creates the tools.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * URL of the tools section.
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=ppwb&section=tools' );
	}

	/**
	 * Prints the tools section.
	 *
	 * @return void
	 */
	public function render(): void {
		$report = $this->report();
		$text   = '';
		foreach ( $report as $row ) {
			$text .= $row['label'] . ': ' . $row['value'] . ( $row['ok'] ? '' : '  [!]' ) . "\n";
		}

		/**
		 * Prints extra sections at the top of the Tools screen, inside
		 * WooCommerce's settings form.
		 */
		do_action( 'ppwb_tools_sections' );
		?>
		<h2><?php esc_html_e( 'Diagnostics', 'primeplaced-withdrawal-button' ); ?></h2>
		<p><?php esc_html_e( 'Copy this report when you contact support. It contains no customer data.', 'primeplaced-withdrawal-button' ); ?></p>
		<table class="widefat striped ppwb-diagnostics">
			<tbody>
				<?php foreach ( $report as $row ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $row['label'] ); ?></th>
						<td>
							<span class="dashicons <?php echo esc_attr( $row['ok'] ? 'dashicons-yes' : 'dashicons-warning' ); ?>" aria-hidden="true"></span>
							<?php echo esc_html( $row['value'] ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<label for="ppwb-diagnostics-text" class="screen-reader-text"><?php esc_html_e( 'Diagnostics report', 'primeplaced-withdrawal-button' ); ?></label>
			<textarea id="ppwb-diagnostics-text" class="large-text code" rows="8" readonly><?php echo esc_textarea( $text ); ?></textarea>
		</p>
		<?php
	}

	/**
	 * Diagnostic checks.
	 *
	 * @return list<array{label: string, value: string, ok: bool}>
	 */
	public function report(): array {
		global $wpdb;

		$form_page   = (int) $this->settings->form_page_id();
		$status_page = (int) get_option( StatusPage::PAGE_OPTION, 0 );
		$private     = PrivateStorage::base_dir();
		$hpos        = 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' );
		$failed      = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE type = %s AND created_at_gmt > %s', $wpdb->prefix . 'ppwb_events', 'email_failed', gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostics.
		$reminder    = function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( Mailer::REMINDER_HOOK, array(), 'ppwb' );
		$fs_ready    = function_exists( 'ppwb_fs' ) && null !== ppwb_fs();

		$rows = array(
			self::row( __( 'Plugin version', 'primeplaced-withdrawal-button' ), PPWB_VERSION, true ),
			self::row( 'WordPress', get_bloginfo( 'version' ), version_compare( get_bloginfo( 'version' ), '6.4', '>=' ) ),
			self::row( 'WooCommerce', defined( 'WC_VERSION' ) ? WC_VERSION : '-', defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '8.5', '>=' ) ),
			self::row( 'PHP', PHP_VERSION, version_compare( PHP_VERSION, '8.1', '>=' ) ),
			self::row( __( 'Order storage', 'primeplaced-withdrawal-button' ), $hpos ? 'HPOS' : __( 'Posts (legacy)', 'primeplaced-withdrawal-button' ), true ),
			self::row( __( 'Database schema', 'primeplaced-withdrawal-button' ), (string) get_option( Schema::VERSION_OPTION, '-' ), Schema::VERSION === get_option( Schema::VERSION_OPTION ) ),
			self::row( __( 'Withdrawal form page', 'primeplaced-withdrawal-button' ), self::page_state( $form_page, '[ppwb_form]' ), self::page_ok( $form_page, '[ppwb_form]' ) ),
			self::row( __( 'Return status page', 'primeplaced-withdrawal-button' ), self::page_state( $status_page, '[ppwb_status]' ), self::page_ok( $status_page, '[ppwb_status]' ) ),
			self::row( __( 'Footer button', 'primeplaced-withdrawal-button' ), $this->settings->flag( 'button_in_footer' ) ? __( 'On', 'primeplaced-withdrawal-button' ) : __( 'Off', 'primeplaced-withdrawal-button' ), $this->settings->flag( 'button_in_footer' ) ),
			self::row( __( 'Private file storage', 'primeplaced-withdrawal-button' ), is_dir( $private ) ? ( wp_is_writable( $private ) ? __( 'Writable', 'primeplaced-withdrawal-button' ) : __( 'Not writable', 'primeplaced-withdrawal-button' ) ) : __( 'Created on first use', 'primeplaced-withdrawal-button' ), ! is_dir( $private ) || wp_is_writable( $private ) ),
			/* translators: %d: number of failed emails. */
			self::row( __( 'Failed emails (30 days)', 'primeplaced-withdrawal-button' ), (string) $failed, 0 === $failed ),
			self::row( __( 'Daily refund reminder', 'primeplaced-withdrawal-button' ), $reminder ? __( 'Scheduled', 'primeplaced-withdrawal-button' ) : __( 'Not scheduled', 'primeplaced-withdrawal-button' ), $reminder ),
			self::row( __( 'Shop timezone', 'primeplaced-withdrawal-button' ), wp_timezone_string(), true ),
			self::row( __( 'Licensing (Freemius)', 'primeplaced-withdrawal-button' ), $fs_ready ? __( 'Configured', 'primeplaced-withdrawal-button' ) : __( 'Not configured (Free only)', 'primeplaced-withdrawal-button' ), true ),
			self::row( __( 'Active theme', 'primeplaced-withdrawal-button' ), (string) wp_get_theme()->get( 'Name' ), true ),
		);

		/**
		 * Filters the diagnostics rows. Rows must not contain customer data.
		 *
		 * @param list<array{label: string, value: string, ok: bool}> $rows Rows.
		 */
		$rows = apply_filters( 'ppwb_diagnostics_rows', $rows );

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * One report row.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param bool   $ok    Whether the value is fine.
	 * @return array{label: string, value: string, ok: bool}
	 */
	public static function row( string $label, string $value, bool $ok ): array {
		return array(
			'label' => $label,
			'value' => $value,
			'ok'    => $ok,
		);
	}

	/**
	 * Human state of a plugin page.
	 *
	 * @param int    $page_id   Page ID.
	 * @param string $shortcode Expected shortcode.
	 * @return string
	 */
	private static function page_state( int $page_id, string $shortcode ): string {
		if ( ! self::page_ok( $page_id, $shortcode ) ) {
			return __( 'Missing or without its shortcode. Deactivate and activate the plugin to recreate it.', 'primeplaced-withdrawal-button' );
		}

		return (string) get_permalink( $page_id );
	}

	/**
	 * Whether a page is published and contains its shortcode.
	 *
	 * @param int    $page_id   Page ID.
	 * @param string $shortcode Expected shortcode.
	 * @return bool
	 */
	public static function page_ok( int $page_id, string $shortcode ): bool {
		$post = $page_id > 0 ? get_post( $page_id ) : null;

		return $post instanceof \WP_Post && 'publish' === $post->post_status && str_contains( $post->post_content, $shortcode );
	}
}
