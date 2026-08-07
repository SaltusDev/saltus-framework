<?php

namespace Saltus\WP\Framework\WebMcp\Tools;

/**
 * Search published content across WebMCP-enabled post type models.
 * @api
 */
final class SearchContent extends PublicTool {

	public function get_name(): string {
		return 'search_content';
	}

	public function get_description(): string {
		return 'Search published content on this site by keyword. Returns matching entries with their title, excerpt, and link.';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_parameters(): array {
		return [
			'query'     => [
				'type'        => 'string',
				'description' => 'Words to search for.',
				'required'    => true,
			],
			'post_type' => [
				'type'        => 'string',
				'description' => 'Restrict results to one content type.',
			],
			'limit'     => [
				'type'        => 'integer',
				'description' => 'Maximum results, up to ' . self::MAX_RESULTS . '.',
			],
		];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function execute( array $args ): array {
		$post_types = $this->resolve_post_types( $args );
		$query      = isset( $args['query'] ) && is_string( $args['query'] ) ? trim( $args['query'] ) : '';

		if ( $post_types === [] || $query === '' ) {
			return [
				'query'   => $query,
				'count'   => 0,
				'results' => [],
			];
		}

		$limit = $this->resolve_limit( $args );
		$posts = $this->query_posts(
			array_merge(
				$this->base_query( $post_types, $limit ),
				[
					's'       => $query,
					'orderby' => 'relevance',
				]
			)
		);

		$results = [];
		foreach ( $posts as $post ) {
			$results[] = $this->summarize( $post );
		}

		return [
			'query'   => $query,
			'count'   => count( $results ),
			'results' => $results,
		];
	}
}
