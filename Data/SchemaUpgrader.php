<?php
/**
 * Keeps the database schema current in every request context.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Data;

use PrimePlaced\WithdrawalButton\Engine\Module;

/**
 * Class SchemaUpgrader
 *
 * Runs in the core group, so front-end and REST requests never work on an old
 * schema after a plugin update.
 */
final class SchemaUpgrader implements Module {

	/**
	 * Upgrades the schema now; boot already runs on plugins_loaded.
	 *
	 * @return void
	 */
	public function register(): void {
		self::upgrade();
	}

	/**
	 * Installs or upgrades the tables. Used on every request and on activation,
	 * so replacing the plugin files and reactivating also upgrades.
	 *
	 * @return void
	 */
	public static function upgrade(): void {
		if ( Schema::VERSION !== get_option( Schema::VERSION_OPTION, '' ) ) {
			Schema::install();
		}
	}
}
