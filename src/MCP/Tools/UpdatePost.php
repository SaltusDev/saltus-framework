<?php
namespace Saltus\WP\Framework\MCP\Tools;

class UpdatePost implements ToolInterface {

	public function get_name(): string {
		return 'update_post';
	}

	public function get_description(): string {
		return 'Update an existing post\'s fields and meta data';
	}

	/**
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
}
