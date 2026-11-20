<?php
/**
 * Withdrawal request email (plain text). Override: yourtheme/primeplaced-withdrawal-button/emails/plain/request.php
 *
 * @package PrimePlaced\WithdrawalButton
 * @var string                               $email_heading
 * @var string                               $intro
 * @var array<string, string|int|null>       $request
 * @var array<int, array<string, mixed>>     $items
 * @var WC_Order|false                       $order
 * @var array<string, string>                $extra
 * @var string                               $additional_content
 */

defined( 'ABSPATH' ) || exit;

$ppwb_submitted = (int) strtotime( $request['submitted_at_gmt'] . ' UTC' );

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";
echo esc_html( wp_strip_all_tags( $intro ) ) . "\n\n";

if ( ! empty( $extra['message'] ) ) {
	echo esc_html( $extra['message'] ) . "\n\n";
}

echo esc_html__( 'Request number', 'primeplaced-withdrawal-button' ) . ': ' . esc_html( (string) $request['number'] ) . "\n";
echo esc_html__( 'Date and time of the statement', 'primeplaced-withdrawal-button' ) . ': ' . esc_html( \PrimePlaced\WithdrawalButton\Integrations\Dates::datetime( $ppwb_submitted ) ) . "\n";
if ( $order instanceof WC_Order ) {
	echo esc_html__( 'Order', 'primeplaced-withdrawal-button' ) . ': #' . esc_html( (string) $order->get_order_number() ) . "\n";
}
echo esc_html__( 'Products', 'primeplaced-withdrawal-button' ) . ': ' . esc_html( implode( ', ', array_map( static fn ( array $item ): string => $item['name'] . ' x ' . $item['qty'], $items ) ) ) . "\n";
if ( ! empty( $extra['order_url'] ) ) {
	echo esc_url_raw( $extra['order_url'] ) . "\n";
}
if ( ! empty( $extra['estimated'] ) ) {
	echo esc_html__( 'Estimated refund', 'primeplaced-withdrawal-button' ) . ': ' . esc_html( $extra['estimated'] ) . "\n";
	echo esc_html__( 'Refund method', 'primeplaced-withdrawal-button' ) . ': ' . esc_html( (string) ( $extra['method'] ?? '' ) ) . "\n";
}
if ( ! empty( $extra['amount'] ) ) {
	echo esc_html__( 'Refunded amount', 'primeplaced-withdrawal-button' ) . ': ' . esc_html( wp_strip_all_tags( $extra['amount'] ) ) . "\n";
}

if ( ! empty( $extra['show_statement'] ) ) {
	echo "\n" . esc_html__( 'Statement text', 'primeplaced-withdrawal-button' ) . ":\n" . esc_html( (string) $request['statement_text'] ) . "\n";
}
if ( ! empty( $extra['status_url'] ) ) {
	echo "\n" . esc_html__( 'Check the request status', 'primeplaced-withdrawal-button' ) . ': ' . esc_url_raw( $extra['status_url'] ) . "\n";
}
if ( ! empty( $extra['admin_url'] ) ) {
	echo "\n" . esc_html__( 'Open the request', 'primeplaced-withdrawal-button' ) . ': ' . esc_url_raw( $extra['admin_url'] ) . "\n";
}
if ( $additional_content ) {
	echo "\n" . esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n";
}
