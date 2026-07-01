<?php
namespace Saltus\WP\Framework\MCP\Tools;

class ListModels implements ToolInterface {

	public function get_name(): string {
		return 'list_models';
	}

	public function get_description(): string {
		return 'List all registered Custom Post Types and Taxonomies on the WordPress site';
	}

	/**
	* @return array<string, mixed>
	*/
	public function get_parameters(): array {
		return [
			'type' => [
				'type'        => 'string',
				'enum'        => [ 'post_types', 'taxonomies', 'all' ],
				'description' => 'Filter by type: post_types, taxonomies, or all (default)',
				'default'     => 'all',
			],
		];
	}
}
