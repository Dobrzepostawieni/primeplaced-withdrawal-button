<?php
/**
 * WooCommerce > Returns: the requests list, built like the orders list.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Backend;

use PrimePlaced\WithdrawalButton\Data\RequestListQuery;
use PrimePlaced\WithdrawalButton\Data\RequestRepository;
use PrimePlaced\WithdrawalButton\Engine\Module;

/**
 * Class AdminPage
 */
final class AdminPage implements Module {

	public const SLUG = 'ppwb-returns';

	public const SCREEN_ID = 'woocommerce_page_ppwb-returns';

	/**
	 * List table, created when the screen loads.
	 *
	 * @var RequestsListTable|null
	 */
	private ?RequestsListTable $table = null;

	/**
	 * Creates the page.
	 *
	 * @param RequestRepository $requests Request storage, for the menu badge.
	 * @param RequestListQuery  $query    List query.
	 * @param RequestScreen     $details  Request details screen.
	 */
	public function __construct(
		private RequestRepository $requests,
		private RequestListQuery $query,
		private RequestScreen $details
	) {
	}

	/**
	 * Adds the menu, the WooCommerce header connection and styles.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_filter( 'woocommerce_screen_ids', array( $this, 'add_screen_id' ) );
		add_filter( 'set-screen-option', array( $this, 'save_per_page' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PPWB_PLUGIN_ABSOLUTE ), array( $this, 'plugin_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );
	}

	/**
	 * URL of WooCommerce > Settings > Returns.
	 *
	 * @return string
	 */
	public static function settings_url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=ppwb' );
	}

	/**
	 * "Settings" on the plugins list.
	 *
	 * @param array<int|string, string> $links Action links.
	 * @return array<int|string, string>
	 */
	public function plugin_links( array $links ): array {
		array_unshift( $links, sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::settings_url() ), esc_html__( 'Settings', 'primeplaced-withdrawal-button' ) ) );

		return $links;
	}

	/**
	 * "Documentation" and "Support" under the plugin description on the plugins list.
	 *
	 * @param array<int|string, string> $links Row meta links.
	 * @param string                    $file  Plugin basename of the row.
	 * @return array<int|string, string>
	 */
	public function plugin_row_meta( array $links, string $file ): array {
		if ( plugin_basename( PPWB_PLUGIN_ABSOLUTE ) !== $file ) {
			return $links;
		}

		$link = '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>';

		/* translators: URL of the plugin documentation in the user's language. */
		$links['docs'] = sprintf( $link, esc_url( __( 'https://dobrzepostawieni.pl/withdrawal-button/documentation', 'primeplaced-withdrawal-button' ) ), esc_html__( 'Documentation', 'primeplaced-withdrawal-button' ) );
		/* translators: URL of the plugin support page in the user's language. */
		$links['support'] = sprintf( $link, esc_url( __( 'https://dobrzepostawieni.pl/withdrawal-button/support', 'primeplaced-withdrawal-button' ) ), esc_html__( 'Support', 'primeplaced-withdrawal-button' ) );

		return $links;
	}

	/**
	 * Adds WooCommerce > Returns with a badge for new requests, and connects
	 * the page to the WooCommerce admin header.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		$new   = $this->requests->count_new();
		$badge = $new > 0 ? sprintf( ' <span class="awaiting-mod update-plugins count-%1$d"><span class="pending-count">%1$d</span></span>', $new ) : '';

		$hook = add_submenu_page(
			'woocommerce',
			__( 'Returns', 'primeplaced-withdrawal-button' ),
			__( 'Returns', 'primeplaced-withdrawal-button' ) . $badge,
			Lifecycle::CAP_MANAGE_REQUESTS,
			self::SLUG,
			array( $this, 'render' )
		);

		if ( is_string( $hook ) ) {
			add_action( 'load-' . $hook, array( $this, 'load' ) );
		}

		if ( function_exists( 'wc_admin_connect_page' ) ) {
			wc_admin_connect_page(
				array(
					'id'        => self::SLUG,
					'screen_id' => self::SCREEN_ID,
					'title'     => __( 'Returns', 'primeplaced-withdrawal-button' ),
					'path'      => 'admin.php?page=' . self::SLUG,
				)
			);
		}
	}

	/**
	 * Lets WooCommerce load its admin styles (order-status badges) on our screen.
	 *
	 * @param array<int, string> $screen_ids WooCommerce screen IDs.
	 * @return array<int, string>
	 */
	public function add_screen_id( array $screen_ids ): array {
		$screen_ids[] = self::SCREEN_ID;

		return $screen_ids;
	}

	/**
	 * Prepares the table and the "Number of items per page" screen option.
	 *
	 * @return void
	 */
	public function load(): void {
		if ( $this->requested_id() > 0 ) {
			wp_enqueue_script( 'postbox' );
			return;
		}

		$this->handle_bulk_action();

		add_screen_option(
			'per_page',
			array(
				'default' => 20,
				'option'  => RequestsListTable::PER_PAGE_OPTION,
			)
		);

		$this->table = new RequestsListTable( $this->query );
		$this->table->prepare_items();
	}

	/**
	 * Stores the per-page screen option.
	 *
	 * @param mixed  $keep   Value to keep, false by default.
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @return mixed
	 */
	public function save_per_page( mixed $keep, string $option, mixed $value ): mixed {
		return RequestsListTable::PER_PAGE_OPTION === $option ? min( 200, max( 1, absint( $value ) ) ) : $keep;
	}

	/**
	 * Prints the page: brand banner, then a standard WooCommerce list screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( $this->requested_id() > 0 ) {
			BrandBanner::render();
			$this->details->render( $this->requested_id() );
			return;
		}

		if ( null === $this->table ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search label.
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

		BrandBanner::render();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Returns', 'primeplaced-withdrawal-button' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( self::settings_url() ); ?>"><?php esc_html_e( 'Settings', 'primeplaced-withdrawal-button' ); ?></a>
			<?php if ( '' !== $search ) : ?>
				<span class="subtitle">
					<?php
					/* translators: %s: search query. */
					printf( esc_html__( 'Search results for: %s', 'primeplaced-withdrawal-button' ), '<strong>' . esc_html( $search ) . '</strong>' );
					?>
				</span>
			<?php endif; ?>
			<hr class="wp-header-end">
			<?php $this->table->views(); ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<?php
				$this->table->search_box( __( 'Search requests', 'primeplaced-withdrawal-button' ), 'ppwb-requests-search' );
				$this->table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Loads the banner stylesheet on our screen.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( self::SCREEN_ID === $hook_suffix ) {
			BrandBanner::enqueue_style();
		}
	}

	/**
	 * Request ID from the URL when the details screen is open.
	 *
	 * @return int
	 */
	private function requested_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen switch.
		return isset( $_GET['request'] ) ? absint( $_GET['request'] ) : 0;
	}

	/**
	 * Applies a bulk status change from the list and redirects back.
	 *
	 * @return void
	 */
	private function handle_bulk_action(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce checked below before any change.
		$action = isset( $_GET['action'] ) && '-1' !== $_GET['action'] ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( '' === $action && isset( $_GET['action2'] ) && '-1' !== $_GET['action2'] ) {
			$action = sanitize_key( wp_unslash( $_GET['action2'] ) );
		}
		$ids = isset( $_GET['request_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_GET['request_ids'] ) ) : array();
		// phpcs:enable

		if ( '' === $action || array() === $ids ) {
			return;
		}

		check_admin_referer( 'bulk-ppwb-requests' );
		if ( ! current_user_can( Lifecycle::CAP_MANAGE_REQUESTS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage withdrawal requests.', 'primeplaced-withdrawal-button' ), 403 );
		}

		if ( ! str_starts_with( $action, 'mark_' ) ) {
			/**
			 * Runs a bulk action added through the "bulk_actions-woocommerce_page_ppwb-returns"
			 * filter. The nonce and the capability are already checked.
			 *
			 * @param string    $action Bulk action key.
			 * @param list<int> $ids    Selected request IDs.
			 */
			do_action( 'ppwb_requests_bulk_action', $action, array_values( array_filter( $ids ) ) );
			return;
		}

		$this->details->bulk_status( $ids, substr( $action, 5 ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&ppwb_bulk=1' ) );
		exit;
	}
}
