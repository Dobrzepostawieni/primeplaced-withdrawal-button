<?php
/**
 * Request context: which group of modules a request needs.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Engine;

use PrimePlaced\WithdrawalButton\Vendor\Inpsyde\WpContext;

/**
 * Class Context
 */
final class Context {

	public const CORE     = 'core';
	public const ADMIN    = 'admin';
	public const FRONTEND = 'frontend';
	public const REST     = 'rest';

	/**
	 * Detected WordPress context.
	 *
	 * @var WpContext
	 */
	private WpContext $wp_context;

	/**
	 * Detects the current context.
	 */
	public function __construct() {
		$this->wp_context = WpContext::determine();
	}

	/**
	 * Lists the module groups for this request, always starting with core.
	 *
	 * @return list<string>
	 */
	public function groups(): array {
		$groups = array( self::CORE );

		if ( $this->wp_context->isRest() ) {
			$groups[] = self::REST;
		} elseif ( $this->wp_context->isBackoffice() || $this->wp_context->isAjax() ) {
			$groups[] = self::ADMIN;
		} elseif ( $this->wp_context->isFrontoffice() ) {
			$groups[] = self::FRONTEND;
		}

		return $groups;
	}
}
