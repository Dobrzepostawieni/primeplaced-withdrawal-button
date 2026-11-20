<?php
/**
 * Dashboard widget: new requests and refunds close to the deadline.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Backend;

use PrimePlaced\WithdrawalButton\Data\RequestRepository;
use PrimePlaced\WithdrawalButton\Data\Settings;
use PrimePlaced\WithdrawalButton\Engine\Module;

/**
 * Class DashboardWidget
 */
final class DashboardWidget implements Module {

	/**
	 * Creates the widget.
	 *
	 * @param RequestRepository $requests Request storage.
	 * @param Settings          $settings Plugin settings.
	 */
	public function __construct(
		private RequestRepository $requests,
		private Settings $settings
	) {
	}

	/**
	 * Adds the widget hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'add' ) );
	}

	/**
	 * Registers the widget for users who handle requests.
	 *
	 * @return void
	 */
	public function add(): void {
		if ( current_user_can( Lifecycle::CAP_MANAGE_REQUESTS ) ) {
			wp_add_dashboard_widget( 'ppwb_dashboard', __( 'Withdrawals and returns', 'primeplaced-withdrawal-button' ), array( $this, 'render' ) );
		}
	}

	/**
	 * Prints the widget.
	 *
	 * @return void
	 */
	public function render(): void {
		$new = $this->requests->count_new();
		$due = $this->requests->refund_due_within( (int) $this->settings->get( 'refund_reminder_days' ) );

		printf(
			'<p><a href="%1$s"><strong>%2$s</strong></a></p>',
			esc_url( admin_url( 'admin.php?page=' . AdminPage::SLUG . '&status=new' ) ),
			/* translators: %d: number of new requests. */
			esc_html( sprintf( _n( '%d new request', '%d new requests', $new, 'primeplaced-withdrawal-button' ), $new ) )
		);

		if ( array() === $due ) {
			echo '<p>' . esc_html__( 'No refunds close to the deadline.', 'primeplaced-withdrawal-button' ) . '</p>';
			return;
		}

		echo '<p>' . esc_html__( 'Refunds close to the 14-day deadline:', 'primeplaced-withdrawal-button' ) . '</p><ul>';
		foreach ( $due as $request ) {
			printf(
				'<li><a href="%1$s">%2$s</a>, %3$s</li>',
				esc_url( RequestScreen::url( (int) $request['id'] ) ),
				esc_html( (string) $request['number'] ),
				esc_html( \PrimePlaced\WithdrawalButton\Integrations\Dates::date_gmt( (string) $request['refund_due_gmt'] ) )
			);
		}
		echo '</ul>';
	}
}
