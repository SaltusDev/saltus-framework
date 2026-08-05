<?php
namespace Saltus\WP\Framework\Features\Frontend;

use Saltus\WP\Framework\Features\Meta\MetaFieldProvider;
use Saltus\WP\Framework\Models\Model;

/** Renders frontend shortcode views for one post type model. */
final class FrontendRenderer {

	private string $post_type;
	/** @var array<string, mixed> */
	private array $project;
	/** @var array<string, mixed> */
	private array $config;
	private MetaFieldProvider $meta_field_provider;
	private ?Model $model;

	/**
	 * @param array<string, mixed> $project Project data.
	 * @param array<string, mixed> $config Frontend configuration.
	 */
	public function __construct(
		string $post_type,
		array $project = [],
		array $config = [],
		?MetaFieldProvider $meta_field_provider = null,
		?Model $model = null
	) {
		$this->post_type           = $post_type;
		$this->project             = $project;
		$this->config              = $config;
		$this->meta_field_provider = $meta_field_provider ?? new MetaFieldProvider();
		$this->model               = $model;
	}

	/** @param array<string, mixed> $attributes */
	public function render( array $attributes ): string {
		$view = (string) ( $attributes['view'] ?? 'list' );
		if ( $view === 'single' ) {
			return $this->render_single( $attributes );
		}
		return $this->render_list( $attributes );
	}

	/** @param array<string, mixed> $attributes */
	private function render_list( array $attributes ): string {
		$attributes = $this->normalize_list_attributes( $attributes );
		$query_args = [
			'post_type'      => $this->post_type,
			'post_status'    => 'publish',
			'posts_per_page' => $attributes['limit'],
			'orderby'        => $attributes['orderby'],
			'order'          => $attributes['order'],
		];
		if ( $attributes['taxonomy'] !== '' && $attributes['terms'] !== [] && $this->taxonomy_is_valid( $attributes['taxonomy'] ) ) {
			$query_args['tax_query'] = [
				[
					'taxonomy' => $attributes['taxonomy'],
					'field'    => 'slug',
					'terms'    => $attributes['terms'],
				],
			];
		}
		$query_args = apply_filters( 'saltus/framework/frontend/query_args', $query_args, $this->post_type, $attributes );
		$query      = new \WP_Query( is_array( $query_args ) ? $query_args : [] );
		$posts      = is_array( $query->posts ) ? $query->posts : [];
		$fields     = $this->fields();
		$meta       = [];
		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$meta[ $post->ID ] = $this->meta_values( $post->ID, $fields );
			}
		}
		return $this->render_template(
			'list',
			[
				'posts'        => $posts,
				'meta_by_post' => $meta,
				'model'        => $this->model,
				'attributes'   => $attributes,
			]
		);
	}

	/** @param array<string, mixed> $attributes */
	private function render_single( array $attributes ): string {
		$post_id = absint( $attributes['id'] ?? 0 );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || $post->post_type !== $this->post_type || $post->post_status !== 'publish' ) {
			return '';
		}
		$normalized = [
			'view' => 'single',
			'id'   => $post_id,
		];
		$variables  = [
			'post'       => $post,
			'meta'       => $this->meta_values( $post_id, $this->fields() ),
			'model'      => $this->model,
			'attributes' => $normalized,
		];
		return $this->render_template( 'single', $variables );
	}

	/**
	 * @param array<string, mixed> $attributes Shortcode attributes.
	 * @return array<string, mixed> Normalized attributes.
	 */
	private function normalize_list_attributes( array $attributes ): array {
		$allowed_orderby = [ 'date', 'title', 'modified', 'menu_order', 'ID' ];
		$orderby         = (string) ( $attributes['orderby'] ?? 'date' );
		$order           = strtoupper( (string) ( $attributes['order'] ?? 'desc' ) );
		$raw_terms       = $attributes['terms'] ?? '';
		$terms           = is_array( $raw_terms ) ? $raw_terms : explode( ',', (string) $raw_terms );
		return [
			'view'     => 'list',
			'limit'    => max( 1, min( 100, absint( $attributes['limit'] ?? 10 ) ) ),
			'orderby'  => in_array( $orderby, $allowed_orderby, true ) ? $orderby : 'date',
			'order'    => in_array( $order, [ 'ASC', 'DESC' ], true ) ? $order : 'DESC',
			'taxonomy' => sanitize_key( (string) ( $attributes['taxonomy'] ?? '' ) ),
			'terms'    => array_values( array_filter( array_map( 'sanitize_key', $terms ) ) ),
		];
	}

	private function taxonomy_is_valid( string $taxonomy ): bool {
		return function_exists( 'is_object_in_taxonomy' ) && is_object_in_taxonomy( $this->post_type, $taxonomy );
	}

	/** @return list<array<string, mixed>> */
	private function fields(): array {
		$args = $this->model instanceof Model ? $this->model->get_args() : [];
		$meta = is_array( $args['meta'] ?? null ) ? $args['meta'] : [];
		return $this->meta_field_provider->normalize_meta_fields( $meta )['fields'];
	}

	/**
	 * @param list<array<string, mixed>> $fields Normalized fields.
	 * @return array<string, mixed> Meta values keyed by field path.
	 */
	private function meta_values( int $post_id, array $fields ): array {
		$values = [];
		foreach ( $fields as $field ) {
			$path     = (string) ( $field['path'] ?? '' );
			$meta_key = (string) ( $field['meta_key'] ?? $path );
			if ( $path === '' || $meta_key === '' ) {
				continue;
			}
			$value = get_post_meta( $post_id, $meta_key, true );
			if ( ! empty( $field['serialized'] ) && $path !== $meta_key ) {
				foreach ( explode( '.', substr( $path, strlen( $meta_key ) + 1 ) ) as $segment ) {
					$value = is_array( $value ) && array_key_exists( $segment, $value ) ? $value[ $segment ] : null;
				}
			}
			$values[ $path ] = $value;
		}
		return $values;
	}

	/** @param array<string, mixed> $variables */
	private function render_template( string $view, array $variables ): string {
		$template = (string) ( $this->config['templates'][ $view ] ?? '' );
		$root     = realpath( (string) ( $this->project['path'] ?? '' ) );
		if ( $template !== '' && $root !== false ) {
			$candidate = realpath( rtrim( (string) $this->project['path'], '/\\' ) . '/' . ltrim( $template, '/\\' ) );
			if ( $candidate !== false && strpos( $candidate, $root . DIRECTORY_SEPARATOR ) === 0 && is_file( $candidate ) ) {
				$template = $candidate;
			} else {
				$template = '';
			}
		}
		if ( $template === '' && function_exists( 'locate_template' ) ) {
			$template = locate_template( [ 'saltus/' . $this->post_type . '/' . $view . '.php' ], false, false );
		}
		if ( $template === '' ) {
			$template = dirname( __DIR__, 3 ) . '/templates/' . $view . '.php';
		}
		if ( ! is_file( $template ) ) {
			return '';
		}
		extract( $variables, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		ob_start();
		include $template;
		return (string) ob_get_clean();
	}
}
