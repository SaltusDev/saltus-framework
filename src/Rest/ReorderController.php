<?php

namespace Saltus\WP\Framework\Rest;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Saltus\WP\Framework\Features\DragAndDrop\ReorderPostsService;
use Saltus\WP\Framework\MCP\MCPConfig;

/**
 * REST controller for reordering posts via menu_order updates.
 * @api
 */
class ReorderController extends WP_REST_Controller {

	private ?ModelRestPolicy $policy;
	private ReorderPostsService $reorder_service;

	/**
	 * @param ModelRestPolicy|null $policy  Optional REST policy for capability gating.
	 * @param ReorderPostsService|null $reorder_service Optional reorder service.
	 */
	public function __construct( ?ModelRestPolicy $policy = null, ?ReorderPostsService $reorder_service = null ) {
		$this->policy          = $policy;
		$this->reorder_service = $reorder_service ?? new ReorderPostsService();
		$this->namespace       = MCPConfig::get_namespace();
		$this->rest_base       = 'reorder';
	}

	/**
	 * Register the REST route for reordering posts.
	 */
	public function register_routes(): void {
		/** @var non-falsy-string $namespace */
		$namespace = $this->namespace;
		register_rest_route(
			$namespace,
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
	public function create_item_permissions_check( $request ) {
		$items   = is_object( $request ) && method_exists( $request, 'get_param' ) ? $request->get_param( 'items' ) : null;
		$allowed = is_array( $items ) && $items !== []
			? $this->reorder_service->can_edit_any_requested_post( $items, $this->policy )
			: current_user_can( 'edit_posts' );

		if ( ! $allowed ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to reorder posts.', 'saltus-framework' ),
				[
					'status' => 403,
					'hint'   => __( "Assign edit_posts to your user, or ensure all requested posts are editable by the current user. Check that each post's post type has 'show_in_rest' configured under 'features' => [ 'drag_and_drop' => [ 'show_in_rest' => true ] ].", 'saltus-framework' ),
				]
			);
		}
		return true;
	}

	/**
	 * Reorder posts by updating their menu_order values.
	 *
	 * @param mixed $request  The REST request containing the items parameter.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$items = $request->get_param( 'items' );

		if ( ! is_array( $items ) || empty( $items ) ) {
			return new WP_Error(
				'rest_empty_data',
				__( 'No items provided.', 'saltus-framework' ),
				[ 'status' => 400 ]
			);
		}

		return rest_ensure_response( $this->reorder_service->reorder( $items, $this->policy ) );
	}
}
