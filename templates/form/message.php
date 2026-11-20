<?php
/**
 * Message when the order cannot be withdrawn. Override: yourtheme/primeplaced-withdrawal-button/form/message.php
 *
 * @package PrimePlaced\WithdrawalButton
 * @var array<string, mixed> $view
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="ppwb-form" role="status">
	<h3 class="ppwb-form__title"><?php echo esc_html( (string) $view['title'] ); ?></h3>
	<p><?php echo esc_html( (string) $view['message'] ); ?></p>
	<p class="ppwb-actions">
		<a class="ppwb-button" href="<?php echo esc_url( (string) $view['form_url'] ); ?>"><?php esc_html_e( 'Check another order', 'primeplaced-withdrawal-button' ); ?></a>
	</p>
</div>
