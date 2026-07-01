<?php
namespace Saltus\WP\Framework\MCP\Tools;

class CreatePost implements ToolInterface {

	public function get_name(): string {
		return 'create_post';
	}

	public function get_description(): string {
		return 'Create a new post in any registered Custom Post Type';
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
}
