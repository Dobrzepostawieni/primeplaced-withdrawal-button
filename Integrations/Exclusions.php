<?php
/**
 * Product and category withdrawal eligibility.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Integrations;

/**
 * Class Exclusions
 *
 * A product setting wins over its categories; the first excluding category
 * applies when the product has no own setting.
 *
 * @phpstan-type Eligibility array{state: string, reason: string, note: string}
 */
final class Exclusions {

	public const META_STATE  = '_ppwb_eligibility';
	public const META_REASON = '_ppwb_exclusion_reason';
	public const META_NOTE   = '_ppwb_note';

	public const ALLOWED   = 'allowed';
	public const EXCLUDED  = 'excluded';
	public const WITH_NOTE = 'allowed_with_note';

	/**
	 * Art. 38 exception reasons shown to customers.
	 *
	 * @return array<string, string>
	 */
	public static function reasons(): array {
		return array(
			'custom'     => __( 'made to the consumer\'s specification or clearly personalised', 'primeplaced-withdrawal-button' ),
			'perishable' => __( 'liable to deteriorate or expire rapidly', 'primeplaced-withdrawal-button' ),
			'sealed'     => __( 'sealed goods unsealed after delivery, not suitable for return for health or hygiene reasons', 'primeplaced-withdrawal-button' ),
			'mixed'      => __( 'inseparably mixed with other items after delivery', 'primeplaced-withdrawal-button' ),
			'recordings' => __( 'sealed audio, video or software unsealed after delivery', 'primeplaced-withdrawal-button' ),
			'press'      => __( 'newspaper, periodical or magazine', 'primeplaced-withdrawal-button' ),
			'digital'    => __( 'digital content supplied with the consumer\'s consent before the withdrawal period ended', 'primeplaced-withdrawal-button' ),
			'other'      => __( 'other statutory exception', 'primeplaced-withdrawal-button' ),
		);
	}

	/**
	 * Order item meta with the eligibility at the moment of purchase.
	 */
	public const ITEM_META = '_ppwb_eligibility_snapshot';

	/**
	 * Eligibility of a product: variation, then product, then categories.
	 * With several categories an excluding one wins (safer for the shop).
	 *
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID or 0.
	 * @return Eligibility
	 */
	public function for_product( int $product_id, int $variation_id = 0 ): array {
		if ( $variation_id > 0 ) {
			$variation = $this->from_meta( 'post', $variation_id );
			if ( null !== $variation ) {
				return $variation;
			}
		}

		$own = $this->from_meta( 'post', $product_id );
		if ( null !== $own ) {
			return $own;
		}

		$found = null;
		$terms = get_the_terms( $product_id, 'product_cat' );
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$from_term = $this->from_meta( 'term', $term->term_id );
			if ( null === $from_term || self::ALLOWED === $from_term['state'] ) {
				continue;
			}
			if ( self::EXCLUDED === $from_term['state'] ) {
				return $from_term;
			}
			$found ??= $from_term;
		}

		return $found ?? array(
			'state'  => self::ALLOWED,
			'reason' => '',
			'note'   => '',
		);
	}

	/**
	 * Eligibility of an order line: the state saved at purchase, so a later
	 * change of the product does not change rights from earlier orders.
	 * Orders placed before the snapshot existed use the current setting.
	 *
	 * @param \WC_Order_Item_Product $item Order line.
	 * @return Eligibility
	 */
	public function for_item( \WC_Order_Item_Product $item ): array {
		$saved = json_decode( (string) $item->get_meta( self::ITEM_META ), true );
		if ( is_array( $saved ) && in_array( $saved['state'] ?? '', array( self::ALLOWED, self::EXCLUDED, self::WITH_NOTE ), true ) ) {
			return array(
				'state'  => (string) $saved['state'],
				'reason' => (string) ( $saved['reason'] ?? '' ),
				'note'   => (string) ( $saved['note'] ?? '' ),
			);
		}

		return $this->for_product( $item->get_product_id(), $item->get_variation_id() );
	}

	/**
	 * Saves the current eligibility on a new order line (checkout hook).
	 *
	 * @param mixed $item Order line being created.
	 * @return void
	 */
	public function snapshot( mixed $item ): void {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}

		$item->update_meta_data( self::ITEM_META, (string) wp_json_encode( $this->for_product( $item->get_product_id(), $item->get_variation_id() ) ) );
	}

	/**
	 * Reads one object's setting.
	 *
	 * @param string $type "post" or "term".
	 * @param int    $id   Object ID.
	 * @return Eligibility|null
	 */
	private function from_meta( string $type, int $id ): ?array {
		$state = (string) get_metadata( $type, $id, self::META_STATE, true );
		if ( ! in_array( $state, array( self::ALLOWED, self::EXCLUDED, self::WITH_NOTE ), true ) ) {
			return null;
		}

		$reason = (string) get_metadata( $type, $id, self::META_REASON, true );

		return array(
			'state'  => $state,
			'reason' => self::reasons()[ $reason ] ?? '',
			'note'   => (string) get_metadata( $type, $id, self::META_NOTE, true ),
		);
	}
}
