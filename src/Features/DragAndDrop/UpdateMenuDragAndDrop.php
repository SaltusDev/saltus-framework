<?php
namespace Saltus\WP\Framework\Features\DragAndDrop;

use Saltus\WP\Framework\Infrastructure\Service\{
	Actionable
};

/**
 */
class UpdateMenuDragAndDrop implements Actionable {

	/**
	 * Instantiate this Service object.
	 *
	 */
	public function __construct() {}

	public function add_action() {
		add_action( 'wp_ajax_dda-update-menu-order', array( $this, 'update_menu_order' ) );
	}

	public function update_menu_order() {
		global $wpdb;

		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'drag-drop-nonce' ) ) {
			return;
		}

		if ( empty( $_POST['order'] ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		parse_str( $_POST['order'], $data );

		if ( ! is_array( $data ) ) {
			return false;
		}

		$id_arr = array();
		foreach ( $data as $key => $values ) {
			foreach ( $values as $position => $id ) {
				$id_arr[] = $id;
			}
		}

		$menu_order_arr = array();
		foreach ( $id_arr as $key => $id ) {
			$query = "SELECT menu_order FROM $wpdb->posts WHERE ID = %s";
			// phpcs:: ignore
			$query_prepared = $wpdb->prepare( $query, intval( $id ) );
			// phpcs:: ignore
			$query_result = $wpdb->get_results( $query_prepared );

			foreach ( $query_result as $result ) {
				$menu_order_arr[] = $result->menu_order;
			}
		}

		sort( $menu_order_arr );

		foreach ( $data as $key => $values ) {
			foreach ( $values as $position => $id ) {
				$wpdb->update( $wpdb->posts, array( 'menu_order' => $menu_order_arr[ $position ] ), array( 'ID' => intval( $id ) ) );
			}
		}

		do_action( 'dda_update_menu_order' );
	}
}
