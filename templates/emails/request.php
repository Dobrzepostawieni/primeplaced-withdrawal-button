<?php
/**
 * Withdrawal request email (HTML). Override: yourtheme/primeplaced-withdrawal-button/emails/request.php
 *
 * @package PrimePlaced\WithdrawalButton
 * @var string                               $email_heading
 * @var string                               $intro
 * @var array<string, string|int|null>       $request
 * @var array<int, array<string, mixed>>     $items
 * @var WC_Order|false                       $order
 * @var array<string, string>                $extra
 * @var string                               $additional_content
 * @var WC_Email                             $email
 */

defined( 'ABSPATH' ) || exit;

$ppwb_submitted = (int) strtotime( $request['submitted_at_gmt'] . ' UTC' );

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
?>
<p><?php echo wp_kses_post( wpautop( $intro ) ); ?></p>

<?php if ( ! empty( $extra['message'] ) ) : ?>
	<blockquote style="margin:0 0 16px;padding:8px 12px;border-left:4px solid #1d4ed8;"><?php echo wp_kses_post( wpautop( esc_html( $extra['message'] ) ) ); ?></blockquote>
<?php endif; ?>

<h2><?php esc_html_e( 'Withdrawal request', 'primeplaced-withdrawal-button' ); ?></h2>
<ul>
	<li><?php esc_html_e( 'Request number', 'primeplaced-withdrawal-button' ); ?>: <strong><?php echo esc_html( (string) $request['number'] ); ?></strong></li>
	<li><?php esc_html_e( 'Date and time of the statement', 'primeplaced-withdrawal-button' ); ?>: <?php echo esc_html( \PrimePlaced\WithdrawalButton\Integrations\Dates::datetime( $ppwb_submitted ) ); ?></li>
	<?php if ( $order instanceof WC_Order ) : ?>
		<li>
			<?php esc_html_e( 'Order', 'primeplaced-withdrawal-button' ); ?>:
			<?php
			$ppwb_created    = $order->get_date_created();
			$ppwb_order_text = '#' . $order->get_order_number() . ( null === $ppwb_created ? '' : ', ' . \PrimePlaced\WithdrawalButton\Integrations\Dates::date( $ppwb_created ) );
			if ( ! empty( $extra['order_url'] ) ) {
				echo '<a href="' . esc_url( $extra['order_url'] ) . '">' . esc_html( $ppwb_order_text ) . '</a>';
			} else {
				echo esc_html( $ppwb_order_text );
			}
			?>
		</li>
	<?php endif; ?>
	<li>
		<?php esc_html_e( 'Products', 'primeplaced-withdrawal-button' ); ?>:
		<?php echo esc_html( implode( ', ', array_map( static fn ( array $item ): string => $item['name'] . ' x ' . $item['qty'], $items ) ) ); ?>
	</li>
	<?php if ( ! empty( $extra['estimated'] ) ) : ?>
		<li><?php esc_html_e( 'Estimated refund', 'primeplaced-withdrawal-button' ); ?>: <?php echo esc_html( $extra['estimated'] ); ?></li>
		<li><?php esc_html_e( 'Refund method', 'primeplaced-withdrawal-button' ); ?>: <?php echo esc_html( (string) ( $extra['method'] ?? '' ) ); ?></li>
	<?php endif; ?>
	<?php if ( ! empty( $extra['amount'] ) ) : ?>
		<li><?php esc_html_e( 'Refunded amount', 'primeplaced-withdrawal-button' ); ?>: <?php echo wp_kses_post( $extra['amount'] ); ?></li>
	<?php endif; ?>
</ul>

<?php if ( ! empty( $extra['show_statement'] ) ) : ?>
	<h2><?php esc_html_e( 'Statement text', 'primeplaced-withdrawal-button' ); ?></h2>
	<p><?php echo esc_html( (string) $request['statement_text'] ); ?></p>
<?php endif; ?>

<?php if ( ! empty( $extra['status_url'] ) ) : ?>
	<p><a href="<?php echo esc_url( $extra['status_url'] ); ?>"><?php esc_html_e( 'Check the request status', 'primeplaced-withdrawal-button' ); ?></a></p>
<?php endif; ?>

<?php if ( ! empty( $extra['admin_url'] ) ) : ?>
	<p><a href="<?php echo esc_url( $extra['admin_url'] ); ?>"><?php esc_html_e( 'Open the request', 'primeplaced-withdrawal-button' ); ?></a></p>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
