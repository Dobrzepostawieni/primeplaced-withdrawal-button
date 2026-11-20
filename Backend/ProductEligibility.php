<?php
/**
 * "Right of withdrawal" field on products and product categories.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Backend;

use PrimePlaced\WithdrawalButton\Engine\Module;
use PrimePlaced\WithdrawalButton\Integrations\Exclusions;
use WP_Term;

/**
 * Class ProductEligibility
 */
final class ProductEligibility implements Module {

	private const NONCE = 'ppwb_eligibility';

	/**
	 * Adds the product and category fields.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_product_options_advanced', array( $this, 'product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product' ) );
		add_action( 'product_cat_add_form_fields', array( $this, 'category_add_fields' ) );
		add_action( 'product_cat_edit_form_fields', array( $this, 'category_edit_fields' ) );
		add_action( 'created_product_cat', array( $this, 'save_category' ) );
		add_action( 'edited_product_cat', array( $this, 'save_category' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation' ), 10, 2 );
		add_filter( 'manage_edit-product_columns', array( $this, 'add_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'bulk_actions-edit-product', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk' ), 10, 3 );
	}

	/**
	 * Fields of one variation; empty state follows the parent product.
	 *
	 * @param int      $loop      Variation index.
	 * @param mixed    $data      Variation data (unused).
	 * @param \WP_Post $variation Variation post.
	 * @return void
	 */
	public function variation_fields( int $loop, mixed $data, \WP_Post $variation ): void {
		unset( $data );
		if ( 0 === $loop ) {
			wp_nonce_field( self::NONCE, '_ppwb_variation_nonce' );
		}
		woocommerce_wp_select(
			array(
				'id'            => Exclusions::META_STATE . '_' . $loop,
				'name'          => Exclusions::META_STATE . '[' . $loop . ']',
				'label'         => __( 'Right of withdrawal', 'primeplaced-withdrawal-button' ),
				'options'       => array( '' => __( 'Same as the product', 'primeplaced-withdrawal-button' ) ) + array_slice( $this->state_options( true ), 1, null, true ),
				'value'         => (string) get_post_meta( $variation->ID, Exclusions::META_STATE, true ),
				'wrapper_class' => 'form-row form-row-first',
			)
		);
		woocommerce_wp_select(
			array(
				'id'            => Exclusions::META_REASON . '_' . $loop,
				'name'          => Exclusions::META_REASON . '[' . $loop . ']',
				'label'         => __( 'Exception (Art. 38)', 'primeplaced-withdrawal-button' ),
				'options'       => array( '' => '' ) + Exclusions::reasons(),
				'value'         => (string) get_post_meta( $variation->ID, Exclusions::META_REASON, true ),
				'wrapper_class' => 'form-row form-row-last',
			)
		);
	}

