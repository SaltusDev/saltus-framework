<?php
namespace Saltus\WP\Framework\Features\Duplicate;

/**
 * Enable cloning of a cpt entry.
 *
 * Adapted from Kinsta Duplicate posts and pages
 */
final class SaltusDuplicate {

	private $name;
	private $project;
	private $label;
	private $attr_title;

	/**
	 * Instantiate this Service object.
	 *
	 */
	public function __construct( string $name, array $project, array $args ) {
		$this->project    = $project;
		$this->name       = $name;
		$this->label      = ! empty( $args['label'] ) ? $args['label'] : 'Duplicate';
		$this->attr_title = ! empty( $args['attr_title'] ) ? $args['attr_title'] : 'Duplicate this entry';
		$this->register();
	}

	public function register() {

		// non hierarchical
		add_filter( 'post_row_actions', array( $this, 'row_link' ), 10, 2 );
		// if cpt is hierarchical
		add_filter( 'page_row_actions', array( $this, 'row_link' ), 10, 2 );

		add_action( 'admin_action_saltus_duplicate_post', array( $this, 'duplicate' ) );
	}

	/*
	* Add a duplicate link to action list for this cpt row_actions
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
				'admin.php?action=saltus_duplicate_post&post=' . $post->ID,
				basename( __FILE__ ),
				'saltus_duplicate_nonce'
			),
			esc_attr( $this->attr_title ),
			esc_html( $this->label )
		);
		return $actions;
	}

	public function duplicate() {

		global $wpdb;
		$error_msg = esc_html__( 'Item cannot be found. Please select one to duplicate.', 'saltus' );

		// Die if post not selected
		if ( ! ( isset( $_GET['post'] ) || isset( $_POST['post'] ) || ( isset( $_REQUEST['action'] ) && 'saltus_duplicate_post' === $_REQUEST['action'] ) ) ) {
			wp_die( esc_html__( 'Please select an item to duplicate.', 'saltus' ) );
		}

		// Verify nonce
		if ( ! isset( $_GET['saltus_duplicate_nonce'] ) || ! wp_verify_nonce( $_GET['saltus_duplicate_nonce'], basename( __FILE__ ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			wp_die( $error_msg );
		}

		// Get id of post to be duplicated and data from it
		$post_id = ( isset( $_GET['post'] ) ? absint( $_GET['post'] ) : absint( $_POST['post'] ) );
		$post    = get_post( $post_id );

		// duplicate the post
		if ( ! isset( $post ) || $post === null ) {
			return;
		}

		// args for new post
		$args = array(
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
			'post_author'    => $post->post_author,
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

		// insert the new post
		$new_post_id = wp_insert_post( $args );

		// add taxonomy terms to the new post
		// identify taxonomies that apply to the post type
		$taxonomies = get_object_taxonomies( $post->post_type );

		// add the taxonomy terms to the new post
		foreach ( $taxonomies as $taxonomy ) {

			$post_terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'slugs' ) );
			wp_set_object_terms( $new_post_id, $post_terms, $taxonomy, false );
		}

		// use SQL queries to duplicate postmeta
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$query_prepared = $wpdb->prepare( "SELECT meta_key, meta_value FROM $wpdb->postmeta WHERE post_id=%s", $post_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$query_result = $wpdb->get_results( $query_prepared );

		if ( count( $query_result ) === 0 ) {
			return;
		}

		$insert_query = "INSERT INTO $wpdb->postmeta ( post_id, meta_key, meta_value )";
		foreach ( $query_result as $post_meta ) {

			$meta_key = $post_meta->meta_key;

			if ( $meta_key === '_wp_old_slug' ) {
				continue;
			}
			$sql_query_sel[] = $wpdb->prepare( "SELECT %s, %s, %s", $new_post_id, $meta_key, $post_meta->meta_value);
		}

		$insert_query .= implode( ' UNION ALL ', $sql_query_sel );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $insert_query );

		// redirect to admin screen depending on post type
		$post_type = get_post_type( $post_id );

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . $post_type ) );

	}
}
