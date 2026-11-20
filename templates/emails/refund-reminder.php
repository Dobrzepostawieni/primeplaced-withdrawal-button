<?php
/**
 * Refund deadline reminder (HTML). Override: yourtheme/primeplaced-withdrawal-button/emails/refund-reminder.php
 *
 * @package PrimePlaced\WithdrawalButton
 * @var string                            $email_heading
 * @var list<array<string, string|bool>>  $rows
 * @var WC_Email                          $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
?>
<p><?php esc_html_e( 'The law requires refunding the payment within 14 days of receiving the statement. These requests need attention:', 'primeplaced-withdrawal-button' ); ?></p>
<ul>
	<?php foreach ( $rows as $ppwb_row ) : ?>
		<li>
			<a href="<?php echo esc_url( (string) $ppwb_row['url'] ); ?>"><?php echo esc_html( (string) $ppwb_row['number'] ); ?></a>,
			<?php echo esc_html( (string) $ppwb_row['name'] ); ?>,
			<?php
			/* translators: %s: refund deadline date. */
			printf( esc_html__( 'refund due %s', 'primeplaced-withdrawal-button' ), esc_html( (string) $ppwb_row['due'] ) );
			?>
			<?php if ( ! empty( $ppwb_row['late'] ) ) : ?>
				<strong><?php esc_html_e( '(overdue)', 'primeplaced-withdrawal-button' ); ?></strong>
			<?php endif; ?>
		</li>
	<?php endforeach; ?>
</ul>
<?php
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
