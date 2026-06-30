<?php

namespace Saltus\WP\Framework\Rest;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class ReorderController extends WP_REST_Controller {

	private const ROUTE_NAMESPACE = 'saltus-framework/v1';

	public function __construct() {
		$this->namespace = self::ROUTE_NAMESPACE;
		$this->rest_base = 'reorder';
	}

	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/' . $this->rest_base,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => [ $this, 'create_item_permissions_check' ],
				'args'                => [
					'items' => [
						'type'        => 'array',
						'required'    => true,
						'description' => 'Array of {id, menu_order} objects',
						'items'       => [
							'type'       => 'object',
							'required'   => [ 'id', 'menu_order' ],
							'properties' => [
								'id'         => [
									'type'     => 'integer',
									'required' => true,
								],
								'menu_order' => [
									'type'     => 'integer',
									'required' => true,
								],
							],
						],
					],
				],
			]
		);
	}

	public function create_item_permissions_check( $request ): WP_Error|true {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to reorder posts.', 'saltus-framework' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	public function create_item( $request ): WP_REST_Response|WP_Error {
		$items = $request->get_param( 'items' );

		if ( ! is_array( $items ) || empty( $items ) ) {
			return new WP_Error(
				'rest_empty_data',
				__( 'No items provided.', 'saltus-framework' ),
				[ 'status' => 400 ]
			);
		}

		$results = [];

		foreach ( $items as $item ) {
			$post_id    = (int) $item['id'];
			$menu_order = (int) $item['menu_order'];

			if ( ! get_post( $post_id ) ) {
				$results[] = [
					'id'     => $post_id,
					'status' => 'skipped',
					'reason' => 'Post not found',
				];
				continue;
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				$results[] = [
					'id'     => $post_id,
					'status' => 'skipped',
					'reason' => 'Permission denied',
				];
				continue;
			}

			$updated = wp_update_post(
				[
					'ID'         => $post_id,
					'menu_order' => $menu_order,
				],
				true
			);

			if ( is_wp_error( $updated ) ) {
				$results[] = [
					'id'     => $post_id,
					'status' => 'error',
					'reason' => $updated->get_error_message(),
				];
			} else {
				$results[] = [
					'id'         => $post_id,
					'menu_order' => $menu_order,
					'status'     => 'updated',
				];
			}
		}

		return rest_ensure_response(
			[
				'results' => $results,
				'total'   => count( $results ),
				'updated' => count( array_filter( $results, fn( $r ) => $r['status'] === 'updated' ) ),
			]
		);
	}
}
