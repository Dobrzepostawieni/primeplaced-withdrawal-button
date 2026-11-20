<?php
/**
 * Database schema.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Data;

/**
 * Class Schema
 */
final class Schema {

	/**
	 * Bump on every schema change; SchemaUpgrader reruns dbDelta when it differs.
	 */
	public const VERSION = '1';

	public const VERSION_OPTION = 'ppwb_db_version';

	/**
	 * Table names without the WordPress prefix.
	 *
	 * @return list<string>
	 */
	public static function tables(): array {
		return array( 'ppwb_requests', 'ppwb_request_items', 'ppwb_events', 'ppwb_counters' );
	}

	/**
	 * Creates or updates all tables and stores the schema version.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix;

		dbDelta(
			"CREATE TABLE {$prefix}ppwb_requests (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				number VARCHAR(40) NOT NULL,
				order_id BIGINT UNSIGNED NOT NULL,
				customer_id BIGINT UNSIGNED NULL,
				email VARCHAR(190) NOT NULL,
				full_name VARCHAR(190) NOT NULL,
				status VARCHAR(32) NOT NULL,
				flags VARCHAR(255) NULL,
				reason_code VARCHAR(64) NULL,
				reason_text TEXT NULL,
				statement_text LONGTEXT NOT NULL,
				statement_hash CHAR(64) NOT NULL,
				submitted_at_gmt DATETIME NOT NULL,
				refund_due_gmt DATETIME NOT NULL,
				bank_account VARCHAR(255) NULL,
				currency CHAR(3) NOT NULL,
				estimated_total DECIMAL(19,4) NOT NULL,
				refunded_total DECIMAL(19,4) NOT NULL DEFAULT 0,
				wc_refund_ids TEXT NULL,
				tracking_number VARCHAR(64) NULL,
				tracking_carrier VARCHAR(64) NULL,
				ip_hash CHAR(64) NULL,
				user_agent VARCHAR(255) NULL,
				locale VARCHAR(20) NULL,
				pdf_path VARCHAR(255) NULL,
				created_via VARCHAR(20) NOT NULL,
				created_at_gmt DATETIME NOT NULL,
				updated_at_gmt DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY number (number),
				KEY order_id (order_id),
				KEY customer_id (customer_id),
				KEY email (email),
				KEY status (status),
				KEY submitted_at_gmt (submitted_at_gmt),
				KEY refund_due_gmt (refund_due_gmt)
			) {$charset};
			CREATE TABLE {$prefix}ppwb_request_items (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				request_id BIGINT UNSIGNED NOT NULL,
				order_item_id BIGINT UNSIGNED NOT NULL,
				product_id BIGINT UNSIGNED NOT NULL,
				variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				name VARCHAR(255) NOT NULL,
				qty INT UNSIGNED NOT NULL,
				unit_total DECIMAL(19,4) NOT NULL,
				tax DECIMAL(19,4) NOT NULL DEFAULT 0,
				excluded_reason VARCHAR(64) NULL,
				PRIMARY KEY  (id),
				KEY request_id (request_id)
			) {$charset};
			CREATE TABLE {$prefix}ppwb_events (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				request_id BIGINT UNSIGNED NOT NULL,
				type VARCHAR(40) NOT NULL,
				actor_type VARCHAR(10) NOT NULL,
				actor_id BIGINT UNSIGNED NULL,
				payload LONGTEXT NULL,
				created_at_gmt DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY request_id (request_id)
			) {$charset};
			CREATE TABLE {$prefix}ppwb_counters (
				scope VARCHAR(20) NOT NULL,
				value BIGINT UNSIGNED NOT NULL,
				PRIMARY KEY  (scope)
			) {$charset};"
		);

		update_option( self::VERSION_OPTION, self::VERSION, true );
	}

	/**
	 * Drops all plugin tables. Used only by uninstall when the shop opted in.
	 *
	 * @return void
	 */
	public static function drop(): void {
		global $wpdb;

		foreach ( self::tables() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping our own tables on opted-in uninstall.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) );
		}

		delete_option( self::VERSION_OPTION );
	}
}
