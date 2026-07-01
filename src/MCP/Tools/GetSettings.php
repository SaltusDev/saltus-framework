<?php
namespace Saltus\WP\Framework\MCP\Tools;

class GetSettings implements ToolInterface {

	public function get_name(): string {
		return 'get_settings';
	}

	public function get_description(): string {
		return 'Get the Saltus Framework settings for a specific post type';
	}

	/**
	* @return array<string, mixed>
	*/
	public function get_parameters(): array {
		return [
			'post_type' => [
				'type'        => 'string',
				'description' => 'The post type slug to get settings for',
				'required'    => true,
			],
		];
	}
}
