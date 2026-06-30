<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\MCP\Client\WordPressClient;

class ListModels implements ToolInterface {

	public function getName(): string {
		return 'list_models';
	}

	public function getDescription(): string {
		return 'List all registered Custom Post Types and Taxonomies on the WordPress site';
	}

	/**
	* @return array<string, mixed>
	*/
	public function getParameters(): array {
		return [
			'type' => [
				'type'        => 'string',
				'enum'        => [ 'post_types', 'taxonomies', 'all' ],
				'description' => 'Filter by type: post_types, taxonomies, or all (default)',
				'default'     => 'all',
			],
		];
	}

	/**
	* @param array<string, mixed> $args
	* @return array<string, mixed>
	*/
	public function handle( array $args, WordPressClient $client ): array {
		$type   = $args['type'] ?? 'all';
		$result = [];

		if ( $type === 'all' || $type === 'post_types' ) {
			$postTypes            = $client->get( 'wp/v2/types' );
			$result['post_types'] = $this->formatPostTypes( $postTypes );
		}

		if ( $type === 'all' || $type === 'taxonomies' ) {
			$taxonomies           = $client->get( 'wp/v2/taxonomies' );
			$result['taxonomies'] = $this->formatTaxonomies( $taxonomies );
		}

		return $result;
	}

	/**
	* @param array<string, mixed> $data
	 * @return list<array<string, mixed>>
	 */
	private function formatPostTypes( array $data ): array {
		$types = [];
		foreach ( $data as $slug => $type ) {
			if ( ! is_array( $type ) ) {
				continue;
			}
			$types[] = [
				'slug'         => $slug,
				'name'         => $type['name'] ?? $slug,
				'rest_base'    => $type['rest_base'] ?? $slug,
				'description'  => $type['description'] ?? '',
				'hierarchical' => $type['hierarchical'] ?? false,
				'public'       => $type['public'] ?? false,
			];
		}

		return $types;
	}

	/**
	* @param array<string, mixed> $data
	 * @return list<array<string, mixed>>
	 */
	private function formatTaxonomies( array $data ): array {
		$taxonomies = [];
		foreach ( $data as $slug => $tax ) {
			if ( ! is_array( $tax ) ) {
				continue;
			}
			$taxonomies[] = [
				'slug'         => $slug,
				'name'         => $tax['name'] ?? $slug,
				'rest_base'    => $tax['rest_base'] ?? $slug,
				'hierarchical' => $tax['hierarchical'] ?? false,
				'types'        => $tax['types'] ?? [],
			];
		}

		return $taxonomies;
	}
}
