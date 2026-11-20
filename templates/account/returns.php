<?php
/**
 * My account > Returns. Override: yourtheme/primeplaced-withdrawal-button/account/returns.php
 *
 * @package PrimePlaced\WithdrawalButton
 * @var array<string, mixed> $view
 */

defined( 'ABSPATH' ) || exit;

$ppwb_requests = (array) $view['requests'];
?>
<?php if ( array() === $ppwb_requests ) : ?>
	<div class="woocommerce-info">
		<?php esc_html_e( 'You have no withdrawal requests.', 'primeplaced-withdrawal-button' ); ?>
		<a class="woocommerce-Button button" href="<?php echo esc_url( (string) $view['form_url'] ); ?>"><?php echo esc_html( ( new \PrimePlaced\WithdrawalButton\Data\Settings() )->button_label() ); ?></a>
	</div>
<?php else : ?>
	<table class="woocommerce-orders-table shop_table shop_table_responsive my_account_orders">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Request', 'primeplaced-withdrawal-button' ); ?></th>
				<th><?php esc_html_e( 'Order', 'primeplaced-withdrawal-button' ); ?></th>
				<th><?php esc_html_e( 'Date', 'primeplaced-withdrawal-button' ); ?></th>
				<th><?php esc_html_e( 'Status', 'primeplaced-withdrawal-button' ); ?></th>
				<th><?php esc_html_e( 'Amount', 'primeplaced-withdrawal-button' ); ?></th>
				<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'primeplaced-withdrawal-button' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $ppwb_requests as $ppwb_request ) : ?>
				<tr>
					<td data-title="<?php esc_attr_e( 'Request', 'primeplaced-withdrawal-button' ); ?>"><span style="white-space:nowrap;"><?php echo esc_html( (string) $ppwb_request['number'] ); ?></span></td>
					<td data-title="<?php esc_attr_e( 'Order', 'primeplaced-withdrawal-button' ); ?>">
						<?php if ( $ppwb_request['order'] instanceof WC_Order ) : ?>
							<a href="<?php echo esc_url( $ppwb_request['order']->get_view_order_url() ); ?>">#<?php echo esc_html( (string) $ppwb_request['order']->get_order_number() ); ?></a>
						<?php endif; ?>
					</td>
					<td data-title="<?php esc_attr_e( 'Date', 'primeplaced-withdrawal-button' ); ?>" style="white-space:nowrap;"><?php echo esc_html( \PrimePlaced\WithdrawalButton\Integrations\Dates::date_gmt( (string) $ppwb_request['submitted_at_gmt'] ) ); ?></td>
					<td data-title="<?php esc_attr_e( 'Status', 'primeplaced-withdrawal-button' ); ?>"><?php echo esc_html( (string) $ppwb_request['status_label'] ); ?></td>
					<td data-title="<?php esc_attr_e( 'Amount', 'primeplaced-withdrawal-button' ); ?>" style="white-space:nowrap;"><?php echo wp_kses_post( (string) $ppwb_request['amount'] ); ?></td>
					<td><a class="woocommerce-button button view" href="<?php echo esc_url( (string) $ppwb_request['status_url'] ); ?>"><?php esc_html_e( 'View', 'primeplaced-withdrawal-button' ); ?></a></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
