<?php
/**
 * PrimePlaced brand banner shown above the plugin's admin screens.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Backend;

/**
 * Class BrandBanner
 *
 * The only PrimePlaced element in the admin; everything below it follows
 * WooCommerce's own screens.
 */
final class BrandBanner {

	public const STYLE_HANDLE = 'ppwb-admin';

	/**
	 * Enqueues the admin stylesheet (banner and list tweaks).
	 *
	 * @return void
	 */
	public static function enqueue_style(): void {
		wp_enqueue_style(
			self::STYLE_HANDLE,
			plugins_url( 'assets/admin/admin.css', PPWB_PLUGIN_ABSOLUTE ),
			array(),
			PPWB_VERSION
		);
	}

	/**
	 * Plugin name in admin texts.
	 *
	 * @return string
	 */
	public static function name(): string {
		/**
		 * Filters the plugin name shown in admin notices and the test email (white label).
		 *
		 * @param string $name Plugin name.
		 */
		$name = apply_filters( 'ppwb_brand_name', 'PrimePlaced - Withdrawal Button' );

		return is_string( $name ) && '' !== trim( $name ) ? $name : 'PrimePlaced - Withdrawal Button';
	}

	/**
	 * Prints the banner.
	 *
	 * @return void
	 */
	public static function render(): void {
		/**
		 * Filters whether the PrimePlaced banner is shown above the plugin screens (white label).
		 *
		 * @param bool $show Whether to show the banner.
		 */
		if ( ! apply_filters( 'ppwb_show_brand_banner', true ) ) {
			return;
		}

		/**
		 * Filters the plan name on the banner.
		 *
		 * @param string $plan Plan name.
		 */
		$plan = (string) apply_filters( 'ppwb_plan_label', __( 'Free', 'primeplaced-withdrawal-button' ) );
		?>
		<div class="ppwb-banner">
			<img class="ppwb-banner__logo" src="<?php echo esc_url( plugins_url( 'assets/images/ppwb-logo.svg', PPWB_PLUGIN_ABSOLUTE ) ); ?>" alt="" width="40" height="40">
			<div class="ppwb-banner__text">
				<p class="ppwb-banner__title">PrimePlaced - Withdrawal Button</p>
				<p class="ppwb-banner__subtitle"><?php esc_html_e( 'Withdrawals and returns, compliant with EU law', 'primeplaced-withdrawal-button' ); ?></p>
			</div>
			<span class="ppwb-banner__plan"><?php echo esc_html( $plan ); ?></span>
		</div>
		<?php
	}
}
