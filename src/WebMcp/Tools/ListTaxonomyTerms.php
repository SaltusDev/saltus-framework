<?php

namespace Saltus\WP\Framework\WebMcp\Tools;

/**
 * List the terms available in a public taxonomy.
 * @api
 */
final class ListTaxonomyTerms extends PublicTool {

	/** Maximum terms returned by any single call. */
	private const MAX_TERMS = 100;

	public function get_name(): string {
		return 'list_taxonomy_terms';
	}

	public function get_description(): string {
		return 'List the categories or tags available for a content type, so results can be filtered by one of them.';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_parameters(): array {
		return [
			'post_type' => [
				'type'        => 'string',
				'description' => 'The content type whose taxonomies to list.',
				'required'    => true,
			],
			'taxonomy'  => [
				'type'        => 'string',
				'description' => 'Restrict output to one taxonomy.',
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
				'count'      => 0,
				'taxonomies' => [],
			];
		}

		$post_type = $post_types[0];
		$requested = isset( $args['taxonomy'] ) && is_string( $args['taxonomy'] ) ? $args['taxonomy'] : '';
		$available = $this->public_taxonomies( $post_type );

		if ( $requested !== '' ) {
			$available = in_array( $requested, $available, true ) ? [ $requested ] : [];
		}

		$taxonomies = [];
		foreach ( $available as $taxonomy ) {
			$taxonomies[] = [
				'taxonomy' => $taxonomy,
				'terms'    => $this->terms( $taxonomy ),
			];
		}

		return [
			'post_type'  => $post_type,
			'count'      => count( $taxonomies ),
			'taxonomies' => $taxonomies,
		];
	}

	/**
	 * Resolve the terms in a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return list<array{id: int, name: string, slug: string, count: int}>
	 */
	private function terms( string $taxonomy ): array {
		$results = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'number'     => self::MAX_TERMS,
			]
		);

		if ( ! is_array( $results ) ) {
			return [];
		}

		$terms = [];
		foreach ( $results as $term ) {
			$terms[] = [
				'id'    => $term->term_id,
				'name'  => $term->name,
				'slug'  => $term->slug,
				'count' => $term->count,
			];
		}

		return $terms;
	}
}
