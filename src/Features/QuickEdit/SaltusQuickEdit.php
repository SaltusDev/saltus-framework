<?php
/**
 * Admin Columns
 *
 * @package Saltus/WP/Framework
 */

namespace Saltus\WP\Framework\Features\QuickEdit;

use Saltus\WP\Framework\Infrastructure\Service\{
	Processable
};

/**
 * Enable custom administration columns
 *
 */
final class SaltusQuickEdit implements Processable {

	/**
	 * @var string $name The name of the custom post type (CPT)
	 */
	private $name;
	/**
	 * @var string $field_name The name of the custom field to be added
	 */
	private $field_name;

	/**
	 * @var string $column_name The name of the custom column to be edited
	 */
	private $column_name;

	/**
	 * Instantiate this Service object.
	 *
	 * @param string $name The name of the custom post type (CPT)
	 * @param array  $args List of columns
	 */
	public function __construct( string $name, array $args ) {
		$this->name        = $name; // cpt name
		$this->field_name  = ! empty( $args['label'] ) ? $args['label'] : '';
		$this->column_name = ! empty( $args['column_name'] ) ? $args['column_name'] : '';
	}

	/**
	 * Register filters for this feature
	 *
	 * @return void
	 */
	public function process() {

		// Save Quick Edit data
		add_action( 'save_post', [ $this, 'save_quick_edit_data' ] );
		// Hook the function to admin_footer
		add_action( 'current_screen', [ $this, 'current_screen_actions' ] );
	}

	public function save_quick_edit_data( $post_id ) {
		if ( ! isset( $_POST['quick_edit_nonce_field'] ) ||
			! wp_verify_nonce( $_POST['quick_edit_nonce_field'], 'quick_edit_nonce' ) ||
			! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST[ $this->field_name ] ) ) {
			update_post_meta( $post_id, $this->field_name, sanitize_text_field( $_POST[ $this->field_name ] ) );
		}
	}

	public function current_screen_actions() {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		if ( $screen->base === 'edit' && $screen->post_type === $this->name ) {

			// Populate custom column
			add_action( "manage_{$this->name}_posts_custom_column", [ $this, 'populate_custom_column' ], 10, 2 );

			// Add field to Quick Edit
			add_action( 'quick_edit_custom_box', [ $this, 'add_quick_edit_field' ], 10, 2 );

			// Add JavaScript
			add_action( 'admin_footer', [ $this, 'quick_edit_javascript' ] );
		}
	}


	/**
	 * Overwrite the custom column to add a dataset attribute
	 *
	 * @param string $column The name of the column.
	 * @param int    $post_id The ID of the post.
	 */
	public function populate_custom_column( $column, $post_id ) {
		if ( $column === $this->column_name ) {
			$value = get_post_meta( $post_id, $this->field_name, true );
			echo '<span class="hidden custom-field-value" data-custom-field="' . esc_attr( $value ) . '">' . esc_html( $value ) . '</span>';

		}
	}

	/**
	 * Add a custom field to the Quick Edit form
	 *
	 * @param string $column_name The name of the column.
	 * @param string $post_type   The post type.
	 */
	public function add_quick_edit_field( $column_name, $post_type ) {
		if ( $post_type !== $this->name || $column_name !== $this->column_name ) {
			return;
		}

		wp_nonce_field( 'quick_edit_nonce', 'quick_edit_nonce_field' );
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label>
					<span class="title"><?php _e( 'Globe id', 'textdomain' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $this->field_name ); ?>" value="" />
				</label>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Add JavaScript to handle Quick Edit functionality
	 *
	 * This script will populate the Quick Edit field with the custom field value
	 * when the user clicks on the Quick Edit link.
	 */
	public function quick_edit_javascript() {
		?>
		<script type="text/javascript">
		jQuery(function($) {
			var $wp_inline_edit = inlineEditPost.edit;

			inlineEditPost.edit = function(id) {
				$wp_inline_edit.apply(this, arguments);

				var post_id = 0;
				if (typeof(id) == 'object') {
					post_id = parseInt(this.getId(id));
				}

				if (post_id > 0) {
					var $row = $('#edit-' + post_id);
					var custom_field = $('#post-' + post_id).find('.<?php echo esc_js( $this->column_name ); ?> .custom-field-value').data('custom-field');
					$row.find('input[name="<?php echo esc_js( $this->field_name ); ?>"]').val(custom_field);
				}
			};
		});
		</script>
		<?php
	}
}
