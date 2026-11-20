<?php
/**
 * Contract for a plugin module.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Engine;

/**
 * A module receives its dependencies through the constructor and adds its hooks in register().
 */
interface Module {

	/**
	 * Adds the module's WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void;
}
