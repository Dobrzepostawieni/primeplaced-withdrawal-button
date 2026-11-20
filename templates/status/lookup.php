<?php
/**
 * Status lookup. Override: yourtheme/primeplaced-withdrawal-button/status/lookup.php
 *
 * @package PrimePlaced\WithdrawalButton
 * @var array<string, mixed> $view
 */

defined( 'ABSPATH' ) || exit;

$ppwb_error = ! empty( $view['error'] );
?>
<div class="ppwb-form" aria-live="polite">
	<p class="ppwb-form__intro"><?php esc_html_e( 'Enter the request number from your confirmation and the email address you used.', 'primeplaced-withdrawal-button' ); ?></p>
	<?php if ( $ppwb_error ) : ?>
		<div class="ppwb-notice ppwb-notice--error" role="alert" id="ppwb-error"><?php echo esc_html( (string) $view['error'] ); ?></div>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( (string) $view['page_url'] ); ?>" class="ppwb-form__body">
		<?php echo $view['nonce']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() output. ?>
		<input type="hidden" name="ppwb_status_lookup" value="1">
		<p class="ppwb-field">
			<label for="ppwb-number"><?php esc_html_e( 'Request number', 'primeplaced-withdrawal-button' ); ?></label>
			<input type="text" id="ppwb-number" name="ppwb_number" required autocomplete="off" value="<?php echo esc_attr( (string) ( $view['number'] ?? '' ) ); ?>"<?php echo $ppwb_error ? ' aria-describedby="ppwb-error" aria-invalid="true"' : ''; ?>>
		</p>
		<p class="ppwb-field">
			<label for="ppwb-status-email"><?php esc_html_e( 'Email address', 'primeplaced-withdrawal-button' ); ?></label>
			<input type="email" id="ppwb-status-email" name="ppwb_email" required autocomplete="email" value="<?php echo esc_attr( (string) ( $view['email'] ?? '' ) ); ?>">
		</p>
		<p class="ppwb-actions"><button type="submit" class="ppwb-button ppwb-button--primary"><?php esc_html_e( 'Check status', 'primeplaced-withdrawal-button' ); ?></button></p>
	</form>
</div>
