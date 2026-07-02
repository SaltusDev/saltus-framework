<?php
namespace Saltus\WP\Framework\MCP\Tools;

/**
 * MCP tool to retrieve a single post by ID with all fields and meta data.
 */
class GetPost extends RestTool {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'get_post';
	}

	/**
	 * Get the tool description for the AI.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Get a single post by ID with all fields and meta data';
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
				'description' => 'The post ID',
				'required'    => true,
			],
			'post_type' => [
				'type'        => 'string',
				'description' => 'The post type slug (defaults to "posts")',
				'default'     => 'posts',
			],
		];
	}

	/**
	 * Build the WP_REST_Request for retrieving a post.
	 *
	 * @param array<string, mixed> $args
	 * @return \WP_REST_Request|null
	 */
	public function build_rest_request( array $args ): ?\WP_REST_Request {
		return $this->request(
			'GET',
			'/wp/v2/' . rawurlencode( $this->post_type_rest_base( (string) ( $args['post_type'] ?? 'posts' ) ) ) . '/' . (int) ( $args['post_id'] ?? 0 )
		);
	}

	/**
	 * Whether responses from this tool can be cached.
	 *
	 * @return bool
	 */
	public function is_cacheable(): bool {
		return true;
	}
}
