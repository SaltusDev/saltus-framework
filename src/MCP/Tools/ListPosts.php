<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\MCP\Client\WordPressClient;

class ListPosts implements ToolInterface
{
	public function getName(): string
	{
		return 'list_posts';
	}

	public function getDescription(): string
	{
		return 'Query posts from a Custom Post Type with optional filters';
	}

	/**
	* @return array<string, mixed>
	*/
	public function getParameters(): array
	{
		return [
			'post_type' => [
				'type' => 'string',
				'description' => 'The post type slug (e.g., "posts", "page", "product")',
				'default' => 'posts',
			],
			'status' => [
				'type' => 'string',
				'description' => 'Post status filter (publish, draft, pending, private, trash, any)',
				'default' => 'publish',
			],
			'search' => [
				'type' => 'string',
				'description' => 'Search term',
			],
			'per_page' => [
				'type' => 'number',
				'description' => 'Number of posts per page (max 100)',
				'default' => 20,
			],
			'page' => [
				'type' => 'number',
				'description' => 'Page number',
				'default' => 1,
			],
			'orderby' => [
				'type' => 'string',
				'description' => 'Sort field (date, title, id, modified, menu_order)',
				'default' => 'date',
			],
			'order' => [
				'type' => 'string',
				'enum' => ['asc', 'desc'],
				'description' => 'Sort order',
				'default' => 'desc',
			],
			'terms' => [
				'type' => 'object',
				'description' => 'Taxonomy term filters as {taxonomy_rest_base: [term_id, ...]}',
				'additionalProperties' => [
					'type' => 'array',
					'items' => ['type' => 'number'],
				],
			],
		];
	}

	/**
	* @param array<string, mixed> $args
	* @return array<string, mixed>
	*/
	public function handle(array $args, WordPressClient $client): array
	{
		$postType = $args['post_type'] ?? 'posts';
		$query = [
			'per_page' => min($args['per_page'] ?? 20, 100),
			'page' => $args['page'] ?? 1,
			'orderby' => $args['orderby'] ?? 'date',
			'order' => $args['order'] ?? 'desc',
		];

		if (!empty($args['status']) && $args['status'] !== 'any') {
			$query['status'] = $args['status'];
		}

		if (!empty($args['search'])) {
			$query['search'] = $args['search'];
		}

		$query = $this->appendTermFilters($query, $args['terms'] ?? [], $client);

		$restBase = $this->getRestBase($postType, $client);
		$posts = $client->get("wp/v2/{$restBase}", $query);

		if (isset($posts['code'])) {
			return $posts;
		}

		$formatted = array_map(function ($post) {
			return [
				'id' => $post['id'] ?? 0,
				'title' => $post['title']['rendered'] ?? '',
				'slug' => $post['slug'] ?? '',
				'status' => $post['status'] ?? '',
				'date' => $post['date'] ?? '',
				'modified' => $post['modified'] ?? '',
				'type' => $post['type'] ?? '',
			];
		}, $posts);

		return [
			'posts' => $formatted,
			'total' => count($formatted),
		];
	}

	private function getRestBase(string $postType, WordPressClient $client): string
	{
		if (in_array($postType, ['posts', 'pages', 'media', 'users'], true)) {
			return $postType;
		}

		$types = $client->get('wp/v2/types', ['per_page' => 100]);
		foreach ($types as $slug => $type) {
			if (is_array($type) && ($slug === $postType || ($type['rest_base'] ?? '') === $postType)) {
				return $type['rest_base'] ?? $slug;
			}
		}

		return $postType;
	}

	/**
	* @param array<string, mixed> $query
	* @param mixed $terms
	* @return array<string, mixed>
	*/
	private function appendTermFilters(array $query, mixed $terms, WordPressClient $client): array
	{
		if (!is_array($terms)) {
			return $query;
		}

		$restBases = $this->getTaxonomyRestBases($client);

		foreach ($terms as $taxonomy => $termIds) {
			if (!is_string($taxonomy) || !is_array($termIds)) {
				continue;
			}

			$ids = array_values(array_filter(array_map('intval', $termIds)));
			if ($ids === []) {
				continue;
			}

			$query[$restBases[$taxonomy] ?? $taxonomy] = $ids;
		}

		return $query;
	}

	/**
	* @return array<string, string>
	*/
	private function getTaxonomyRestBases(WordPressClient $client): array
	{
		$taxonomies = $client->get('wp/v2/taxonomies');
		$restBases = [];

		foreach ($taxonomies as $slug => $taxonomy) {
			if (!is_array($taxonomy)) {
				continue;
			}

			$restBase = is_string($taxonomy['rest_base'] ?? null) ? $taxonomy['rest_base'] : $slug;
			$restBases[$slug] = $restBase;
			$restBases[$restBase] = $restBase;
		}

		return $restBases;
	}
}
