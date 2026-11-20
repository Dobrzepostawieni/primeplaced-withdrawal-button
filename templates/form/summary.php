<?php
/**
 * Step 3: review and confirm. Override: yourtheme/primeplaced-withdrawal-button/form/summary.php
 *
 * @package PrimePlaced\WithdrawalButton
 * @var array<string, mixed> $view
 */

defined( 'ABSPATH' ) || exit;

use PrimePlaced\WithdrawalButton\Frontend\Templates;

/**
 * Lines prepared by Frontend\WithdrawalForm.
 *
 * @var list<array{id: int, name: string, price: string, qty: int}> $ppwb_chosen
 */
$ppwb_chosen = (array) $view['chosen'];
$ppwb_hidden = static function ( array $view ): void {
	echo $view['nonce']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() output.
	printf( '<input type="hidden" name="ppwb_token" value="%s">', esc_attr( (string) $view['token'] ) );
	printf( '<input type="hidden" name="ppwb_reason" value="%s">', esc_attr( (string) $view['reason'] ) );
	printf( '<input type="hidden" name="ppwb_reason_code" value="%s">', esc_attr( (string) ( $view['reason_code'] ?? '' ) ) );
	if ( ! empty( $view['consumer'] ) ) {
		echo '<input type="hidden" name="ppwb_consumer" value="1">';
	}
	printf( '<input type="hidden" name="ppwb_iban" value="%s">', esc_attr( (string) $view['iban'] ) );
	printf( '<input type="hidden" name="ppwb_via" value="%s">', esc_attr( (string) $view['via'] ) );
	foreach ( (array) $view['selection'] as $ppwb_id => $ppwb_qty ) {
		printf( '<input type="hidden" name="ppwb_qty[%s]" value="%s">', esc_attr( (string) $ppwb_id ), esc_attr( (string) $ppwb_qty ) );
	}
};
?>
<div class="ppwb-form" aria-live="polite">
	<?php Templates::render( 'form/steps', array( 'current' => 3 ) + $view ); ?>
	<h3 class="ppwb-form__title"><?php esc_html_e( 'Check and confirm', 'primeplaced-withdrawal-button' ); ?></h3>
	<?php if ( ! empty( $view['notice'] ) ) : ?>
		<div class="ppwb-notice ppwb-notice--info"><?php echo esc_html( (string) $view['notice'] ); ?></div>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( (string) $view['form_url'] ); ?>" class="ppwb-form__body">
		<?php $ppwb_hidden( $view ); ?>
		<input type="hidden" name="ppwb_submission" value="<?php echo esc_attr( (string) $view['submission'] ); ?>">

		<section class="ppwb-block">
			<h4><?php esc_html_e( 'Your details', 'primeplaced-withdrawal-button' ); ?></h4>
			<p class="ppwb-field">
				<label for="ppwb-full-name"><?php esc_html_e( 'Full name', 'primeplaced-withdrawal-button' ); ?></label>
				<input type="text" id="ppwb-full-name" name="ppwb_full_name" required autocomplete="name" value="<?php echo esc_attr( (string) $view['full_name'] ); ?>">
			</p>
			<p class="ppwb-field">
				<label for="ppwb-contact-email"><?php esc_html_e( 'Email for the confirmation', 'primeplaced-withdrawal-button' ); ?></label>
				<input type="email" id="ppwb-contact-email" name="ppwb_contact_email" required autocomplete="email" value="<?php echo esc_attr( (string) ( $view['contact_email'] ?? $view['email'] ) ); ?>">
				<span class="ppwb-field__hint"><?php esc_html_e( 'We send the confirmation and the PDF here. The order is still identified by the email used when ordering.', 'primeplaced-withdrawal-button' ); ?></span>
			</p>
			<?php if ( '' !== (string) $view['iban'] ) : ?>
				<p><?php esc_html_e( 'Bank account for the refund:', 'primeplaced-withdrawal-button' ); ?> <?php echo esc_html( \PrimePlaced\WithdrawalButton\Domain\Iban::grouped( (string) $view['iban'] ) ); ?></p>
			<?php endif; ?>
		</section>

		<section class="ppwb-block">
			<h4><?php esc_html_e( 'Products', 'primeplaced-withdrawal-button' ); ?></h4>
			<ul class="ppwb-summary-items">
				<?php foreach ( $ppwb_chosen as $ppwb_item ) : ?>
					<li><?php echo esc_html( $ppwb_item['name'] . ' x ' . $ppwb_item['qty'] ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p class="ppwb-total">
				<?php esc_html_e( 'Estimated refund amount:', 'primeplaced-withdrawal-button' ); ?>
				<strong><?php echo wp_kses_post( (string) $view['total'] ); ?></strong>
			</p>
		</section>

		<section class="ppwb-block">
			<h4><?php esc_html_e( 'Statement text', 'primeplaced-withdrawal-button' ); ?></h4>
			<blockquote class="ppwb-statement"><?php echo esc_html( (string) $view['statement'] ); ?></blockquote>
		</section>

		<?php
		// Confirm comes first, so Enter confirms; CSS shows "Back" on the left.
		?>
		<p class="ppwb-actions ppwb-actions--split">
			<button type="submit" name="ppwb_action" value="confirm" class="ppwb-button ppwb-button--primary"><?php esc_html_e( 'Confirm withdrawal', 'primeplaced-withdrawal-button' ); ?></button>
			<button type="submit" name="ppwb_action" value="products" class="ppwb-button" formnovalidate><?php esc_html_e( 'Back', 'primeplaced-withdrawal-button' ); ?></button>
		</p>
	</form>
</div>
