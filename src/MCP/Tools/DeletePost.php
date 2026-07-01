<?php
namespace Saltus\WP\Framework\MCP\Tools;

/**
 * MCP tool to delete (trash or force delete) a post by ID.
 */
class DeletePost extends RestTool {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'delete_post';
	}

	/**
	 * Get the tool description for the AI.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Delete (trash or force delete) a post by ID';
	}

	/**
	 * Get the JSON Schema for tool parameters.
	 *
	 * @return array<string, mixed>
	 */
	public function get_parameters(): array {
		return [
			'post_id'   => [
				'type'        => 'number',
				'description' => 'The post ID to delete',
				'required'    => true,
			],
			'post_type' => [
				'type'        => 'string',
				'description' => 'The post type slug',
				'default'     => 'posts',
			],
			'force'     => [
				'type'        => 'boolean',
				'description' => 'Whether to force delete (skip trash)',
				'default'     => false,
			],
		];
	}

	/**
	 * Build the WP_REST_Request for deleting a post.
	 *
	 * @param array<string, mixed> $args
	 * @return \WP_REST_Request|null
	 */
	public function build_rest_request( array $args ): ?\WP_REST_Request {
		return $this->request(
			'DELETE',
			'/wp/v2/' . rawurlencode( $this->post_type_rest_base( (string) ( $args['post_type'] ?? 'posts' ) ) ) . '/' . (int) ( $args['post_id'] ?? 0 ),
			[ 'force' => ! empty( $args['force'] ) ]
		);
	}
}
