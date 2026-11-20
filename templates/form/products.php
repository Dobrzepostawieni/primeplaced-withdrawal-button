<?php
/**
 * Step 2: choose products. Override: yourtheme/primeplaced-withdrawal-button/form/products.php
 *
 * @package PrimePlaced\WithdrawalButton
 * @var array<string, mixed> $view
 */

defined( 'ABSPATH' ) || exit;

use PrimePlaced\WithdrawalButton\Frontend\Templates;

/**
 * Lines prepared by Frontend\WithdrawalForm.
 *
 * @var list<array{id: int, name: string, ordered: int, available: int, price: string, excluded: bool, exclusion_reason: string, note: string, variant: string, thumb: string}> $ppwb_lines
 */
$ppwb_lines     = (array) $view['lines'];
$ppwb_selection = (array) $view['selection'];
?>
<div class="ppwb-form" aria-live="polite">
	<?php Templates::render( 'form/steps', array( 'current' => 2 ) + $view ); ?>
	<form method="post" action="<?php echo esc_url( (string) $view['form_url'] ); ?>" class="ppwb-form__body">
		<?php echo $view['nonce']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() output. ?>
		<input type="hidden" name="ppwb_action" value="summary">
		<input type="hidden" name="ppwb_token" value="<?php echo esc_attr( (string) $view['token'] ); ?>">
		<input type="hidden" name="ppwb_via" value="<?php echo esc_attr( (string) $view['via'] ); ?>">
		<h3 class="ppwb-form__title"><?php echo esc_html( empty( $view['notice'] ) ? __( 'Which products do you want to return?', 'primeplaced-withdrawal-button' ) : __( 'Which products do you want to cancel?', 'primeplaced-withdrawal-button' ) ); ?></h3>
		<?php if ( ! empty( $view['notice'] ) ) : ?>
			<div class="ppwb-notice ppwb-notice--info"><?php echo esc_html( (string) $view['notice'] ); ?></div>
		<?php endif; ?>
		<p class="ppwb-form__meta">
			<?php
			/* translators: %s: order number. */
			printf( esc_html__( 'Order #%s', 'primeplaced-withdrawal-button' ), esc_html( (string) $view['order_number'] ) );
			?>
		</p>
		<ul class="ppwb-items">
			<?php foreach ( $ppwb_lines as $ppwb_line ) : ?>
				<?php $ppwb_field = 'ppwb-qty-' . $ppwb_line['id']; ?>
				<li class="ppwb-item<?php echo 0 === $ppwb_line['available'] ? ' is-disabled' : ''; ?>">
					<span class="ppwb-item__thumb"><?php echo wp_kses_post( $ppwb_line['thumb'] ); ?></span>
					<label for="<?php echo esc_attr( $ppwb_field ); ?>" class="ppwb-item__name">
						<?php echo esc_html( $ppwb_line['name'] ); ?>
						<?php if ( '' !== $ppwb_line['variant'] ) : ?>
							<small><?php echo esc_html( $ppwb_line['variant'] ); ?></small>
						<?php endif; ?>
					</label>
					<span class="ppwb-item__price"><?php echo wp_kses_post( $ppwb_line['price'] ); ?></span>
					<?php if ( $ppwb_line['available'] > 0 ) : ?>
						<select id="<?php echo esc_attr( $ppwb_field ); ?>" name="ppwb_qty[<?php echo esc_attr( (string) $ppwb_line['id'] ); ?>]" class="ppwb-item__qty">
							<?php for ( $ppwb_q = 0; $ppwb_q <= $ppwb_line['available']; $ppwb_q++ ) : ?>
								<option value="<?php echo esc_attr( (string) $ppwb_q ); ?>"<?php selected( (int) ( $ppwb_selection[ $ppwb_line['id'] ] ?? 0 ), $ppwb_q ); ?>>
									<?php
									/* translators: 1: quantity to return, 2: quantity available. */
									printf( esc_html__( '%1$d of %2$d', 'primeplaced-withdrawal-button' ), (int) $ppwb_q, (int) $ppwb_line['available'] );
									?>
								</option>
							<?php endfor; ?>
						</select>
					<?php elseif ( $ppwb_line['excluded'] ) : ?>
						<span class="ppwb-item__note">
							<?php
							/* translators: %s: statutory exception. */
							printf( esc_html__( 'This product cannot be returned: %s.', 'primeplaced-withdrawal-button' ), esc_html( '' !== $ppwb_line['exclusion_reason'] ? $ppwb_line['exclusion_reason'] : __( 'statutory exception', 'primeplaced-withdrawal-button' ) ) );
							?>
						</span>
					<?php else : ?>
						<span class="ppwb-item__note"><?php esc_html_e( 'Already returned', 'primeplaced-withdrawal-button' ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== $ppwb_line['note'] ) : ?>
						<span class="ppwb-item__hint"><?php echo esc_html( $ppwb_line['note'] ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="ppwb-estimate">
			<?php
			/* translators: %s: amount. */
			printf( esc_html__( 'Estimated refund for the selected quantities: %s. Step 3 shows the exact amount.', 'primeplaced-withdrawal-button' ), wp_kses_post( (string) ( $view['estimate'] ?? '' ) ) );
			?>
		</p>
		<?php if ( ! empty( $view['needs_bank'] ) ) : ?>
			<p class="ppwb-field">
				<label for="ppwb-iban"><?php esc_html_e( 'Bank account number for the refund', 'primeplaced-withdrawal-button' ); ?></label>
				<input type="text" id="ppwb-iban" name="ppwb_iban" required autocomplete="off" inputmode="text" aria-describedby="ppwb-iban-hint" value="<?php echo esc_attr( (string) $view['iban'] ); ?>">
				<span class="ppwb-field__hint" id="ppwb-iban-hint"><?php esc_html_e( 'We will refund this order by bank transfer, so we need your account number.', 'primeplaced-withdrawal-button' ); ?></span>
			</p>
		<?php else : ?>
			<p class="ppwb-field__hint">
				<?php
				/* translators: %s: payment method name. */
				printf( esc_html__( 'We will refund the money to the account you paid from (%s).', 'primeplaced-withdrawal-button' ), esc_html( (string) ( $view['pay_method'] ?? '' ) ) );
				?>
			</p>
		<?php endif; ?>
		<?php if ( ! empty( $view['ask_consumer'] ) ) : ?>
			<p class="ppwb-field">
				<label><input type="checkbox" name="ppwb_consumer" value="1" required<?php checked( ! empty( $view['consumer'] ) ); ?>> <?php esc_html_e( 'I bought as a consumer or as a sole trader for whom the purchase is not professional (consumer rights, Art. 38a).', 'primeplaced-withdrawal-button' ); ?></label>
			</p>
		<?php endif; ?>
		<?php $ppwb_reasons = (array) ( $view['reasons'] ?? array() ); ?>
		<?php if ( array() !== $ppwb_reasons ) : ?>
			<p class="ppwb-field">
				<label for="ppwb-reason-code"><?php esc_html_e( 'Reason for return (optional)', 'primeplaced-withdrawal-button' ); ?></label>
				<select id="ppwb-reason-code" name="ppwb_reason_code">
					<option value=""><?php esc_html_e( 'I prefer not to say', 'primeplaced-withdrawal-button' ); ?></option>
					<?php foreach ( $ppwb_reasons as $ppwb_code => $ppwb_label ) : ?>
						<option value="<?php echo esc_attr( (string) $ppwb_code ); ?>" <?php selected( (string) ( $view['reason_code'] ?? '' ), (string) $ppwb_code ); ?>><?php echo esc_html( (string) $ppwb_label ); ?></option>
					<?php endforeach; ?>
					<option value="other" <?php selected( (string) ( $view['reason_code'] ?? '' ), 'other' ); ?>><?php esc_html_e( 'Other', 'primeplaced-withdrawal-button' ); ?></option>
				</select>
			</p>
		<?php endif; ?>
		<p class="ppwb-field">
			<label for="ppwb-reason"><?php echo esc_html( array() === $ppwb_reasons ? __( 'Reason for return (optional)', 'primeplaced-withdrawal-button' ) : __( 'Comment (optional)', 'primeplaced-withdrawal-button' ) ); ?></label>
			<textarea id="ppwb-reason" name="ppwb_reason" rows="3" maxlength="1000"><?php echo esc_textarea( (string) $view['reason'] ); ?></textarea>
		</p>
		<p class="ppwb-actions">
			<a class="ppwb-button" href="<?php echo esc_url( (string) $view['form_url'] ); ?>"><?php esc_html_e( 'Back', 'primeplaced-withdrawal-button' ); ?></a>
			<button type="submit" class="ppwb-button ppwb-button--primary"><?php esc_html_e( 'Next', 'primeplaced-withdrawal-button' ); ?></button>
		</p>
	</form>
</div>
