<?php
/**
 * Raised when a statement cannot be stored for an order.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Integrations;

use DateTimeImmutable;
use RuntimeException;

/**
 * Class WithdrawalBlocked
 */
final class WithdrawalBlocked extends RuntimeException {

	/**
	 * Creates the exception.
	 *
	 * @param array{code: string, deadline?: DateTimeImmutable, status?: string} $reason Blocking reason from WithdrawalService.
	 */
	public function __construct( public readonly array $reason ) {
		parent::__construct( esc_html( 'Withdrawal blocked: ' . $reason['code'] ) );
	}
}
