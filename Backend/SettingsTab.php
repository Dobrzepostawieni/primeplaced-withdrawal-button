<?php
/**
 * Adds the Returns tab to WooCommerce > Settings.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Backend;

use PrimePlaced\WithdrawalButton\Backend\Settings\ReturnsSettingsPage;
use PrimePlaced\WithdrawalButton\Engine\Module;

/**
 * Class SettingsTab
 */
final class SettingsTab implements Module {

	/**
	 * Adds the filter and the banner stylesheet.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_get_settings_pages', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Registers the settings page; WC_Settings_Page exists at this point.
	 *
	 * @param array<int, object> $pages WooCommerce settings pages.
	 * @return array<int, object>
	 */
	public function add_page( array $pages ): array {
		// Shop managers handle requests but do not see the settings.
		if ( current_user_can( Lifecycle::CAP_MANAGE_SETTINGS ) ) {
			$pages[] = new ReturnsSettingsPage();
		}

		return $pages;
	}

	/**
	 * Loads the banner stylesheet on the Returns tab.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab check.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( 'woocommerce_page_wc-settings' === $hook_suffix && ReturnsSettingsPage::TAB === $tab ) {
			BrandBanner::enqueue_style();
		}
	}
}
