<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\MCP\Client\WordPressClient;

class ListPosts implements ToolInterface {
	public function get_name(): string {
		return 'list_posts';
	}

	public function get_description(): string {
		return 'Query posts from a Custom Post Type with optional filters';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_parameters(): array {
		return [
			'post_type' => [
				'type'        => 'string',
				'description' => 'The post type slug (e.g., "posts", "page", "product")',
				'default'     => 'posts',
			],
			'status'    => [
				'type'        => 'string',
				'description' => 'Post status filter (publish, draft, pending, private, trash, any)',
				'default'     => 'publish',
			],
			'search'    => [
				'type'        => 'string',
				'description' => 'Search term',
			],
			'per_page'  => [
				'type'        => 'number',
				'description' => 'Number of posts per page (max 100)',
				'default'     => 20,
			],
			'page'      => [
				'type'        => 'number',
				'description' => 'Page number',
				'default'     => 1,
			],
			'orderby'   => [
				'type'        => 'string',
				'description' => 'Sort field (date, title, id, modified, menu_order)',
				'default'     => 'date',
			],
			'order'     => [
				'type'        => 'string',
				'enum'        => [ 'asc', 'desc' ],
				'description' => 'Sort order',
				'default'     => 'desc',
			],
			'terms'     => [
				'type'                 => 'object',
				'description'          => 'Taxonomy term filters as {taxonomy_rest_base: [term_id, ...]}',
				'additionalProperties' => [
					'type'  => 'array',
					'items' => [ 'type' => 'number' ],
				],
			],
		];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- Query construction keeps REST parameters close to validation output.
	public function handle( array $args, WordPressClient $client ): array {
		$post_type = $args['post_type'] ?? 'posts';
		$query     = [
			'per_page' => min( $args['per_page'] ?? 20, 100 ),
			'page'     => $args['page'] ?? 1,
			'orderby'  => $args['orderby'] ?? 'date',
			'order'    => $args['order'] ?? 'desc',
		];

		if ( ! empty( $args['status'] ) && $args['status'] !== 'any' ) {
			$query['status'] = $args['status'];
		}

		if ( ! empty( $args['search'] ) ) {
			$query['search'] = $args['search'];
		}

		$query     = $this->append_term_filters( $query, $args['terms'] ?? [], $client );
		$rest_base = $this->get_rest_base( $post_type, $client );
		$posts     = $client->get( "wp/v2/{$rest_base}", $query );

		if ( isset( $posts['code'] ) ) {
			return $posts;
		}

		$formatted = array_map(
			function ( $post ) {
				return [
					'id'       => $post['id'] ?? 0,
					'title'    => $post['title']['rendered'] ?? '',
					'slug'     => $post['slug'] ?? '',
					'status'   => $post['status'] ?? '',
					'date'     => $post['date'] ?? '',
					'modified' => $post['modified'] ?? '',
					'type'     => $post['type'] ?? '',
				];
			},
			$posts
		);

		return [
			'posts' => $formatted,
			'total' => count( $formatted ),
		];
	}

	private function get_rest_base( string $post_type, WordPressClient $client ): string {
		if ( in_array( $post_type, [ 'posts', 'pages', 'media', 'users' ], true ) ) {
			return $post_type;
		}

		$types = $client->get( 'wp/v2/types', [ 'per_page' => 100 ] );
		foreach ( $types as $slug => $type ) {
			if ( is_array( $type ) && ( $slug === $post_type || ( $type['rest_base'] ?? '' ) === $post_type ) ) {
				return $type['rest_base'] ?? $slug;
			}
		}

		return $post_type;
	}

	/**
	 * @param array<string, mixed> $query
	 * @param mixed $terms
	 * @return array<string, mixed>
	 */
	private function append_term_filters( array $query, mixed $terms, WordPressClient $client ): array {
		if ( ! is_array( $terms ) ) {
			return $query;
		}

		$rest_bases = $this->get_taxonomy_rest_bases( $client );

		foreach ( $terms as $taxonomy => $term_ids ) {
			if ( ! is_string( $taxonomy ) || ! is_array( $term_ids ) ) {
				continue;
			}

			$ids = array_values( array_filter( array_map( 'intval', $term_ids ) ) );
			if ( $ids === [] ) {
				continue;
			}

			$query[ $rest_bases[ $taxonomy ] ?? $taxonomy ] = $ids;
		}

		return $query;
	}

	/**
	 * @return array<string, string>
	 */
	private function get_taxonomy_rest_bases( WordPressClient $client ): array {
		$taxonomies = $client->get( 'wp/v2/taxonomies' );
		$rest_bases = [];

		foreach ( $taxonomies as $slug => $taxonomy ) {
			if ( ! is_array( $taxonomy ) ) {
				continue;
			}

			$rest_base                = is_string( $taxonomy['rest_base'] ?? null ) ? $taxonomy['rest_base'] : $slug;
			$rest_bases[ $slug ]      = $rest_base;
			$rest_bases[ $rest_base ] = $rest_base;
		}

		return $rest_bases;
	}
}
