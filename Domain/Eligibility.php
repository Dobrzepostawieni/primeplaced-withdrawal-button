<?php
/**
 * Item eligibility rules.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Domain;

/**
 * Class Eligibility
 */
final class Eligibility {

	/**
	 * Quantity still available for a new request.
	 *
	 * @param int $ordered   Quantity on the order line.
	 * @param int $refunded  Quantity already refunded in WooCommerce (positive).
	 * @param int $requested Quantity in earlier open requests.
	 * @return int
	 */
	public static function available_quantity( int $ordered, int $refunded, int $requested ): int {
		return max( 0, $ordered - $refunded - $requested );
	}
}
