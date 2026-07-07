<?php
/**
 * Quick Edit Fields
 *
 * @package Saltus/WP/Framework
 */

namespace Saltus\WP\Framework\Features\QuickEdit;

use Saltus\WP\Framework\Infrastructure\Service\{
	Processable
};

/**
 * Enable custom Quick Edit Fields
 *
 */
final class SaltusQuickEdit implements Processable {

	/**
	 * @var string $name The name of the custom post type (CPT)
	 */
	private string $name;

	/**
	 * @var array<string, array<string, mixed>> $fields List of fields for the custom fields
	 */
	private array $fields = [];

	/**
	 * Instantiate this Service object.
	 *
	 * @param string $name The name of the custom post type (CPT)
	 * @param array<string, mixed> $args List of Quick Edit Fields
	 */
	public function __construct( string $name, array $args ) {
		$this->name = $name; // cpt name
		foreach ( $args as $meta_key => $values ) {
			$this->fields[ $meta_key ] = [
				'meta_key'    => $meta_key,
				'column_name' => ! empty( $values['column_name'] ) ? $values['column_name'] : $meta_key,
				'title'       => ! empty( $values['title'] ) ? $values['title'] : ucfirst( $meta_key ),
			];
		}
	}

	/**
	 * Register filters for this feature
	 *
	 * @return void
	 */
	public function process(): void {

		// Save Quick Edit data
		add_action( 'save_post', [ $this, 'save_quick_edit_data' ] );
		// Hook the function to admin_footer
		add_action( 'current_screen', [ $this, 'current_screen_actions' ] );
	}

	public function save_quick_edit_data( int $post_id ): void {
		if (
			! isset( $_POST['quick_edit_nonce_field'] ) ||
			! wp_verify_nonce( $_POST['quick_edit_nonce_field'], 'quick_edit_nonce' ) ||
			! current_user_can( 'edit_post', $post_id )
		) {
			return;
		}
		foreach ( $this->fields as $meta_key => $field ) {
			if ( isset( $_POST[ $meta_key ] ) ) {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( $_POST[ $meta_key ] ) );
			}
		}
	}

	public function current_screen_actions(): void {
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
	public function populate_custom_column( string $column, int $post_id ): void {
		foreach ( $this->fields as $meta_key => $field ) {
			if ( $column === $field['column_name'] ) {
				$value = get_post_meta( $post_id, $meta_key, true );
				echo '<span class="hidden quick-edit-field-value" data-quick-edit-field="' . esc_attr( $value ) . '">' . esc_html( $value ) . '</span>';
			}
		}
	}

	/**
	 * Add a custom field to the Quick Edit form
	 *
	 * @param string $column_name The name of the column.
	 * @param string $post_type   The post type.
	 */
	public function add_quick_edit_field( string $column_name, string $post_type ): void {
		if ( $post_type !== $this->name ) {
			return;
		}
		foreach ( $this->fields as $meta_key => $field ) {
			if ( $column_name === $field['column_name'] ) {
				wp_nonce_field( 'quick_edit_nonce', 'quick_edit_nonce_field' );
				?>
				<fieldset class="inline-edit-col-right">
					<div class="inline-edit-col">
						<label>
							<span class="title"><?php echo esc_html( $field['title'] ); ?></span>
							<input type="text" name="<?php echo esc_attr( $meta_key ); ?>" value="" />
						</label>
					</div>
				</fieldset>
				<?php
			}
		}
	}

	/**
	 * Add JavaScript to handle Quick Edit functionality
	 *
	 * This script will populate the Quick Edit field with the custom field value
	 * when the user clicks on the Quick Edit link.
	 */
	public function quick_edit_javascript(): void {
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
					<?php foreach ( $this->fields as $meta_key => $field ) : ?>
						var quick_edit_field = $('#post-' + post_id).find('.<?php echo esc_js( $field['column_name'] ); ?> .quick-edit-field-value').data('quick-edit-field');
						$('#edit-' + post_id).find('input[name="<?php echo esc_js( $meta_key ); ?>"]').val(quick_edit_field);
					<?php endforeach; ?>
				}
			};
		});
		</script>
		<?php
	}
}
