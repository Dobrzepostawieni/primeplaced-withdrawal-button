<?php
/**
 * Request status. Override: yourtheme/primeplaced-withdrawal-button/status/details.php
 *
 * Keep the ppwb_status_details_shipping action in an override: extensions add
 * their return shipping options there.
 *
 * @package PrimePlaced\WithdrawalButton
 * @var array<string, mixed> $view
 */

defined( 'ABSPATH' ) || exit;

$ppwb_request = (array) $view['request'];
// Carriers for the customer's country (filter ppwb_return_carriers), then "Other".
$ppwb_carriers   = isset( $view['carriers'] ) ? (array) $view['carriers'] : array();
$ppwb_carriers[] = __( 'Other', 'primeplaced-withdrawal-button' );
$ppwb_other_key  = array_key_last( $ppwb_carriers );
?>
<div class="ppwb-form ppwb-status" aria-live="polite">
	<?php if ( ! empty( $view['error'] ) ) : ?>
		<div class="ppwb-notice ppwb-notice--error" role="alert"><?php echo wp_kses_post( (string) $view['error'] ); ?></div>
	<?php endif; ?>

	<p class="ppwb-success__label"><?php esc_html_e( 'Request number', 'primeplaced-withdrawal-button' ); ?></p>
	<p class="ppwb-success__number"><?php echo esc_html( (string) $ppwb_request['number'] ); ?></p>
	<p>
		<?php esc_html_e( 'Status:', 'primeplaced-withdrawal-button' ); ?> <strong><?php echo esc_html( (string) $view['status_label'] ); ?></strong>
	</p>

	<h3 class="ppwb-form__title"><?php esc_html_e( 'Timeline', 'primeplaced-withdrawal-button' ); ?></h3>
	<ol class="ppwb-timeline">
		<?php foreach ( (array) $view['timeline'] as $ppwb_step ) : ?>
			<li>
				<strong><?php echo esc_html( (string) $ppwb_step['label'] ); ?></strong>,
				<?php echo esc_html( \PrimePlaced\WithdrawalButton\Integrations\Dates::datetime_gmt( (string) $ppwb_step['time'] ) ); ?>
				<?php if ( ! empty( $ppwb_step['note'] ) ) : ?>
					<br><span class="ppwb-field__hint"><?php echo esc_html( (string) $ppwb_step['note'] ); ?></span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>

	<h3 class="ppwb-form__title"><?php esc_html_e( 'Products', 'primeplaced-withdrawal-button' ); ?></h3>
	<ul class="ppwb-summary-items">
		<?php foreach ( (array) $view['items'] as $ppwb_item ) : ?>
			<li><?php echo esc_html( $ppwb_item['name'] . ' x ' . $ppwb_item['qty'] ); ?></li>
		<?php endforeach; ?>
	</ul>
	<p><a class="ppwb-button" href="<?php echo esc_url( (string) $view['pdf_url'] ); ?>"><?php esc_html_e( 'Download confirmation (PDF)', 'primeplaced-withdrawal-button' ); ?></a></p>

	<?php
	/**
	 * Fires above the shipping details form.
	 *
	 * @param array<string, mixed> $view Status view (request, reference, page_url, nonce, can_ship).
	 */
	do_action( 'ppwb_status_details_shipping', $view );
	?>

	<?php if ( ! empty( $view['can_ship'] ) ) : ?>
		<h3 class="ppwb-form__title"><?php esc_html_e( 'Have you sent the parcel?', 'primeplaced-withdrawal-button' ); ?></h3>
		<?php if ( ! empty( $ppwb_request['tracking_number'] ) ) : ?>
			<p><?php echo esc_html( trim( $ppwb_request['tracking_carrier'] . ' ' . $ppwb_request['tracking_number'] ) ); ?></p>
		<?php endif; ?>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( add_query_arg( 'ppwb_r', (string) $view['reference'], (string) $view['page_url'] ) ); ?>" class="ppwb-form__body">
			<?php echo $view['nonce']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() output. ?>
			<input type="hidden" name="ppwb_do" value="shipping">
			<p class="ppwb-field">
				<label for="ppwb-carrier"><?php esc_html_e( 'Carrier', 'primeplaced-withdrawal-button' ); ?></label>
				<select id="ppwb-carrier" name="ppwb_carrier" class="input-text">
					<?php foreach ( $ppwb_carriers as $ppwb_key => $ppwb_carrier ) : ?>
						<option value="<?php echo esc_attr( $ppwb_carrier ); ?>"<?php echo $ppwb_other_key === $ppwb_key ? ' data-ppwb-other="1"' : ''; ?>><?php echo esc_html( $ppwb_carrier ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="ppwb-field ppwb-field--carrier-other">
				<label for="ppwb-carrier-other"><?php esc_html_e( 'Other carrier (when you chose "Other")', 'primeplaced-withdrawal-button' ); ?></label>
				<input type="text" id="ppwb-carrier-other" name="ppwb_carrier_other" maxlength="64" class="input-text">
			</p>
			<p class="ppwb-field">
				<label for="ppwb-tracking"><?php esc_html_e( 'Tracking number', 'primeplaced-withdrawal-button' ); ?></label>
				<input type="text" id="ppwb-tracking" name="ppwb_tracking" maxlength="64" class="input-text">
			</p>
			<p class="ppwb-field">
				<label for="ppwb-proof"><?php esc_html_e( 'Proof of shipment (JPG, PNG or PDF, up to 5 MB, optional)', 'primeplaced-withdrawal-button' ); ?></label>
				<input type="file" id="ppwb-proof" name="ppwb_proof" accept=".jpg,.jpeg,.png,.pdf" class="input-text">
			</p>
			<p class="ppwb-actions"><button type="submit" class="ppwb-button ppwb-button--primary"><?php esc_html_e( 'Save shipping details', 'primeplaced-withdrawal-button' ); ?></button></p>
		</form>
	<?php endif; ?>

	<?php if ( ! empty( $view['can_cancel'] ) ) : ?>
		<h3 class="ppwb-form__title"><?php esc_html_e( 'Withdraw the request', 'primeplaced-withdrawal-button' ); ?></h3>
		<form method="post" action="<?php echo esc_url( add_query_arg( 'ppwb_r', (string) $view['reference'], (string) $view['page_url'] ) ); ?>" class="ppwb-form__body">
			<?php echo $view['nonce']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() output. ?>
			<input type="hidden" name="ppwb_do" value="cancel">
			<p class="ppwb-field ppwb-field--check">
				<label><input type="checkbox" name="ppwb_confirm_cancel" value="1" required> <?php esc_html_e( 'I want to withdraw this request. The contract will stay in force.', 'primeplaced-withdrawal-button' ); ?></label>
			</p>
			<p class="ppwb-actions"><button type="submit" class="ppwb-button"><?php esc_html_e( 'Withdraw request', 'primeplaced-withdrawal-button' ); ?></button></p>
		</form>
	<?php endif; ?>
</div>
