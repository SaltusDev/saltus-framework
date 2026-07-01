<?php
namespace Saltus\WP\Framework\MCP\Tools;

class CreateTerm implements ToolInterface {

	public function get_name(): string {
		return 'create_term';
	}

	public function get_description(): string {
		return 'Create a new term in a taxonomy';
	}

	/**
	* @return array<string, mixed>
	*/
	public function get_parameters(): array {
		return [
			'taxonomy'    => [
				'type'        => 'string',
				'description' => 'The taxonomy slug (e.g., "categories", "tags")',
				'required'    => true,
			],
			'name'        => [
				'type'        => 'string',
				'description' => 'The term name',
				'required'    => true,
			],
			'slug'        => [
				'type'        => 'string',
				'description' => 'URL slug (auto-generated if not provided)',
			],
			'description' => [
				'type'        => 'string',
				'description' => 'Term description',
			],
			'parent'      => [
				'type'        => 'number',
				'description' => 'Parent term ID (for hierarchical taxonomies)',
			],
		];
	}
}
