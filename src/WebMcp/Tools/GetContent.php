<?php

namespace Saltus\WP\Framework\WebMcp\Tools;

/**
 * Retrieve one published entry with its public meta fields.
 * @api
 */
final class GetContent extends PublicTool {

	public function get_name(): string {
		return 'get_content';
	}

	public function get_description(): string {
		return 'Get the full details of one published entry by its id, including its public custom fields.';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_parameters(): array {
		return [
			'id' => [
				'type'        => 'integer',
				'description' => 'The entry id, as returned by search_content.',
				'required'    => true,
			],
		];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function execute( array $args ): array {
		$id = isset( $args['id'] ) ? (int) $args['id'] : 0;
		if ( $id <= 0 ) {
			return [ 'found' => false ];
		}

		$post = get_post( $id );
		if ( ! $this->is_public_post( $post ) ) {
			return [ 'found' => false ];
		}

		/** @var \WP_Post $post */
		$post_type = (string) $post->post_type;

		$entry            = $this->summarize( $post );
		$entry['content'] = trim( wp_strip_all_tags( (string) $post->post_content ) );
		$entry['fields']  = $this->fields->values( $this->modeler, $id, $post_type );
		$entry['terms']   = $this->terms( $id, $post_type );

		return [
			'found' => true,
			'entry' => $entry,
		];
	}

	/**
	 * Resolve public taxonomy terms assigned to a post.
	 *
	 * @param int    $post_id   Post id.
	 * @param string $post_type Post type slug.
	 * @return array<string, list<string>> Taxonomy slug mapped to term names.
	 */
	private function terms( int $post_id, string $post_type ): array {
		if ( ! function_exists( 'wp_get_object_terms' ) ) {
			return [];
		}

		$assigned = [];
		foreach ( $this->public_taxonomies( $post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $post_id, $taxonomy );
			if ( ! is_array( $terms ) ) {
				continue;
			}

			$names = [];
			foreach ( $terms as $term ) {
				$names[] = $term->name;
			}

			if ( $names !== [] ) {
				$assigned[ $taxonomy ] = $names;
			}
		}

		return $assigned;
	}
}
