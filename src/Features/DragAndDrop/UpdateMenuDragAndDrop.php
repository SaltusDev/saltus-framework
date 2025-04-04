<?php
namespace Saltus\WP\Framework\Features\DragAndDrop;

use Saltus\WP\Framework\Infrastructure\Service\{
	Actionable
};

/**
 * Class UpdateMenuDragAndDrop
 *
 * Handles the drag-and-drop functionality for updating menu order.
 */
class UpdateMenuDragAndDrop implements Actionable {

	/**
	 * Instantiate this Service object.
	 *
	 */
	public function __construct() {}


	/**
	 * Register the WordPress action for handling the AJAX request.
	 */
	public function add_action() {
		add_action( 'wp_ajax_saltus-framwork-drop-and-drag-update-menu-order', array( $this, 'update_menu_order' ) );
	}

	/**
	 * Handle the AJAX request to update the menu order.
	 *
	 * Validates the nonce, checks user permissions, and updates the menu order
	 * in the database based on the provided data.
	 */
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

		// can't trust much parse_str
		parse_str( $_POST['order'], $data );

		$id_arr = array();
		foreach ( $data as $id_sorted ) {
			foreach ( $id_sorted as $position => $id ) {
				$id_arr[ absint( $position ) ] = absint( $id );
			}
		}

		// Deals with paginated view
		$id_list        = implode( ',', array_map( 'absint', $id_arr ) );
		$query          = "SELECT menu_order FROM $wpdb->posts WHERE ID IN (%s)";
		$query_prepared = sprintf( $query, $id_list );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$query_result    = $wpdb->get_results( $query_prepared );
		$menu_order_list = [];
		foreach ( $query_result as $result ) {
			$menu_order_list[] = $result->menu_order;
		}

		sort( $menu_order_list );

		// This should be just one request using query()
		foreach ( $id_arr as $position => $id ) {
			if ( ! isset( $menu_order_list[ $position ] ) ) {
				continue;
			}
			$data  = array( 'menu_order' => $menu_order_list[ $position ] );
			$where = array( 'ID' => absint( $id ) );
			$wpdb->update(
				$wpdb->posts,
				$data,
				$where
			);
		}

		do_action( 'saltus/dad/update_menu_order' );
	}
}
