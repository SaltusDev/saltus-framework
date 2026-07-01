<?php
namespace Saltus\WP\Framework\MCP\Tools;

class ListPosts implements ToolInterface {
	public function get_name(): string {
		return 'list_posts';
	}

	public function get_description(): string {
		return 'Query posts from a Custom Post Type with optional filters';
	}

	/**
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
}
