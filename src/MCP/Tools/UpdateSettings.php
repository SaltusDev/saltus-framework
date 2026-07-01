<?php
namespace Saltus\WP\Framework\MCP\Tools;

class UpdateSettings implements ToolInterface {

	public function get_name(): string {
		return 'update_settings';
	}

	public function get_description(): string {
		return 'Update the Saltus Framework settings for a specific post type';
	}

	/**
	* @return array<string, mixed>
	*/
	public function get_parameters(): array {
		return [
			'post_type' => [
				'type'        => 'string',
				'description' => 'The post type slug to update settings for',
				'required'    => true,
			],
			'settings'  => [
				'type'        => 'object',
				'description' => 'The settings data to update (key-value pairs)',
				'required'    => true,
			],
		];
	}
}
