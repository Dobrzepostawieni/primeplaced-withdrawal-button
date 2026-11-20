<?php
/**
 * [ppwb_button], [ppwb_info] and the "Withdrawal button" block.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Blocks;

use PrimePlaced\WithdrawalButton\Data\Settings;
use PrimePlaced\WithdrawalButton\Engine\Module;

/**
 * Class Shortcodes
 *
 * The block and the shortcode share one renderer. [ppwb_form] and
 * [ppwb_status] are registered by their front-end modules.
 */
final class Shortcodes implements Module {

	/**
	 * Creates the module.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * Adds the shortcodes and the block.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'ppwb_button', array( $this, 'button' ) );
		add_shortcode( 'ppwb_info', array( $this, 'info' ) );
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Registers the dynamic block with its editor script.
	 *
	 * @return void
	 */
	public function register_block(): void {
		wp_register_script(
			'ppwb-button-block',
			plugins_url( 'assets/blocks/button.js', PPWB_PLUGIN_ABSOLUTE ),
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-server-side-render' ),
			PPWB_VERSION,
			true
		);

		register_block_type(
			PPWB_PLUGIN_ROOT . 'Blocks',
			array(
				'editor_script'   => 'ppwb-button-block',
				'render_callback' => fn (): string => $this->button(),
			)
		);
	}

	/**
	 * Link to the form, styled by the theme as a button.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function button( array|string $atts = array() ): string {
		$atts = shortcode_atts( array( 'label' => ( new \PrimePlaced\WithdrawalButton\Data\Settings() )->button_label() ), is_array( $atts ) ? $atts : array(), 'ppwb_button' );
		$url  = get_permalink( $this->settings->form_page_id() );
		if ( ! is_string( $url ) ) {
			return '';
		}

		return sprintf(
			'<div class="wp-block-button ppwb-button-block"><a class="wp-block-button__link wp-element-button" href="%1$s">%2$s</a></div>',
			esc_url( $url ),
			esc_html( (string) $atts['label'] )
		);
	}

	/**
	 * Short information about the right of withdrawal, for the terms page.
	 *
	 * @return string
	 */
	public function info(): string {
		return sprintf(
			'<div class="ppwb-info"><p>%1$s</p>%2$s</div>',
			esc_html(
				sprintf(
					/* translators: %d: withdrawal period in days. */
					__( 'You can withdraw from the contract within %d days of delivery without giving a reason. Use the form below; you will get a confirmation right away.', 'primeplaced-withdrawal-button' ),
					$this->settings->withdrawal_period()->days()
				)
			),
			$this->button()
		);
	}
}
