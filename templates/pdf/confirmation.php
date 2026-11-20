<?php
/**
 * Confirmation PDF (rendered by Dompdf). Override: yourtheme/primeplaced-withdrawal-button/pdf/confirmation.php
 *
 * @package PrimePlaced\WithdrawalButton
 * @var array<string, mixed> $view
 */

defined( 'ABSPATH' ) || exit;

$ppwb_request = (array) $view['request'];
$ppwb_shop    = (array) $view['shop'];
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8">
<style>
	body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5pt; color: #111; line-height: 1.45; }
	h1 { font-size: 15pt; margin: 0 0 4mm; }
	h2 { font-size: 11pt; margin: 6mm 0 2mm; border-bottom: 0.3mm solid #999; padding-bottom: 1mm; }
	table { width: 100%; border-collapse: collapse; }
	td, th { text-align: left; vertical-align: top; padding: 1mm 0; }
	.number { font-size: 14pt; font-weight: bold; }
	.statement { border-left: 1mm solid #1d4ed8; padding: 2mm 4mm; background: #f3f6fd; }
	.footer { margin-top: 10mm; font-size: 8pt; color: #555; word-break: break-all; page-break-inside: avoid; }
	.logo img { max-height: 18mm; max-width: 60mm; }
	.meta .label { width: 55mm; color: #444; }
	.parties td { width: 50%; padding-right: 6mm; }
	.parties h2 { margin-top: 4mm; }
	.items .qty { width: 25mm; text-align: right; }
	.items th { border-bottom: 0.3mm solid #999; }
	.statement, .parties, .next { page-break-inside: avoid; }
	.next { margin: 0; padding-left: 5mm; }
</style>
</head>
<body>
	<?php if ( ! empty( $view['logo'] ) ) : ?>
		<p class="logo"><img src="<?php echo esc_attr( (string) $view['logo'] ); ?>" alt=""></p>
	<?php endif; ?>
	<h1><?php esc_html_e( 'Confirmation of receipt of the statement of withdrawal from the contract', 'primeplaced-withdrawal-button' ); ?></h1>
	<table class="meta">
		<tr><td class="label"><?php esc_html_e( 'Request number', 'primeplaced-withdrawal-button' ); ?></td><td><span class="number"><?php echo esc_html( (string) $ppwb_request['number'] ); ?></span></td></tr>
		<tr><td class="label"><?php esc_html_e( 'Date and time of the statement', 'primeplaced-withdrawal-button' ); ?></td><td><strong><?php echo esc_html( (string) $view['submitted'] ); ?></strong></td></tr>
	</table>

	<?php /* One table for both parties: separate blocks were printed twice by some viewers. */ ?>
	<table class="parties">
		<tr>
			<td>
				<h2><?php esc_html_e( 'Seller', 'primeplaced-withdrawal-button' ); ?></h2>
				<div><?php echo esc_html( (string) $ppwb_shop['name'] ); ?></div>
				<?php if ( '' !== (string) $ppwb_shop['address'] ) : ?>
					<div><?php echo esc_html( (string) $ppwb_shop['address'] ); ?></div>
				<?php endif; ?>
				<?php if ( '' !== (string) $ppwb_shop['tax_id'] ) : ?>
					<div><?php esc_html_e( 'Tax ID', 'primeplaced-withdrawal-button' ); ?>: <?php echo esc_html( (string) $ppwb_shop['tax_id'] ); ?></div>
				<?php endif; ?>
				<div><?php echo esc_html( (string) $ppwb_shop['email'] ); ?></div>
			</td>
			<td>
				<h2><?php esc_html_e( 'Consumer', 'primeplaced-withdrawal-button' ); ?></h2>
				<div><?php echo esc_html( (string) $ppwb_request['full_name'] ); ?></div>
				<div><?php echo esc_html( (string) $ppwb_request['email'] ); ?></div>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Order and products', 'primeplaced-withdrawal-button' ); ?></h2>
	<p>
		<?php
		/* translators: 1: order number, 2: order date. */
		printf( esc_html__( 'Order no. %1$s of %2$s', 'primeplaced-withdrawal-button' ), esc_html( (string) $view['order_number'] ), esc_html( (string) $view['order_date'] ) );
		?>
	</p>
	<table class="items">
		<thead><tr><th><?php esc_html_e( 'Product', 'primeplaced-withdrawal-button' ); ?></th><th class="qty"><?php esc_html_e( 'Quantity', 'primeplaced-withdrawal-button' ); ?></th></tr></thead>
		<tbody>
			<?php foreach ( (array) $view['items'] as $ppwb_item ) : ?>
				<tr><td><?php echo esc_html( (string) $ppwb_item['name'] ); ?></td><td class="qty"><?php echo esc_html( (string) $ppwb_item['qty'] ); ?></td></tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Statement text', 'primeplaced-withdrawal-button' ); ?></h2>
	<div class="statement"><?php echo esc_html( (string) $ppwb_request['statement_text'] ); ?></div>

	<?php if ( ! empty( $view['next_steps'] ) ) : ?>
		<h2><?php esc_html_e( 'What next', 'primeplaced-withdrawal-button' ); ?></h2>
		<ol class="next">
			<?php foreach ( (array) $view['next_steps'] as $ppwb_step ) : ?>
				<li><?php echo esc_html( (string) $ppwb_step ); ?></li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>

	<div class="footer">
		<?php esc_html_e( 'SHA-256 of the statement text', 'primeplaced-withdrawal-button' ); ?>: <?php echo esc_html( (string) $ppwb_request['statement_hash'] ); ?><br>
		<?php esc_html_e( 'Document generated', 'primeplaced-withdrawal-button' ); ?>: <?php echo esc_html( (string) $view['generated'] ); ?>
	</div>
</body>
</html>
