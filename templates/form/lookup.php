<?php
/**
 * Step 1: find the order. Override: yourtheme/primeplaced-withdrawal-button/form/lookup.php
 *
 * @package PrimePlaced\WithdrawalButton
 * @var array<string, mixed> $view
 */

defined( 'ABSPATH' ) || exit;

use PrimePlaced\WithdrawalButton\Frontend\Templates;

$ppwb_error = ! empty( $view['error'] );
?>
<div class="ppwb-form" aria-live="polite">
	<p class="ppwb-form__intro">
		<?php
		printf(
			/* translators: %d: number of days to withdraw. */
			esc_html__( 'You have %d days to withdraw from the contract without giving a reason. Fill in the form and you will get a confirmation right away.', 'primeplaced-withdrawal-button' ),
			(int) $view['days']
		);
		?>
	</p>
	<?php Templates::render( 'form/steps', array( 'current' => 1 ) + $view ); ?>
	<?php if ( ! empty( $view['my_orders'] ) ) : ?>
		<h3 class="ppwb-form__title"><?php esc_html_e( 'Your orders you can withdraw from', 'primeplaced-withdrawal-button' ); ?></h3>
		<ul class="ppwb-my-orders">
			<?php foreach ( (array) $view['my_orders'] as $ppwb_order ) : ?>
				<li>
					<a class="ppwb-button" href="<?php echo esc_url( (string) $ppwb_order['url'] ); ?>">
						<?php
						/* translators: 1: order number, 2: order date. */
						printf( esc_html__( 'Order #%1$s of %2$s', 'primeplaced-withdrawal-button' ), esc_html( (string) $ppwb_order['number'] ), esc_html( (string) $ppwb_order['date'] ) );
						?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<p><?php esc_html_e( 'Order placed without logging in? Enter its number and email below.', 'primeplaced-withdrawal-button' ); ?></p>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( (string) $view['form_url'] ); ?>" class="ppwb-form__body" novalidate>
		<?php echo $view['nonce']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() output. ?>
		<input type="hidden" name="ppwb_action" value="lookup">
		<p class="ppwb-field">
			<label for="ppwb-order"><?php esc_html_e( 'Order number', 'primeplaced-withdrawal-button' ); ?></label>
			<input type="text" id="ppwb-order" name="ppwb_order" required autocomplete="off" inputmode="numeric"
				value="<?php echo esc_attr( (string) ( $view['order_number'] ?? '' ) ); ?>"
				aria-describedby="ppwb-order-hint<?php echo $ppwb_error ? ' ppwb-error' : ''; ?>"<?php echo $ppwb_error ? ' aria-invalid="true"' : ''; ?>>
			<span class="ppwb-field__hint" id="ppwb-order-hint"><?php esc_html_e( 'You will find it in the order confirmation email.', 'primeplaced-withdrawal-button' ); ?></span>
		</p>
		<p class="ppwb-field">
			<label for="ppwb-email"><?php esc_html_e( 'Email address used for the order', 'primeplaced-withdrawal-button' ); ?></label>
			<input type="email" id="ppwb-email" name="ppwb_email" required autocomplete="email"
				value="<?php echo esc_attr( (string) ( $view['email'] ?? '' ) ); ?>"<?php echo $ppwb_error ? ' aria-describedby="ppwb-error" aria-invalid="true"' : ''; ?>>
		</p>
		<p class="ppwb-actions">
			<button type="submit" class="ppwb-button ppwb-button--primary"><?php esc_html_e( 'Next', 'primeplaced-withdrawal-button' ); ?></button>
		</p>
	</form>
</div>
