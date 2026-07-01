<?php
namespace Saltus\WP\Framework\MCP\Tools;

class ListTerms implements ToolInterface {
	public function get_name(): string {
		return 'list_terms';
	}

	public function get_description(): string {
		return 'List terms from a taxonomy (categories, tags, or custom taxonomies)';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_parameters(): array {
		return [
			'taxonomy'   => [
				'type'        => 'string',
				'description' => 'The taxonomy slug (e.g., "categories", "tags", or custom)',
				'required'    => true,
			],
			'per_page'   => [
				'type'        => 'number',
				'description' => 'Number of terms per page (max 100)',
				'default'     => 50,
			],
			'search'     => [
				'type'        => 'string',
				'description' => 'Search term',
			],
			'hide_empty' => [
				'type'        => 'boolean',
				'description' => 'Whether to hide terms with no posts',
				'default'     => false,
			],
		];
	}
}