	/**
	 * Saves the variation fields.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop         Variation index.
	 * @return void
	 */
	public function save_variation( int $variation_id, int $loop ): void {
		if ( ! isset( $_POST['_ppwb_variation_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_ppwb_variation_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $variation_id ) ) {
			return;
		}

		$state  = isset( $_POST[ Exclusions::META_STATE ][ $loop ] ) ? sanitize_key( wp_unslash( $_POST[ Exclusions::META_STATE ][ $loop ] ) ) : '';
		$reason = isset( $_POST[ Exclusions::META_REASON ][ $loop ] ) ? sanitize_key( wp_unslash( $_POST[ Exclusions::META_REASON ][ $loop ] ) ) : '';

		update_post_meta( $variation_id, Exclusions::META_STATE, in_array( $state, array( Exclusions::ALLOWED, Exclusions::EXCLUDED, Exclusions::WITH_NOTE ), true ) ? $state : '' );
		update_post_meta( $variation_id, Exclusions::META_REASON, isset( Exclusions::reasons()[ $reason ] ) ? $reason : '' );
	}

	/**
	 * "Withdrawal" column on the products list.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function add_column( array $columns ): array {
		$columns['ppwb_withdrawal'] = __( 'Withdrawal', 'primeplaced-withdrawal-button' );

		return $columns;
	}

	/**
	 * Shows the exclusion on the products list; empty when withdrawal applies.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Product ID.
	 * @return void
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( 'ppwb_withdrawal' !== $column ) {
			return;
		}

		$eligibility = ( new Exclusions() )->for_product( $post_id );
		if ( Exclusions::EXCLUDED === $eligibility['state'] ) {
			printf( '<span title="%1$s">%2$s</span>', esc_attr( $eligibility['reason'] ), esc_html__( 'Excluded', 'primeplaced-withdrawal-button' ) );
		} elseif ( Exclusions::WITH_NOTE === $eligibility['state'] ) {
			printf( '<span title="%1$s">%2$s</span>', esc_attr( $eligibility['note'] ), esc_html__( 'With a note', 'primeplaced-withdrawal-button' ) );
		} else {
			echo '<span aria-hidden="true">-</span>';
		}
	}

	/**
	 * Bulk actions on the products list.
	 *
	 * @param array<string, string> $actions Actions.
	 * @return array<string, string>
	 */
	public function bulk_actions( array $actions ): array {
		$actions['ppwb_exclude'] = __( 'Right of withdrawal: does not apply', 'primeplaced-withdrawal-button' );
		$actions['ppwb_inherit'] = __( 'Right of withdrawal: follow categories', 'primeplaced-withdrawal-button' );

		return $actions;
	}

	/**
	 * Applies a bulk action; WordPress checks the list nonce before this filter.
	 *
	 * @param string    $redirect Redirect URL.
	 * @param string    $action   Action.
	 * @param list<int> $ids      Product IDs.
	 * @return string
	 */
	public function handle_bulk( string $redirect, string $action, array $ids ): string {
		if ( ! in_array( $action, array( 'ppwb_exclude', 'ppwb_inherit' ), true ) ) {
			return $redirect;
		}

		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post', (int) $id ) ) {
				continue;
			}
			if ( 'ppwb_exclude' === $action ) {
				update_post_meta( (int) $id, Exclusions::META_STATE, Exclusions::EXCLUDED );
				if ( '' === (string) get_post_meta( (int) $id, Exclusions::META_REASON, true ) ) {
					update_post_meta( (int) $id, Exclusions::META_REASON, 'other' );
				}
			} else {
				delete_post_meta( (int) $id, Exclusions::META_STATE );
			}
		}

		return $redirect;
	}

