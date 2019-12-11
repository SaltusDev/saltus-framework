<?php
namespace Saltus\WP\Framework\Features\DragAndDrop;

use Saltus\WP\Framework\Infrastructure\Feature\{
	Feature,
	EnqueueAssets,
};

final class CustomTypeDragAndDrop implements EnqueueAssets {

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
		// TODO
		add_action( 'wp_ajax_update-menu-order', array( $this, 'update_menu_order' ) );

		//done
		add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ) );
		add_filter( 'get_previous_post_where', array( $this, 'previous_post_where' ) );
		add_filter( 'get_previous_post_sort', array( $this, 'previous_post_sort' ) );
		add_filter( 'get_next_post_where', array( $this, 'next_post_where' ) );
		add_filter( 'get_next_post_sort', array( $this, 'next_post_sort' ) );

	}
	private function check_load_script_css() {
		$active = false;

		if ( isset( $_GET['orderby'] ) || strstr( $_SERVER['REQUEST_URI'], 'action=edit' ) || strstr( $_SERVER['REQUEST_URI'], 'wp-admin/post-new.php' ) ) {
			return false;
		}

		if ( isset( $_GET['post_type'] ) &&
			! isset( $_GET['taxonomy'] ) &&
			$_GET['post_type'] === $this->name ) { // if custom post types
				return true;
		}

		return $active;
	}

	public function load_script_css() {
		if ( ! $this->check_load_script_css() ) {
			return;
		}

		wp_enqueue_script( 'jquery' );
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_script( 'drag_drop_orderjs', $this->project['root_url'] . '/Feature/DragAndDrop/order.js', array( 'jquery' ), '1', true );

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

		$objects = $this->get_options_objects();
		if ( empty( $objects ) ) {
			return $where;
		}

		if ( isset( $post->post_type ) && $post->post_type === $this->name ) {
			$where = preg_replace( "/p.post_date > \'[0-9\-\s\:]+\'/i", "p.menu_order < '" . $post->menu_order . "'", $where );
		}
		return $where;
	}

	public function next_post_sort( $orderby ) {
		global $post;

		$objects = $this->get_options_objects();
		if ( empty( $objects ) ) {
			return $orderby;
		}

		if ( isset( $post->post_type ) && $post->post_type === $this->name ) {
			$orderby = 'ORDER BY p.menu_order DESC LIMIT 1';
		}
		return $orderby;
	}
	public function update_menu_order() {
		global $wpdb;

		parse_str( $_POST['order'], $data );

		if ( ! is_array( $data ) ) {
			return false;
		}

		$id_arr = array();
		// ugh could have duplicated?!
		foreach ( $data as $key => $values ) {
			foreach ( $values as $position => $id ) {
				$id_arr[] = $id;
			}
		}

		// remove duplicated
		$id_arr = array_merge( ...array_values( $data ) );

		// sanitize to int
		$id_arr = array_map( 'intval', $id_arr );

		// convert to string 1,2,3 etc
		$id_list = implode( $id_arr );

		$prepare = $wpdb->prepare(
			'SELECT menu_order FROM $wpdb->posts WHERE ID in ( %s )',
			$id_list
		);
		$results = $wpdb->get_results( $prepare );

		$menu_order_arr = array();
		foreach ( $results as $result ) {
			$menu_order_arr[] = $result->menu_order;
		}

		sort( $menu_order_arr );

		foreach ( $data as $key => $values ) {
			foreach ( $values as $position => $id ) {
				$wpdb->update(
					$wpdb->posts, // table
					array( 'menu_order' => intval( $menu_order_arr[ $position ] ) ), // data
					array( 'ID' => intval( $id ) ) // where clause
				);
			}
		}
	}

	public function refresh() {

	}

	public function pre_get_posts( $wp_query ) {

		if ( ! isset( $wp_query->query['post_type'] ) ) {
			return;
		}

		if ( is_admin() ) {
			// skip if its already being sorted
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



