<?php

namespace Saltus\WP\Framework\Rest;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * REST controller for reordering posts via menu_order updates.
 */
class ReorderController extends WP_REST_Controller {

	private const ROUTE_NAMESPACE = 'saltus-framework/v1';
	private ?ModelRestPolicy $policy;

	/**
	 * @param ModelRestPolicy|null $policy  Optional REST policy for capability gating.
	 */
	public function __construct( ?ModelRestPolicy $policy = null ) {
		$this->policy    = $policy;
		$this->namespace = self::ROUTE_NAMESPACE;
		$this->rest_base = 'reorder';
	}

	/**
	 * Register the REST route for reordering posts.
	 */
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

	/**
	 * Check whether the current user can reorder posts.
	 *
	 * @param mixed $request  The REST request.
	 * @return WP_Error|true
	 */
	public function create_item_permissions_check( $request ): WP_Error|true {
		$items   = is_object( $request ) && method_exists( $request, 'get_param' ) ? $request->get_param( 'items' ) : null;
		$allowed = is_array( $items ) && $items !== []
			? $this->can_edit_any_requested_post( $items )
			: current_user_can( 'edit_posts' );

		if ( ! $allowed ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to reorder posts.', 'saltus-framework' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * Check whether the current user can edit at least one post in the request.
	 *
	 * @param array<int, mixed> $items  Requested reorder items.
	 * @return bool
	 */
	private function can_edit_any_requested_post( array $items ): bool {
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['id'] ) ) {
				continue;
			}

			$post_id = (int) $item['id'];
			if ( $post_id > 0 && get_post( $post_id ) && current_user_can( 'edit_post', $post_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reorder posts by updating their menu_order values.
	 *
	 * @param mixed $request  The REST request containing the items parameter.
	 * @return WP_REST_Response|WP_Error
	 */
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

			if ( $this->policy && ! $this->policy->is_post_enabled( $post_id, ModelRestPolicy::CAPABILITY_REORDER ) ) {
				$results[] = [
					'id'     => $post_id,
					'status' => 'skipped',
					'reason' => 'Reorder is not enabled for this post type',
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
