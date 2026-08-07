<?php

namespace Saltus\WP\Framework\WebMcp\Tools;

/**
 * List the content types an agent can browse on this site.
 * @api
 */
final class ListContentModels extends PublicTool {

	public function get_name(): string {
		return 'list_content_models';
	}

	public function get_description(): string {
		return 'List the kinds of content published on this site, with the filters available for each. Call this first to learn what can be searched.';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_parameters(): array {
		return [];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function execute( array $args ): array {
		$models = [];

		foreach ( $this->policy->frontend_models() as $post_type ) {
			$models[] = [
				'post_type'  => $post_type,
				'label'      => $this->label( $post_type ),
				'taxonomies' => $this->public_taxonomies( $post_type ),
				'fields'     => $this->field_summary( $post_type ),
			];
		}

		return [
			'count'  => count( $models ),
			'models' => $models,
		];
	}

	/**
	 * Resolve a human-readable label for a post type.
	 *
	 * @param string $post_type Post type slug.
	 */
	private function label( string $post_type ): string {
		if ( ! function_exists( 'get_post_type_object' ) ) {
			return $post_type;
		}

		$object = get_post_type_object( $post_type );
		if ( ! is_object( $object ) ) {
			return $post_type;
		}

		$name = $object->labels->name ?? null;
		if ( is_string( $name ) && $name !== '' ) {
			return $name;
		}

		return $object->label !== '' ? $object->label : $post_type;
	}

	/**
	 * Summarize the public field paths and types for a post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return list<array{path: string, label: string, type: string}>
	 */
	private function field_summary( string $post_type ): array {
		$summary = [];

		foreach ( $this->fields->fields( $this->modeler, $post_type ) as $field ) {
			$summary[] = [
				'path'  => (string) ( $field['path'] ?? '' ),
				'label' => (string) ( $field['label'] ?? '' ),
				'type'  => (string) ( $field['type'] ?? 'string' ),
			];
		}

		return $summary;
	}
}
