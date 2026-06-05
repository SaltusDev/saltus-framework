<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\MCP\Client\WordPressClient;

class ListTerms implements ToolInterface
{
	public function getName(): string
	{
		return 'list_terms';
	}

	public function getDescription(): string
	{
		return 'List terms from a taxonomy (categories, tags, or custom taxonomies)';
	}

	public function getParameters(): array
	{
		return [
			'taxonomy' => [
				'type' => 'string',
				'description' => 'The taxonomy slug (e.g., "categories", "tags", or custom)',
				'required' => true,
			],
			'per_page' => [
				'type' => 'number',
				'description' => 'Number of terms per page (max 100)',
				'default' => 50,
			],
			'search' => [
				'type' => 'string',
				'description' => 'Search term',
			],
			'hide_empty' => [
				'type' => 'boolean',
				'description' => 'Whether to hide terms with no posts',
				'default' => false,
			],
		];
	}

	public function handle(array $args, WordPressClient $client): array
	{
		$taxonomy = $args['taxonomy'] ?? 'categories';

		$query = [
			'per_page' => min($args['per_page'] ?? 50, 100),
			'hide_empty' => !empty($args['hide_empty']),
		];

		if (!empty($args['search'])) {
			$query['search'] = $args['search'];
		}

		$terms = $client->get("wp/v2/{$taxonomy}", $query);

		if (isset($terms['code'])) {
			return $terms;
		}

		$formatted = array_map(function ($term) {
			return [
				'id' => $term['id'] ?? 0,
				'name' => $term['name'] ?? '',
				'slug' => $term['slug'] ?? '',
				'taxonomy' => $term['taxonomy'] ?? '',
				'count' => $term['count'] ?? 0,
				'description' => $term['description'] ?? '',
				'parent' => $term['parent'] ?? 0,
			];
		}, $terms);

		return [
			'terms' => $formatted,
			'total' => count($formatted),
		];
	}
}
