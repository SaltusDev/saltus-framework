<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\Features\DragAndDrop\ReorderPostsService;
use Saltus\WP\Framework\Rest\ModelRestPolicy;

/**
 * MCP tool to reorder posts by updating their menu_order values.
 */
class ReorderPosts extends RestTool {

	private ReorderPostsService $reorder_service;

	/**
	 * @param ReorderPostsService|null $reorder_service Shared reorder service.
	 */
	public function __construct( ?ReorderPostsService $reorder_service = null ) {
		$this->reorder_service = $reorder_service ?? new ReorderPostsService();
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'reorder_posts';
	}

	/**
	 * Get the tool description for the AI.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Reorder multiple posts by updating their menu_order values in a single batch operation';
	}

	/**
	 * Get the JSON Schema for tool parameters.
	 *
	 * @return array<string, mixed>
	 */
	public function get_parameters(): array {
		return [
			'items' => [
				'type'        => 'array',
				'description' => 'Array of objects with "id" (post ID) and "menu_order" (integer position)',
				'required'    => true,
				'items'       => [
					'type'       => 'object',
					'properties' => [
						'id'         => [
							'type'        => 'number',
							'description' => 'The post ID',
						],
						'menu_order' => [
							'type'        => 'number',
							'description' => 'The new menu order position',
						],
					],
				],
			],
		];
	}

	/**
	 * Get the capability requirement for this tool.
	 *
	 * @return RestCapabilityRequirement|null
	 */
	public function get_rest_capability(): ?RestCapabilityRequirement {
		return new RestCapabilityRequirement( ModelRestPolicy::CAPABILITY_REORDER, 'post_type' );
	}

	/**
	 * Build the WP_REST_Request for reordering posts.
	 *
	 * @param array<string, mixed> $args
	 * @return \WP_REST_Request|null
	 */
	public function build_rest_request( array $args ): ?\WP_REST_Request {
		return $this->request( 'POST', $this->mcp_route( '/reorder' ), [], [ 'items' => $args['items'] ?? [] ] );
	}

	/**
	 * Reorder posts directly through the shared feature service.
	 *
	 * @param array<int, mixed> $items Requested reorder items.
	 * @param ModelRestPolicy|null $policy Optional REST policy.
	 * @return array{results: list<array<string, mixed>>, total: int, updated: int}
	 */
	public function reorder_posts( array $items, ?ModelRestPolicy $policy = null ): array {
		return $this->reorder_service->reorder( $items, $policy );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return bool
	 */
	public function has_permission( array $args ): bool {
		return current_user_can( 'edit_posts' );
	}
}
