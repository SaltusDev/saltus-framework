<?php
namespace Saltus\WP\Framework\MCP\Tools;

class GetModel implements ToolInterface {

	public function get_name(): string {
		return 'get_model';
	}

	public function get_description(): string {
		return 'Get details of a specific Custom Post Type or Taxonomy by slug';
	}

	/**
	* @return array<string, mixed>
	*/
	public function get_parameters(): array {
		return [
			'slug' => [
				'type'        => 'string',
				'description' => 'The slug of the post type or taxonomy (e.g., "post", "page", "product")',
				'required'    => true,
			],
		];
	}
}
