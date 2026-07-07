<?php
namespace Saltus\WP\Framework\MCP\Tools;

/**
 * MCP tool to query posts from a Custom Post Type with optional filters.
 */
class ListPosts extends RestTool {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'list_posts';
	}

	/**
	 * Get the tool description for the AI.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Query posts from a Custom Post Type with optional filters';
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
			'status'    => [
				'type'        => 'string',
				'description' => 'Post status filter (publish, draft, pending, private, trash, any)',
				'default'     => 'publish',
			],
			'search'    => [
				'type'        => 'string',
				'description' => 'Search term',
			],
			'per_page'  => [
				'type'        => 'number',
				'description' => 'Number of posts per page (max 100)',
				'default'     => 20,
			],
			'page'      => [
				'type'        => 'number',
				'description' => 'Page number',
				'default'     => 1,
			],
			'orderby'   => [
				'type'        => 'string',
				'description' => 'Sort field (date, title, id, modified, menu_order)',
				'default'     => 'date',
			],
			'order'     => [
				'type'        => 'string',
				'enum'        => [ 'asc', 'desc' ],
				'description' => 'Sort order',
				'default'     => 'desc',
			],
			'terms'     => [
				'type'                 => 'object',
				'description'          => 'Taxonomy term filters as {taxonomy_rest_base: [term_id, ...]}',
				'additionalProperties' => [
					'type'  => 'array',
					'items' => [ 'type' => 'number' ],
				],
			],
		];
	}

	/**
	 * Build the WP_REST_Request for querying posts.
	 *
	 * @param array<string, mixed> $args
	 * @return \WP_REST_Request|null
	 */
	public function build_rest_request( array $args ): ?\WP_REST_Request {
		$query = $this->only_args( $args, [ 'status', 'search', 'per_page', 'page', 'orderby', 'order' ] );
		$query = $this->append_term_filters( $query, $args['terms'] ?? [] );

		return $this->request( 'GET', '/wp/v2/' . rawurlencode( $this->post_type_rest_base( (string) ( $args['post_type'] ?? 'posts' ) ) ), $query );
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
