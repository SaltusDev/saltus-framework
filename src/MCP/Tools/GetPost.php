<?php
namespace Saltus\WP\Framework\MCP\Tools;

class GetPost implements ToolInterface {

	public function get_name(): string {
		return 'get_post';
	}

	public function get_description(): string {
		return 'Get a single post by ID with all fields and meta data';
	}

	/**
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
}
