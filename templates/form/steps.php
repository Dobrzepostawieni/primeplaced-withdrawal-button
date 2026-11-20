<?php
/**
 * Step bar and intro. Override: yourtheme/primeplaced-withdrawal-button/form/steps.php
 *
 * @package PrimePlaced\WithdrawalButton
 * @var array<string, mixed> $view
 */

defined( 'ABSPATH' ) || exit;

$ppwb_current = (int) ( $view['current'] ?? 1 );
$ppwb_steps   = array(
	1 => __( 'Order', 'primeplaced-withdrawal-button' ),
	2 => __( 'Products', 'primeplaced-withdrawal-button' ),
	3 => __( 'Confirmation', 'primeplaced-withdrawal-button' ),
);
?>
<ol class="ppwb-steps" aria-label="<?php esc_attr_e( 'Form steps', 'primeplaced-withdrawal-button' ); ?>">
	<?php foreach ( $ppwb_steps as $ppwb_number => $ppwb_label ) : ?>
		<li class="ppwb-steps__item<?php echo $ppwb_number === $ppwb_current ? ' is-current' : ''; ?>"<?php echo $ppwb_number === $ppwb_current ? ' aria-current="step"' : ''; ?>>
			<span class="ppwb-steps__number"><?php echo esc_html( (string) $ppwb_number ); ?></span>
			<?php echo esc_html( $ppwb_label ); ?>
		</li>
	<?php endforeach; ?>
</ol>
<?php if ( ! empty( $view['error'] ) ) : ?>
	<div class="ppwb-notice ppwb-notice--error" role="alert" id="ppwb-error"><?php echo esc_html( (string) $view['error'] ); ?></div>
<?php endif; ?>
