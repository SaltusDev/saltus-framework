<?php
namespace Saltus\WP\Framework\MCP\Tools;

class DuplicatePost implements ToolInterface {

	public function get_name(): string {
		return 'duplicate_post';
	}

	public function get_description(): string {
		return 'Duplicate a WordPress post, creating a copy with "(Copy)" appended to the title';
	}

	/**
	* @return array<string, mixed>
	*/
	public function get_parameters(): array {
		return [
			'post_id' => [
				'type'        => 'number',
				'description' => 'The ID of the post to duplicate',
				'required'    => true,
			],
		];
	}
}