	/**
	 * Fields in Product data > Advanced.
	 *
	 * @return void
	 */
	public function product_fields(): void {
		global $post;

		$id = $post instanceof \WP_Post ? $post->ID : 0;
		echo '<div class="options_group">';
		wp_nonce_field( self::NONCE, '_ppwb_eligibility_nonce' );
		woocommerce_wp_select(
			array(
				'id'          => Exclusions::META_STATE,
				'label'       => __( 'Right of withdrawal', 'primeplaced-withdrawal-button' ),
				'options'     => $this->state_options( true ),
				'value'       => (string) get_post_meta( $id, Exclusions::META_STATE, true ),
				'desc_tip'    => true,
				'description' => __( 'Default follows the product categories.', 'primeplaced-withdrawal-button' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => Exclusions::META_REASON,
				'label'   => __( 'Exception (Art. 38)', 'primeplaced-withdrawal-button' ),
				'options' => array( '' => '' ) + Exclusions::reasons(),
				'value'   => (string) get_post_meta( $id, Exclusions::META_REASON, true ),
			)
		);
		woocommerce_wp_textarea_input(
			array(
				'id'    => Exclusions::META_NOTE,
				'label' => __( 'Note for the customer', 'primeplaced-withdrawal-button' ),
				'value' => (string) get_post_meta( $id, Exclusions::META_NOTE, true ),
			)
		);
		echo '</div>';
	}

	/**
	 * Saves the product fields.
	 *
	 * @param \WC_Product $product Product being saved.
	 * @return void
	 */
	public function save_product( \WC_Product $product ): void {
		if ( ! isset( $_POST['_ppwb_eligibility_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_ppwb_eligibility_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $product->get_id() ) ) {
			return;
		}

		foreach ( $this->posted() as $key => $value ) {
			$product->update_meta_data( $key, $value );
		}
	}

	/**
	 * Fields on "Add category".
	 *
	 * @return void
	 */
	public function category_add_fields(): void {
		wp_nonce_field( self::NONCE, '_ppwb_eligibility_nonce' );
		?>
		<div class="form-field">
			<label for="ppwb-cat-state"><?php esc_html_e( 'Right of withdrawal', 'primeplaced-withdrawal-button' ); ?></label>
			<?php $this->select( 'ppwb-cat-state', Exclusions::META_STATE, $this->state_options( false ), '' ); ?>
		</div>
		<div class="form-field">
			<label for="ppwb-cat-reason"><?php esc_html_e( 'Exception (Art. 38)', 'primeplaced-withdrawal-button' ); ?></label>
			<?php $this->select( 'ppwb-cat-reason', Exclusions::META_REASON, array( '' => '' ) + Exclusions::reasons(), '' ); ?>
		</div>
		<div class="form-field">
			<label for="ppwb-cat-note"><?php esc_html_e( 'Note for the customer', 'primeplaced-withdrawal-button' ); ?></label>
			<textarea id="ppwb-cat-note" name="<?php echo esc_attr( Exclusions::META_NOTE ); ?>" rows="3"></textarea>
		</div>
		<?php
	}

	/**
	 * Fields on "Edit category".
	 *
	 * @param WP_Term $term Category.
	 * @return void
	 */
	public function category_edit_fields( WP_Term $term ): void {
		wp_nonce_field( self::NONCE, '_ppwb_eligibility_nonce' );
		?>
		<tr class="form-field">
			<th scope="row"><label for="ppwb-cat-state"><?php esc_html_e( 'Right of withdrawal', 'primeplaced-withdrawal-button' ); ?></label></th>
			<td><?php $this->select( 'ppwb-cat-state', Exclusions::META_STATE, $this->state_options( false ), (string) get_term_meta( $term->term_id, Exclusions::META_STATE, true ) ); ?></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="ppwb-cat-reason"><?php esc_html_e( 'Exception (Art. 38)', 'primeplaced-withdrawal-button' ); ?></label></th>
			<td><?php $this->select( 'ppwb-cat-reason', Exclusions::META_REASON, array( '' => '' ) + Exclusions::reasons(), (string) get_term_meta( $term->term_id, Exclusions::META_REASON, true ) ); ?></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="ppwb-cat-note"><?php esc_html_e( 'Note for the customer', 'primeplaced-withdrawal-button' ); ?></label></th>
			<td><textarea id="ppwb-cat-note" name="<?php echo esc_attr( Exclusions::META_NOTE ); ?>" rows="3"><?php echo esc_textarea( (string) get_term_meta( $term->term_id, Exclusions::META_NOTE, true ) ); ?></textarea></td>
		</tr>
		<?php
	}

	/**
	 * Saves the category fields.
	 *
	 * @param int $term_id Category ID.
	 * @return void
	 */
	public function save_category( int $term_id ): void {
		if ( ! isset( $_POST['_ppwb_eligibility_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_ppwb_eligibility_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_product_terms' ) ) {
			return;
		}

		foreach ( $this->posted() as $key => $value ) {
			update_term_meta( $term_id, $key, $value );
		}
	}

	/**
	 * Sanitized posted values.
	 *
	 * @return array<string, string>
	 */
	private function posted(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by the callers.
		$state  = isset( $_POST[ Exclusions::META_STATE ] ) ? sanitize_key( wp_unslash( $_POST[ Exclusions::META_STATE ] ) ) : '';
		$reason = isset( $_POST[ Exclusions::META_REASON ] ) ? sanitize_key( wp_unslash( $_POST[ Exclusions::META_REASON ] ) ) : '';
		$note   = isset( $_POST[ Exclusions::META_NOTE ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ Exclusions::META_NOTE ] ) ) : '';
		// phpcs:enable

		return array(
			Exclusions::META_STATE  => in_array( $state, array( Exclusions::ALLOWED, Exclusions::EXCLUDED, Exclusions::WITH_NOTE ), true ) ? $state : '',
			Exclusions::META_REASON => isset( Exclusions::reasons()[ $reason ] ) ? $reason : '',
			Exclusions::META_NOTE   => mb_substr( $note, 0, 500 ),
		);
	}

	/**
	 * State options.
	 *
	 * @param bool $inherit Whether to offer "follow categories".
	 * @return array<string, string>
	 */
	private function state_options( bool $inherit ): array {
		return array(
			''                    => $inherit ? __( 'Follow categories', 'primeplaced-withdrawal-button' ) : __( 'Not set', 'primeplaced-withdrawal-button' ),
			Exclusions::ALLOWED   => __( 'Applies', 'primeplaced-withdrawal-button' ),
			Exclusions::WITH_NOTE => __( 'Applies, with a note for the customer', 'primeplaced-withdrawal-button' ),
			Exclusions::EXCLUDED  => __( 'Does not apply (statutory exception)', 'primeplaced-withdrawal-button' ),
		);
	}

	/**
	 * Prints a select.
	 *
	 * @param string                $id      Element ID.
	 * @param string                $name    Field name.
	 * @param array<string, string> $options Options.
	 * @param string                $current Current value.
	 * @return void
	 */
	private function select( string $id, string $name, array $options, string $current ): void {
		printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
		foreach ( $options as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $value ), selected( $current, (string) $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}
}
