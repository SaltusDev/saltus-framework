<?php
namespace Saltus\WP\Framework\Features\Duplicate;

use Saltus\WP\Framework\Infrastructure\Service\{
	Processable
};

/**
 * Enable cloning of a cpt entry.
 *
 * Adapted from Kinsta Duplicate posts and pages
 */
final class SaltusDuplicate implements Processable {

	/**
	 * @var string $name The name of the custom post type (CPT)
	 */
	private $name;

	/**
	 * @var string $label The label for duplicate link.
	 */
	private $label;

	/**
	 * @var string $attr_title The title for the duplicate link.
	 */
	private $attr_title;

	/**
	 * Constructor.
	 *
	 * @param string $name The name of the custom post type (CPT).
	 * @param array  $args Additional arguments.
	 *                     - 'label': The label for the duplicate link.
	 *                     - 'attr_title': The title for the duplicate link.
	 */
	public function __construct( string $name, array $args ) {
		$this->name       = $name;
		$this->label      = ! empty( $args['label'] ) ? $args['label'] : 'Duplicate';
		$this->attr_title = ! empty( $args['attr_title'] ) ? $args['attr_title'] : 'Duplicate this entry';
	}

	public function process() {

		// non hierarchical
		add_filter( 'post_row_actions', array( $this, 'row_link' ), 10, 2 );
		// if cpt is hierarchical
		add_filter( 'page_row_actions', array( $this, 'row_link' ), 10, 2 );

		add_action( 'admin_action_saltus_framework_' . $this->name . '_duplicate_post', array( $this, 'duplicate' ) );
	}

	/*
	* Add a duplicate link to action list for this cpt row_actions
	*
	* @param array $actions The actions for the row.
	* @param object $post The post object.
	*
	* @return array The modified actions.
	*
	*/
	public function row_link( $actions, $post ) {

		if ( $post->post_type !== $this->name ) {
			return $actions;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return $actions;
		}

		$actions['duplicate'] = sprintf(
			'<a href="%1$s" title="%2$s" rel="permalink">%3$s</a>',
			wp_nonce_url(
				'admin.php?action=saltus_framework_' . $this->name . '_duplicate_post&post=' . $post->ID,
				basename( __FILE__ ),
				'saltus_framework_duplicate_nonce'
			),
			esc_attr( $this->attr_title ),
			esc_html( $this->label )
		);
		return $actions;
	}

	public function duplicate() {

		$error_msg = esc_html__( 'Item cannot be found. Please select one to duplicate.', 'saltus-framework' );

		// Die if post not selected
		if ( ! ( isset( $_GET['post'] ) ||
				isset( $_POST['post'] ) ||
				( isset( $_REQUEST['action'] ) && $_REQUEST['action'] === 'saltus_framework_duplicate_post' )
				) ) {
			wp_die( esc_html__( 'Please select an item to duplicate.', 'saltus-framework' ) );
		}

		// Verify nonce
		if ( ! isset( $_GET['saltus_framework_duplicate_nonce'] ) ||
			! wp_verify_nonce( $_GET['saltus_framework_duplicate_nonce'], basename( __FILE__ ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			wp_die( $error_msg );
		}

		// Get id of post to be duplicated and data from it
		$post_id     = ( isset( $_GET['post'] ) ? absint( $_GET['post'] ) : absint( $_POST['post'] ) );
		$new_post_id_or_error = $this->perform_duplication( $post_id );

		if ( is_wp_error( $new_post_id_or_error ) ) {
			wp_die( $new_post_id_or_error );
		}

		// redirect to admin screen depending on post type
		$post_type = get_post_type( $post_id );

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . $post_type ) );
		exit;
	}

	/**
	 * Perform the actual duplication logic.
	 *
	 * @param int $post_id The ID of the post to duplicate.
	 * @return int|\WP_Error The new post ID on success, or WP_Error on failure.
	 */
	public function perform_duplication( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'duplicate_failed', __( 'Post not found.', 'saltus-framework' ) );
		}

		// Prepare Args (Add filter for title/status/etc)
		$args = array(
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
			'post_author'    => get_current_user_id() ?: $post->post_author,
			'post_content'   => $post->post_content,
			'post_excerpt'   => $post->post_excerpt,
			'post_name'      => $post->post_name,
			'post_parent'    => $post->post_parent,
			'post_password'  => $post->post_password,
			'post_status'    => 'draft',
			'post_title'     => $post->post_title,
			'post_type'      => $post->post_type,
			'to_ping'        => $post->to_ping,
			'menu_order'     => $post->menu_order,
		);

		$args = apply_filters( 'saltus/framework/duplicate_post/args', $args, $post_id );

		// insert the new post
		// @phpstan-ignore argument.type
		$new_post_id = wp_insert_post( $args, true );

		if ( is_wp_error( $new_post_id ) ) {
			return $new_post_id;
		}

		// Clone Taxonomies
		$taxonomies = get_object_taxonomies( $post->post_type );

		foreach ( $taxonomies as $taxonomy ) {
			$post_terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'slugs' ) );
			wp_set_object_terms( $new_post_id, $post_terms, $taxonomy, false );
		}

		// Clone Meta
		$all_meta = get_post_meta( $post_id );

		if ( ! empty( $all_meta ) ) {

			/**
			 * Filter the list of meta keys to exclude when duplicating a post.
			 *
			 * _edit_lock and _edit_last are set by wp_insert_post() itself, so
			 * copying them again would create duplicate rows with stale values.
			 *
			 * @param string[] $excluded_keys Meta keys that should not be copied.
			 */
			$excluded_keys = apply_filters(
				'saltus/framework/duplicate_post/excluded_meta_keys',
				[
					'_wp_old_slug',
					'_edit_lock',
					'_edit_last',
				]
			);

			foreach ( $all_meta as $meta_key => $meta_values ) {

				if ( in_array( $meta_key, $excluded_keys, true ) ) {
					continue;
				}

				foreach ( $meta_values as $meta_value ) {
					// get_post_meta() returns values already unserialized; update_post_meta()
					// will re-serialize them correctly when writing back.
					update_post_meta( $new_post_id, $meta_key, maybe_unserialize( $meta_value ) );
				}
			}
		}

		// Trigger Action
		do_action( 'saltus/framework/duplicate_post/after', $post->post_type, $post_id, $new_post_id );

		return $new_post_id;
	}
}
