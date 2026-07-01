<?php
namespace Saltus\WP\Framework\MCP\Tools;

class GetMetaFields implements ToolInterface {

	public function get_name(): string {
		return 'get_meta_fields';
	}

	public function get_description(): string {
		return 'Get the meta field definitions for a post type as configured in the Saltus Framework model';
	}

	/**
	* @return array<string, mixed>
	*/
	public function get_parameters(): array {
		return [
			'post_type' => [
				'type'        => 'string',
				'description' => 'The post type slug to get meta fields for',
				'required'    => true,
			],
		];
	}
}
