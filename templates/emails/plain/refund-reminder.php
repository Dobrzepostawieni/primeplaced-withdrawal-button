<?php
/**
 * Refund deadline reminder (plain text). Override: yourtheme/primeplaced-withdrawal-button/emails/plain/refund-reminder.php
 *
 * @package PrimePlaced\WithdrawalButton
 * @var string                            $email_heading
 * @var list<array<string, string|bool>>  $rows
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";
echo esc_html__( 'The law requires refunding the payment within 14 days of receiving the statement. These requests need attention:', 'primeplaced-withdrawal-button' ) . "\n\n";
foreach ( $rows as $ppwb_row ) {
	/* translators: %s: refund deadline date. */
	echo '- ' . esc_html( (string) $ppwb_row['number'] . ', ' . (string) $ppwb_row['name'] . ', ' . sprintf( __( 'refund due %s', 'primeplaced-withdrawal-button' ), (string) $ppwb_row['due'] ) ) . ( empty( $ppwb_row['late'] ) ? '' : ' ' . esc_html__( '(overdue)', 'primeplaced-withdrawal-button' ) ) . "\n  " . esc_url_raw( (string) $ppwb_row['url'] ) . "\n";
}
