<?php
namespace Saltus\WP\Framework\Features\DragAndDrop;

use Saltus\WP\Framework\Infrastructure\Feature\{
	EnqueueAssets
};

final class SaltusDragAndDrop implements EnqueueAssets {

	private $name;
	private $project;

		/**
	 * Instantiate this Service object.
	 *
	 */
	public function __construct( string $name, array $project, ...$args ) {
		$this->project = $project;
		$this->name    = $name;

		$this->register();

		$this->enqueue_assets();
	}

	public function enqueue_assets() {

		add_action( 'admin_init', array( $this, 'load_script_css' ) );

	}

	public function register() {

		add_action( 'admin_init', array( $this, 'refresh' ) );

		add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ) );
		add_filter( 'get_previous_post_where', array( $this, 'previous_post_where' ) );
		add_filter( 'get_previous_post_sort', array( $this, 'previous_post_sort' ) );
		add_filter( 'get_next_post_where', array( $this, 'next_post_where' ) );
		add_filter( 'get_next_post_sort', array( $this, 'next_post_sort' ) );
	}

	private function check_load_script_css() {
		$active = false;

		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['orderby'] ) || strstr( $_SERVER['REQUEST_URI'], 'action=edit' ) || strstr( $_SERVER['REQUEST_URI'], 'wp-admin/post-new.php' ) ) {
			return false;
		}

		if ( isset( $_GET['post_type'] ) &&
			! isset( $_GET['taxonomy'] ) &&
			$_GET['post_type'] === $this->name ) { // if custom post types
				return true;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $active;
	}

	public function load_script_css() {
		if ( ! $this->check_load_script_css() ) {
			return;
		}

		wp_enqueue_script( 'jquery' );
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_script( 'drag_drop_orderjs', $this->project['root_url'] . '/Feature/DragAndDrop/order.js', array( 'jquery' ), '1', true );
		wp_localize_script(
			'drag_drop_orderjs',
			'drag_drop_object',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'drag-drop-nonce' ),
			)
		);

		wp_enqueue_style( 'drag_drop_order', $this->project['root_url'] . '/Feature/DragAndDrop/order.css', array(), '1' );

	}

	public function previous_post_where( $where ) {
		global $post;

		if ( isset( $post->post_type ) && $post->post_type === $this->name ) {
			$where = preg_replace( "/p.post_date < \'[0-9\-\s\:]+\'/i", "p.menu_order > '" . $post->menu_order . "'", $where );
		}
		return $where;
	}

	public function previous_post_sort( $orderby ) {
		global $post;

		if ( isset( $post->post_type ) && $post->post_type === $this->name ) {
			$orderby = 'ORDER BY p.menu_order ASC LIMIT 1';
		}
		return $orderby;
	}

	public function next_post_where( $where ) {
		global $post;

		if ( isset( $post->post_type ) && $post->post_type === $this->name ) {
			$where = preg_replace( "/p.post_date > \'[0-9\-\s\:]+\'/i", "p.menu_order < '" . $post->menu_order . "'", $where );
		}
		return $where;
	}

	public function next_post_sort( $orderby ) {
		global $post;

		if ( isset( $post->post_type ) && $post->post_type === $this->name ) {
			$orderby = 'ORDER BY p.menu_order DESC LIMIT 1';
		}
		return $orderby;
	}

	public function refresh() {
		global $wpdb;

		$query = "SELECT count(*) as cnt, max(menu_order) as max, min(menu_order) as min
			FROM {$wpdb->posts}
			WHERE post_type = %s AND post_status IN ('publish', 'pending', 'draft', 'private', 'future')
		";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$query_prepared = $wpdb->prepare( $query, $this->name );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$query_result = $wpdb->get_results( $query_prepared );

		if ( empty( $query_result ) || $query_result[0]->cnt === 0 || $query_result[0]->cnt === $query_result[0]->max ) {
			return;
		}

		// Here's the optimization
		$wpdb->query( 'SET @row_number = 0;' );
		$query = "UPDATE $wpdb->posts as pt JOIN (
			SELECT ID, (@row_number:=@row_number + 1) AS `rank`
			FROM {$wpdb->posts}
			WHERE post_type = %s AND post_status IN ( 'publish', 'pending', 'draft', 'private', 'future' )
			ORDER BY menu_order ASC
			) as pt2
			ON pt.id = pt2.id
			SET pt.menu_order = pt2.`rank`;
		";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$query_prepared = $wpdb->prepare( $query, $this->name );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$query_result = $wpdb->query( $query_prepared );

	}

	public function pre_get_posts( $wp_query ) {

		if ( ! isset( $wp_query->query['post_type'] ) ) {
			return;
		}

		if ( is_admin() ) {
			// skip if its already being sorted
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['orderby'] ) ) {
				return;
			}
			// skip if its another CPT
			if ( $wp_query->query['post_type'] !== $this->name ) {
				return;
			}
			$wp_query->set( 'orderby', 'menu_order' );
			$wp_query->set( 'order', 'ASC' );
			return;
		}

		$active = false;

		if ( is_array( $wp_query->query['post_type'] ) &&
			in_array( $this->name, $wp_query->query['post_type'], true ) ) {
			$active = true;
		}
		if ( $this->name === $wp_query->query['post_type'] ) {
			$active = true;
		}

		if ( ! $active ) {
			return false;
		}

		if ( isset( $wp_query->query['suppress_filters'] ) ) {
			if ( $wp_query->get( 'orderby' ) === 'date' ) {
				$wp_query->set( 'orderby', 'menu_order' );
			}
			if ( $wp_query->get( 'order' ) === 'DESC' ) {
				$wp_query->set( 'order', 'ASC' );
			}
			return;
		}

		if ( ! $wp_query->get( 'orderby' ) ) {
			$wp_query->set( 'orderby', 'menu_order' );
		}
		if ( ! $wp_query->get( 'order' ) ) {
			$wp_query->set( 'order', 'ASC' );
		}
	}
}
