<?php
namespace Saltus\WP\Framework\Features\Blocks;

/**
 * Renders model-driven list and single blocks.
 * @api
 */
final class BlockRenderer {

	/** @var array<string, mixed> */
	private array $project;

	/** @param array<string, mixed> $project Project paths. */
	public function __construct( array $project = [] ) {
		$this->project = $project;
	}

	/**
	 * @param array<string, mixed> $definition Block/model definition.
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( string $view, array $definition, array $attributes ): string {
		if ( $view === 'list' ) {
			return $this->render_list( $definition, $attributes );
		}
		if ( $view === 'single' ) {
			return $this->render_single( $definition, $attributes );
		}
		return '';
	}

	/**
	 * @param array<string, mixed> $definition Block definition.
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_list( array $definition, array $attributes ): string {
		$attributes = $this->normalize_list_attributes( $attributes );
		$query_args = [
			'post_type'      => $definition['post_type'],
			'post_status'    => 'publish',
			'posts_per_page' => $attributes['postsToShow'],
			'orderby'        => $attributes['orderBy'],
			'order'          => $attributes['order'],
		];

		if ( $attributes['taxonomy'] !== '' && $attributes['terms'] !== [] && $this->taxonomy_is_valid( $attributes['taxonomy'], (string) $definition['post_type'] ) ) {
			$query_args['tax_query'] = [
				[
					'taxonomy' => $attributes['taxonomy'],
					'field'    => 'slug',
					'terms'    => $attributes['terms'],
				],
			];
		}

		$query_args   = apply_filters( 'saltus/framework/blocks/query_args', $query_args, $definition, $attributes );
		$query        = new \WP_Query( is_array( $query_args ) ? $query_args : [] );
		$posts        = $query->posts;
		$meta_fields  = $this->selected_meta_fields( $definition, $attributes );
		$meta_by_post = [];
		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$meta_by_post[ $post->ID ] = $this->meta_values( $post->ID, $meta_fields );
			}
		}

		return $this->render_template( 'list', $definition, $attributes, compact( 'posts', 'meta_fields', 'meta_by_post' ) );
	}

	/**
	 * @param array<string, mixed> $definition Block definition.
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_single( array $definition, array $attributes ): string {
		$post_id = max( 0, (int) ( $attributes['postId'] ?? 0 ) );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || $post->post_type !== $definition['post_type'] || $post->post_status !== 'publish' ) {
			return '';
		}

		$attributes  = [
			'postId'      => $post_id,
			'showTitle'   => ! array_key_exists( 'showTitle', $attributes ) || (bool) $attributes['showTitle'],
			'showContent' => ! array_key_exists( 'showContent', $attributes ) || (bool) $attributes['showContent'],
			'metaFields'  => is_array( $attributes['metaFields'] ?? null ) ? $attributes['metaFields'] : [],
		];
		$meta_fields = $this->selected_meta_fields( $definition, $attributes );
		$meta        = $this->meta_values( $post_id, $meta_fields );

		return $this->render_template( 'single', $definition, $attributes, compact( 'post', 'meta', 'meta_fields' ) );
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return array<string, mixed>
	 */
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- Attribute normalization validates independent inputs.
	private function normalize_list_attributes( array $attributes ): array {
		$allowed_orderby = [ 'date', 'title', 'modified', 'menu_order', 'ID' ];
		$orderby         = (string) ( $attributes['orderBy'] ?? 'date' );
		$order           = strtoupper( (string) ( $attributes['order'] ?? 'DESC' ) );
		$terms           = is_array( $attributes['terms'] ?? null ) ? $attributes['terms'] : [];

		return [
			'postsToShow' => max( 1, min( 100, (int) ( $attributes['postsToShow'] ?? 10 ) ) ),
			'orderBy'     => in_array( $orderby, $allowed_orderby, true ) ? $orderby : 'date',
			'order'       => in_array( $order, [ 'ASC', 'DESC' ], true ) ? $order : 'DESC',
			'taxonomy'    => sanitize_key( (string) ( $attributes['taxonomy'] ?? '' ) ),
			'terms'       => array_values( array_filter( array_map( 'sanitize_key', $terms ) ) ),
			'showExcerpt' => ! array_key_exists( 'showExcerpt', $attributes ) || (bool) $attributes['showExcerpt'],
			'showDate'    => ! array_key_exists( 'showDate', $attributes ) || (bool) $attributes['showDate'],
			'metaFields'  => is_array( $attributes['metaFields'] ?? null ) ? $attributes['metaFields'] : [],
		];
	}

	private function taxonomy_is_valid( string $taxonomy, string $post_type ): bool {
		return function_exists( 'is_object_in_taxonomy' ) && is_object_in_taxonomy( $post_type, $taxonomy );
	}

	/**
	 * @param array<string, mixed> $definition Block definition.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return list<array<string, mixed>>
	 */
	private function selected_meta_fields( array $definition, array $attributes ): array {
		$selected = is_array( $attributes['metaFields'] ?? null ) ? array_map( 'strval', $attributes['metaFields'] ) : [];
		$fields   = is_array( $definition['meta_fields'] ?? null ) ? $definition['meta_fields'] : [];
		return array_values(
			array_filter(
				$fields,
				static function ( $field ) use ( $selected ): bool {
					return is_array( $field ) && isset( $field['path'] ) && in_array( (string) $field['path'], $selected, true );
				}
			)
		);
	}

	/**
	 * @param list<array<string, mixed>> $fields Normalized meta fields.
	 * @return array<string, mixed>
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
				$segments = explode( '.', substr( $path, strlen( $meta_key ) + 1 ) );
				foreach ( $segments as $segment ) {
					if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
						$value = null;
						break;
					}
					$value = $value[ $segment ];
				}
			}
			$values[ $path ] = $value;
		}
		return $values;
	}

	/**
	 * @param array<string, mixed> $definition
	 * @param array<string, mixed> $attributes
	 * @param array<string, mixed> $variables
	 */
	private function render_template( string $view, array $definition, array $attributes, array $variables ): string {
		$template = $this->template_path( $view, $definition );
		$template = apply_filters( 'saltus/framework/blocks/template', $template, $view, $definition );
		if ( ! is_string( $template ) || ! is_file( $template ) ) {
			return '';
		}

		$model = $definition['model'];
		extract( $variables, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		ob_start();
		include $template;
		$content = (string) ob_get_clean();
		$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes( [ 'class' => 'saltus-block saltus-block--' . $view ] ) : 'class="saltus-block saltus-block--' . esc_attr( $view ) . '"';
		return '<div ' . $wrapper . '>' . $content . '</div>';
	}

	/** @param array<string, mixed> $definition */
	private function template_path( string $view, array $definition ): string {
		$configured   = (string) ( $definition['templates'][ $view ] ?? '' );
		$project_path = (string) ( $this->project['path'] ?? '' );
		if ( $configured !== '' && $project_path !== '' ) {
			$root      = realpath( $project_path );
			$candidate = realpath( rtrim( $project_path, '/\\' ) . '/' . ltrim( $configured, '/\\' ) );
			if ( $root !== false && $candidate !== false && strpos( $candidate, $root . DIRECTORY_SEPARATOR ) === 0 && is_file( $candidate ) ) {
				return $candidate;
			}
		}

		if ( function_exists( 'locate_template' ) ) {
			$theme = locate_template( [ 'saltus/' . $definition['post_type'] . '/block-' . $view . '.php' ], false, false );
			if ( $theme !== '' ) {
				return $theme;
			}
		}

		return dirname( __DIR__, 3 ) . '/templates/blocks/' . $view . '.php';
	}
}
