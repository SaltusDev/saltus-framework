<?php

namespace Saltus\WP\Framework\WebMcp\Tools;

/**
 * Filter published content by taxonomy term.
 *
 * Also returns the archive permalink for the filtered view, so an agent can
 * hand the visitor a page rather than only a list of results.
 * @api
 */
final class FilterContent extends PublicTool {

	public function get_name(): string {
		return 'filter_content';
	}

	public function get_description(): string {
		return 'List published entries of one content type, optionally narrowed to a category or tag. Returns results plus a link to the matching page.';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_parameters(): array {
		return [
			'post_type' => [
				'type'        => 'string',
				'description' => 'The content type to list.',
				'required'    => true,
			],
			'taxonomy'  => [
				'type'        => 'string',
				'description' => 'Taxonomy to filter by, from list_taxonomy_terms.',
			],
			'term'      => [
				'type'        => 'string',
				'description' => 'Term slug to filter by.',
			],
			'orderby'   => [
				'type'        => 'string',
				'enum'        => [ 'date', 'title', 'menu_order' ],
				'description' => 'Sort order for results.',
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
		if ( $post_types === [] ) {
			return [
				'count'   => 0,
				'results' => [],
			];
		}

		$post_type = $post_types[0];
		$limit     = $this->resolve_limit( $args );
		$query     = array_merge(
			$this->base_query( [ $post_type ], $limit ),
			[
				'orderby' => $this->resolve_orderby( $args ),
				'order'   => 'DESC',
			]
		);

		$taxonomy = $this->resolve_taxonomy( $args, $post_type );
		$term     = isset( $args['term'] ) && is_string( $args['term'] ) ? trim( $args['term'] ) : '';

		if ( $taxonomy !== '' && $term !== '' ) {
			$query['tax_query'] = [
				[
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => [ $term ],
				],
			];
		}

		$results = [];
		foreach ( $this->query_posts( $query ) as $post ) {
			$results[] = $this->summarize( $post );
		}

		return [
			'post_type' => $post_type,
			'taxonomy'  => $taxonomy,
			'term'      => $term,
			'count'     => count( $results ),
			'results'   => $results,
			'page_url'  => $this->archive_url( $post_type, $taxonomy, $term ),
		];
	}

	/**
	 * Resolve a validated taxonomy for the post type.
	 *
	 * @param array<string, mixed> $args      Tool arguments.
	 * @param string               $post_type Post type slug.
	 */
	private function resolve_taxonomy( array $args, string $post_type ): string {
		$requested = isset( $args['taxonomy'] ) && is_string( $args['taxonomy'] ) ? trim( $args['taxonomy'] ) : '';
		if ( $requested === '' ) {
			return '';
		}

		return in_array( $requested, $this->public_taxonomies( $post_type ), true ) ? $requested : '';
	}

	/**
	 * Resolve a whitelisted sort column.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 */
	private function resolve_orderby( array $args ): string {
		$orderby = isset( $args['orderby'] ) && is_string( $args['orderby'] ) ? $args['orderby'] : 'date';

		return in_array( $orderby, [ 'date', 'title', 'menu_order' ], true ) ? $orderby : 'date';
	}

	/**
	 * Resolve the public archive URL for the filtered view.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $taxonomy  Taxonomy slug, or empty.
	 * @param string $term      Term slug, or empty.
	 */
	private function archive_url( string $post_type, string $taxonomy, string $term ): string {
		if ( $taxonomy !== '' && $term !== '' && function_exists( 'get_term_link' ) ) {
			$link = get_term_link( $term, $taxonomy );
			if ( is_string( $link ) && $link !== '' ) {
				return $link;
			}
		}

		if ( function_exists( 'get_post_type_archive_link' ) ) {
			$link = get_post_type_archive_link( $post_type );
			if ( is_string( $link ) && $link !== '' ) {
				return $link;
			}
		}

		return '';
	}
}
