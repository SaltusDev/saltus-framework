<?php
namespace Saltus\WP\Framework\MCP\Tools;

/**
 * MCP tool to update an existing post's fields and meta data.
 */
class UpdatePost extends RestTool {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'update_post';
	}

	/**
	 * Get the tool description for the AI.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Update an existing post\'s fields and meta data';
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
				'description' => 'The post ID to update',
				'required'    => true,
			],
			'post_type' => [
				'type'        => 'string',
				'description' => 'The post type slug',
				'default'     => 'posts',
			],
			'title'     => [
				'type'        => 'string',
				'description' => 'New post title',
			],
			'content'   => [
				'type'        => 'string',
				'description' => 'New post content (HTML or raw text)',
			],
			'excerpt'   => [
				'type'        => 'string',
				'description' => 'New post excerpt',
			],
			'slug'      => [
				'type'        => 'string',
				'description' => 'New URL slug',
			],
			'status'    => [
				'type'        => 'string',
				'enum'        => [ 'publish', 'draft', 'pending', 'private' ],
				'description' => 'New post status',
			],
			'meta'      => [
				'type'                 => 'object',
				'description'          => 'Meta fields to update as key-value pairs',
				'additionalProperties' => true,
			],
		];
	}

	/**
	 * Build the WP_REST_Request for updating a post.
	 *
	 * @param array<string, mixed> $args
	 * @return \WP_REST_Request|null
	 */
	public function build_rest_request( array $args ): ?\WP_REST_Request {
		$body = $this->only_args( $args, [ 'title', 'content', 'excerpt', 'slug', 'status', 'meta' ] );

		return $this->request(
			'PUT',
			'/wp/v2/' . rawurlencode( $this->post_type_rest_base( (string) ( $args['post_type'] ?? 'posts' ) ) ) . '/' . (int) ( $args['post_id'] ?? 0 ),
			[],
			$body
		);
	}
}
