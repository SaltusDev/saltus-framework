<?php
namespace Saltus\WP\Framework\Features\Blocks;

use Saltus\WP\Framework\Features\Meta\MetaFieldProvider;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;

/**
 * Builds and registers runtime block definitions for configured post types.
 * @api
 */
final class SaltusBlocks {

	private const EDITOR_HANDLE = 'saltus-framework-blocks-editor';
	private const STYLE_HANDLE  = 'saltus-framework-blocks';

	private Modeler $modeler;
	/** @var array<string, mixed> */
	private array $project;
	private MetaFieldProvider $meta_field_provider;
	private BlockRenderer $renderer;

	/**
	 * @param array<string, mixed> $project Project paths and URLs.
	 */
	public function __construct( Modeler $modeler, array $project = [], ?MetaFieldProvider $meta_field_provider = null, ?BlockRenderer $renderer = null ) {
		$this->modeler             = $modeler;
		$this->project             = $project;
		$this->meta_field_provider = $meta_field_provider ?? new MetaFieldProvider();
		$this->renderer            = $renderer ?? new BlockRenderer( $project );
	}

	public function register(): void {
		$definitions = $this->definitions();
		if ( $definitions === [] ) {
			return;
		}

		$this->register_assets( $definitions );
		foreach ( $definitions as $definition ) {
			foreach ( $definition['blocks'] as $view => $block ) {
				$args = [
					'api_version'     => 3,
					'title'           => $block['title'],
					'description'     => $block['description'],
					'category'        => 'widgets',
					'icon'            => $view === 'list' ? 'list-view' : 'media-document',
					'attributes'      => $block['attributes'],
					'editor_script'   => self::EDITOR_HANDLE,
					'style'           => self::STYLE_HANDLE,
					'render_callback' => function ( array $attributes ) use ( $definition, $view ): string {
						return $this->renderer->render( $view, $definition, $attributes );
					},
				];
				$args = apply_filters( 'saltus/framework/blocks/attributes', $args, $definition['post_type'], $view );
				register_block_type( $block['name'], is_array( $args ) ? $args : [] );
			}
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function definitions(): array {
		$definitions = [];
		foreach ( $this->modeler->get_models() as $model ) {
			if ( $model->get_type() !== 'post_type' ) {
				continue;
			}

			$config = self::normalize_config( $model->get_config()['blocks'] ?? null );
			if ( ! $config['list'] && ! $config['single'] ) {
				continue;
			}

			$definitions[] = $this->definition( $model, $config );
		}

		return $definitions;
	}

	/**
	 * @param mixed $value Raw blocks config.
	 * @return array{list: bool, single: bool, templates: array<string, string>}
	 */
	public static function normalize_config( $value ): array {
		if ( $value === true ) {
			return [
				'list'      => true,
				'single'    => true,
				'templates' => [],
			];
		}

		if ( ! is_array( $value ) ) {
			return [
				'list'      => false,
				'single'    => false,
				'templates' => [],
			];
		}

		$templates = is_array( $value['templates'] ?? null ) ? $value['templates'] : [];
		return [
			'list'      => ! empty( $value['list'] ),
			'single'    => ! empty( $value['single'] ),
			'templates' => array_filter( $templates, 'is_string' ),
		];
	}

	/**
	 * @param array{list: bool, single: bool, templates: array<string, string>} $config
	 * @return array<string, mixed>
	 */
	private function definition( Model $model, array $config ): array {
		$args        = $model->get_args();
		$post_type   = $model->get_name();
		$label       = (string) ( $args['labels']['singular_name'] ?? $post_type );
		$plural      = (string) ( $args['labels']['name'] ?? $label );
		$meta        = is_array( $args['meta'] ?? null ) ? $args['meta'] : [];
		$normalized  = $this->meta_field_provider->normalize_meta_fields( $meta );
		$field_paths = [];
		foreach ( $normalized['fields'] as $field ) {
			if ( isset( $field['path'] ) ) {
				$field_paths[] = (string) $field['path'];
			}
		}

		$blocks = [];
		if ( $config['list'] ) {
			/* translators: %s: plural post type label. */
			$list_title = sprintf( __( '%s List', 'saltus-framework' ), $plural );
			/* translators: %s: plural post type label. */
			$list_description = sprintf( __( 'Display a list of %s.', 'saltus-framework' ), $plural );
			$blocks['list']   = [
				'name'        => 'saltus/' . sanitize_key( $post_type ) . '-list',
				'title'       => $list_title,
				'description' => $list_description,
				'attributes'  => $this->list_attributes( $field_paths ),
			];
		}
		if ( $config['single'] ) {
			/* translators: %s: singular post type label. */
			$single_title = sprintf( __( '%s Single', 'saltus-framework' ), $label );
			/* translators: %s: singular post type label. */
			$single_description = sprintf( __( 'Display one %s.', 'saltus-framework' ), $label );
			$blocks['single']   = [
				'name'        => 'saltus/' . sanitize_key( $post_type ) . '-single',
				'title'       => $single_title,
				'description' => $single_description,
				'attributes'  => $this->single_attributes( $field_paths ),
			];
		}

		return [
			'post_type'    => $post_type,
			'label'        => $label,
			'label_plural' => $plural,
			'blocks'       => $blocks,
			'meta_fields'  => $normalized['fields'],
			'templates'    => $config['templates'],
			'model'        => $model,
		];
	}

	/**
	 * @param list<string> $field_paths Normalized field paths.
	 * @return array<string, mixed>
	 */
	private function list_attributes( array $field_paths ): array {
		return [
			'postsToShow' => [
				'type'    => 'number',
				'default' => 10,
			],
			'order'       => [
				'type'    => 'string',
				'default' => 'DESC',
			],
			'orderBy'     => [
				'type'    => 'string',
				'default' => 'date',
			],
			'taxonomy'    => [
				'type'    => 'string',
				'default' => '',
			],
			'terms'       => [
				'type'    => 'array',
				'default' => [],
				'items'   => [ 'type' => 'string' ],
			],
			'showExcerpt' => [
				'type'    => 'boolean',
				'default' => true,
			],
			'showDate'    => [
				'type'    => 'boolean',
				'default' => true,
			],
			'metaFields'  => [
				'type'    => 'array',
				'default' => $field_paths,
				'items'   => [ 'type' => 'string' ],
			],
		];
	}

	/**
	 * @param list<string> $field_paths Normalized field paths.
	 * @return array<string, mixed>
	 */
	private function single_attributes( array $field_paths ): array {
		return [
			'postId'      => [
				'type'    => 'number',
				'default' => 0,
			],
			'showTitle'   => [
				'type'    => 'boolean',
				'default' => true,
			],
			'showContent' => [
				'type'    => 'boolean',
				'default' => true,
			],
			'metaFields'  => [
				'type'    => 'array',
				'default' => $field_paths,
				'items'   => [ 'type' => 'string' ],
			],
		];
	}

	/** @param list<array<string, mixed>> $definitions Block definitions. */
	private function register_assets( array $definitions ): void {
		$root_url = rtrim( (string) ( $this->project['root_url'] ?? '' ), '/' );
		wp_enqueue_script(
			self::EDITOR_HANDLE,
			$root_url . '/Feature/Blocks/editor.js',
			[ 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ],
			\Saltus\WP\Framework\Core::VERSION,
			true
		);
		wp_enqueue_style( self::STYLE_HANDLE, $root_url . '/Feature/Blocks/style.css', [], \Saltus\WP\Framework\Core::VERSION );
		wp_localize_script( self::EDITOR_HANDLE, 'saltusBlockDefinitions', [ 'items' => $this->editor_definitions( $definitions ) ] );
	}

	/**
	 * @param list<array<string, mixed>> $definitions Block definitions.
	 * @return list<array<string, mixed>>
	 */
	private function editor_definitions( array $definitions ): array {
		$output = [];
		foreach ( $definitions as $definition ) {
			foreach ( $definition['blocks'] as $view => $block ) {
				$output[] = [
					'name'        => $block['name'],
					'title'       => $block['title'],
					'description' => $block['description'],
					'view'        => $view,
					'attributes'  => $block['attributes'],
					'metaFields'  => $definition['meta_fields'],
				];
			}
		}
		return $output;
	}
}
