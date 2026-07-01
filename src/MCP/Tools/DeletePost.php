<?php
namespace Saltus\WP\Framework\MCP\Tools;

class DeletePost implements ToolInterface {

	public function get_name(): string {
		return 'delete_post';
	}

	public function get_description(): string {
		return 'Delete (trash or force delete) a post by ID';
	}

	/**
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
}
