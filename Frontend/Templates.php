<?php
/**
 * Template loader with theme overrides, like WooCommerce templates.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Frontend;

/**
 * Class Templates
 *
 * A theme overrides a template by copying it to
 * yourtheme/primeplaced-withdrawal-button/{name}.php.
 */
final class Templates {

	/**
	 * Prints a template with the given variables.
	 *
	 * @param string               $name Template name without extension, e.g. "form/lookup".
	 * @param array<string, mixed> $vars Variables available as $view in the template.
	 * @return void
	 */
	public static function render( string $name, array $vars ): void {
		$name = preg_replace( '/[^a-z0-9\/_-]/', '', strtolower( $name ) ) ?? '';
		$file = locate_template( 'primeplaced-withdrawal-button/' . $name . '.php' );

		if ( '' === $file ) {
			$file = PPWB_PLUGIN_ROOT . 'templates/' . $name . '.php';
		}

		if ( ! is_readable( $file ) ) {
			return;
		}

		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $view is read by the included template.
		( static function ( string $ppwb_template, array $view ): void {
			include $ppwb_template;
		} )( $file, $vars );
	}
}
