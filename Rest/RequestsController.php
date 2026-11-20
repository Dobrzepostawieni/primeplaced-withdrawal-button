<?php
/**
 * REST: ppwb/v1/requests.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Rest;

use PrimePlaced\WithdrawalButton\Backend\Lifecycle;
use PrimePlaced\WithdrawalButton\Data\RequestRepository;
use PrimePlaced\WithdrawalButton\Engine\Module;
use WP_REST_Response;

/**
 * Class RequestsController
 */
final class RequestsController implements Module {

	/**
	 * Creates the controller.
	 *
	 * @param RequestRepository $requests Request storage.
	 */
	public function __construct( private RequestRepository $requests ) {
	}

	/**
	 * Adds the route hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function routes(): void {
		register_rest_route(
			PPWB_API_NAMESPACE,
			'/requests',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => static fn (): bool => current_user_can( Lifecycle::CAP_MANAGE_REQUESTS ),
				'args'                => array(),
			)
		);
	}

	/**
	 * Lists the latest requests.
	 *
	 * @return WP_REST_Response
	 */
	public function index(): WP_REST_Response {
		$rows = array_map(
			static function ( array $row ): array {
				$order = wc_get_order( $row['order_id'] );

				return $row + array(
					'order_number' => $order instanceof \WC_Order ? (string) $order->get_order_number() : (string) $row['order_id'],
					'order_url'    => $order instanceof \WC_Order ? $order->get_edit_order_url() : '',
				);
			},
			$this->requests->latest()
		);

		return new WP_REST_Response( $rows );
	}
}
