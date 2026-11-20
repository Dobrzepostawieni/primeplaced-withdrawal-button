<?php
/**
 * Success screen. Override: yourtheme/primeplaced-withdrawal-button/form/success.php
 *
 * Keep the ppwb_success_next_steps action in an override: extensions add
 * their return shipping options there.
 *
 * @package PrimePlaced\WithdrawalButton
 * @var array<string, mixed> $view
 */

defined( 'ABSPATH' ) || exit;

$ppwb_number = (string) $view['number'];
?>
<div class="ppwb-form ppwb-success" role="status">
	<h3 class="ppwb-form__title"><?php esc_html_e( 'Done. Your statement has reached us.', 'primeplaced-withdrawal-button' ); ?></h3>
	<p class="ppwb-success__label"><?php esc_html_e( 'Request number', 'primeplaced-withdrawal-button' ); ?></p>
	<div class="ppwb-success__row">
		<p class="ppwb-success__number"
			data-copy-label="<?php esc_attr_e( 'Copy', 'primeplaced-withdrawal-button' ); ?>"
			data-copied-label="<?php esc_attr_e( 'Copied', 'primeplaced-withdrawal-button' ); ?>"><?php echo esc_html( $ppwb_number ); ?></p>
	</div>
	<p>
		<?php
		/* translators: %s: date and time of the statement. */
		printf( esc_html__( 'Submitted on: %s', 'primeplaced-withdrawal-button' ), esc_html( (string) $view['submitted'] ) );
		?>
	</p>
	<h4><?php esc_html_e( 'What next', 'primeplaced-withdrawal-button' ); ?></h4>
	<?php if ( ! empty( $view['before_shipment'] ) ) : ?>
		<ol class="ppwb-next">
			<li>
				<?php
				/* translators: %s: refund due date. */
				printf( esc_html__( 'We have not shipped the order yet. We will cancel it and refund the payment by %s.', 'primeplaced-withdrawal-button' ), esc_html( (string) $view['refund_due'] ) );
				?>
			</li>
			<?php if ( '' !== (string) ( $view['amount'] ?? '' ) ) : ?>
				<li>
					<?php
					/* translators: 1: amount, 2: refund method. */
					printf( esc_html__( 'Estimated refund: %1$s, %2$s.', 'primeplaced-withdrawal-button' ), wp_kses_post( (string) $view['amount'] ), esc_html( (string) $view['refund_method'] ) );
					?>
				</li>
			<?php endif; ?>
			<li>
				<?php
				/* translators: %s: return address. */
				printf( esc_html__( 'If the parcel has already left, refuse to accept it or send it back to: %s.', 'primeplaced-withdrawal-button' ), esc_html( (string) $view['return_address'] ) );
				?>
			</li>
		</ol>
	<?php else : ?>
		<ol class="ppwb-next">
			<li>
				<?php
				/* translators: %s: return address. */
				printf( esc_html__( 'Send the products back within 14 days to: %s.', 'primeplaced-withdrawal-button' ), esc_html( (string) $view['return_address'] ) );
				echo ' ' . esc_html( (string) $view['return_cost'] );
				?>
			</li>
			<li>
				<?php
				/* translators: %s: request number. */
				printf( esc_html__( 'Write the request number %s on the parcel.', 'primeplaced-withdrawal-button' ), esc_html( $ppwb_number ) );
				?>
			</li>
			<li>
				<?php
				/* translators: 1: date, 2: refund method. */
				printf( esc_html__( 'We will refund the payment by %1$s, %2$s. We may withhold the refund until we receive the products or proof that they were sent.', 'primeplaced-withdrawal-button' ), esc_html( (string) $view['refund_due'] ), esc_html( (string) $view['refund_method'] ) );
				if ( '' !== (string) ( $view['amount'] ?? '' ) ) {
					echo ' ';
					/* translators: %s: amount. */
					printf( esc_html__( 'Estimated refund: %s.', 'primeplaced-withdrawal-button' ), wp_kses_post( (string) $view['amount'] ) );
				}
				?>
			</li>
			<li>
				<?php
				/* translators: %s: link to the status page. */
				printf( esc_html__( 'After you send the parcel, add the tracking number on the %s: we can refund sooner.', 'primeplaced-withdrawal-button' ), '<a href="' . esc_url( (string) $view['status_url'] ) . '">' . esc_html__( 'request status page', 'primeplaced-withdrawal-button' ) . '</a>' );
				?>
			</li>
		</ol>
	<?php endif; ?>
	<?php
	/**
	 * Fires below the "What next" steps.
	 *
	 * @param array<string, mixed> $view Success view (request_id, number, status_url).
	 */
	do_action( 'ppwb_success_next_steps', $view );
	?>
	<p><?php esc_html_e( 'We have sent the confirmation with a PDF to your email address.', 'primeplaced-withdrawal-button' ); ?></p>
	<p class="ppwb-actions">
		<a class="ppwb-button ppwb-button--primary" href="<?php echo esc_url( (string) $view['status_url'] ); ?>"><?php esc_html_e( 'Check request status', 'primeplaced-withdrawal-button' ); ?></a>
		<a class="ppwb-button" href="<?php echo esc_url( (string) $view['pdf_url'] ); ?>"><?php esc_html_e( 'Download the confirmation (PDF)', 'primeplaced-withdrawal-button' ); ?></a>
	</p>
</div>
