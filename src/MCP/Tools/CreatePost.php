<?php
namespace Saltus\WP\Framework\MCP\Tools;

/**
 * MCP tool to create a new post in any registered Custom Post Type.
 */
class CreatePost extends RestTool {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'create_post';
	}

	/**
	 * Get the tool description for the AI.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Create a new post in any registered Custom Post Type';
	}

	/**
	 * Get the JSON Schema for tool parameters.
	 *
	 * @return array<string, mixed>
	 */
	public function get_parameters(): array {
		return [
			'post_type' => [
				'type'        => 'string',
				'description' => 'The post type slug (e.g., "posts", "page", "product")',
				'default'     => 'posts',
			],
			'title'     => [
				'type'        => 'string',
				'description' => 'The post title',
				'required'    => true,
			],
			'content'   => [
				'type'        => 'string',
				'description' => 'The post content (HTML or raw text)',
			],
			'excerpt'   => [
				'type'        => 'string',
				'description' => 'The post excerpt',
			],
			'slug'      => [
				'type'        => 'string',
				'description' => 'URL slug',
			],
			'status'    => [
				'type'        => 'string',
				'enum'        => [ 'publish', 'draft', 'pending', 'private' ],
				'description' => 'Post status',
				'default'     => 'draft',
			],
			'meta'      => [
				'type'                 => 'object',
				'description'          => 'Meta fields as key-value pairs',
				'additionalProperties' => true,
			],
			'terms'     => [
				'type'                 => 'object',
				'description'          => 'Taxonomy terms as {taxonomy: [term_id, ...]}',
				'additionalProperties' => [
					'type'  => 'array',
					'items' => [ 'type' => 'number' ],
				],
			],
		];
	}

	/**
	 * Build the WP_REST_Request for creating a post.
	 *
	 * @param array<string, mixed> $args
	 * @return \WP_REST_Request|null
	 */
	public function build_rest_request( array $args ): ?\WP_REST_Request {
		$body = $this->only_args( $args, [ 'title', 'content', 'excerpt', 'slug', 'status', 'meta' ] );
		$body = $this->append_term_filters( $body, $args['terms'] ?? [] );

		return $this->request( 'POST', '/wp/v2/' . rawurlencode( $this->post_type_rest_base( (string) ( $args['post_type'] ?? 'posts' ) ) ), [], $body );
	}
}
