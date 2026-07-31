<?php
namespace Saltus\WP\Framework\Features\WpCli\Commands;

final class TermCommand extends AbstractCommand {
	/**
	 * @param list<string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function list( array $args, array $assoc_args ): void {
		$taxonomy = sanitize_key( $this->argument( $args, 0, 'taxonomy' ) );
		$terms    = get_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => ! empty( $assoc_args['hide-empty'] ),
			'search'     => sanitize_text_field( (string) ( $assoc_args['search'] ?? '' ) ),
		] );
		$terms    = $this->ensure_result( $terms );
		$rows     = [];
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			if ( $term instanceof \WP_Term ) {
				$rows[] = [
					'term_id' => $term->term_id,
					'name'    => $term->name,
					'slug'    => $term->slug,
					'count'   => $term->count,
				];
			}
		}
		$this->cli->format_items( $this->format( $assoc_args ), $rows, [ 'term_id', 'name', 'slug', 'count' ] );
	}

	/**
	 * @param list<string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function create( array $args, array $assoc_args ): void {
		$taxonomy = sanitize_key( $this->argument( $args, 0, 'taxonomy' ) );
		$name     = sanitize_text_field( $this->argument( $args, 1, 'name' ) );
		$result   = wp_insert_term(
			$name,
			$taxonomy,
			[
				'slug'        => sanitize_title( (string) ( $assoc_args['slug'] ?? '' ) ),
				'description' => sanitize_textarea_field( (string) ( $assoc_args['description'] ?? '' ) ),
				'parent'      => max( 0, (int) ( $assoc_args['parent'] ?? 0 ) ),
			]
		);
		$result   = $this->ensure_result( $result );
		$term_id  = is_array( $result ) ? (int) ( $result['term_id'] ?? 0 ) : 0;
		$this->cli->success( 'Created term ' . $term_id . '.' );
	}
}
