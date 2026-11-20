<?php
/**
 * Statutory exceptions on the shop front and at checkout.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Frontend;

use PrimePlaced\WithdrawalButton\Data\Settings;
use PrimePlaced\WithdrawalButton\Engine\Module;
use PrimePlaced\WithdrawalButton\Integrations\Exclusions;
use WC_Product;

/**
 * Class ProductExclusions
 *
 * The consumer must learn before the purchase that the right of withdrawal
 * does not apply (Art. 38 with Art. 12 ust. 1 pkt 11). The state is also
 * saved on every new order line, so later product changes do not change the
 * rights from orders already placed.
 */
final class ProductExclusions implements Module {

	/**
	 * Creates the module.
	 *
	 * @param Settings   $settings   Plugin settings.
	 * @param Exclusions $exclusions Product exclusions.
	 */
	public function __construct(
		private Settings $settings,
		private Exclusions $exclusions
	) {
	}

	/**
	 * Adds the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Classic and block checkout both create lines through WC_Checkout::create_order_line_items().
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this->exclusions, 'snapshot' ), 10, 1 );

		if ( $this->settings->flag( 'exclusion_notice' ) ) {
			add_action( 'woocommerce_single_product_summary', array( $this, 'product_notice' ), 25 );
			add_filter( 'woocommerce_available_variation', array( $this, 'variation_notice' ), 10, 3 );
		}
	}

	/**
	 * Notice on the product page of an excluded product.
	 *
	 * @return void
	 */
	public function product_notice(): void {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$text = $this->notice_text( $product->get_id(), 0 );
		if ( '' !== $text ) {
			echo '<p class="ppwb-product-notice">' . esc_html( $text ) . '</p>';
		}
	}

	/**
	 * Adds the notice to a variation that has its own exception.
	 *
	 * @param mixed $data      Variation data for the add-to-cart form.
	 * @param mixed $product   Parent product.
	 * @param mixed $variation Variation.
	 * @return mixed
	 */
	public function variation_notice( mixed $data, mixed $product, mixed $variation ): mixed {
		if ( ! is_array( $data ) || ! $product instanceof WC_Product || ! $variation instanceof WC_Product ) {
			return $data;
		}

		// The parent notice is already on the page; add only what the variation changes.
		$text = $this->notice_text( $product->get_id(), $variation->get_id() );
		if ( '' !== $text && $text !== $this->notice_text( $product->get_id(), 0 ) ) {
			$data['variation_description'] = (string) ( $data['variation_description'] ?? '' ) . '<p class="ppwb-product-notice">' . esc_html( $text ) . '</p>';
		}

		return $data;
	}

	/**
	 * Text for an excluded product, '' when withdrawal applies.
	 *
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID or 0.
	 * @return string
	 */
	private function notice_text( int $product_id, int $variation_id ): string {
		$eligibility = $this->exclusions->for_product( $product_id, $variation_id );
		if ( Exclusions::EXCLUDED !== $eligibility['state'] ) {
			return '';
		}

		$reason = '' !== $eligibility['note'] ? $eligibility['note'] : $eligibility['reason'];

		return '' === $reason
			? __( 'The right of withdrawal does not apply to this product.', 'primeplaced-withdrawal-button' )
			/* translators: %s: statutory exception or the shop's note. */
			: sprintf( __( 'The right of withdrawal does not apply to this product: %s.', 'primeplaced-withdrawal-button' ), rtrim( $reason, '.' ) );
	}
}
