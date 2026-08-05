<?php
namespace Saltus\WP\Framework\Features\Frontend;

use Saltus\WP\Framework\Features\Meta\MetaFieldProvider;
use Saltus\WP\Framework\Infrastructure\Service\Processable;
use Saltus\WP\Framework\Models\Model;

/** Registers a model's frontend shortcodes and renders its content. */
final class SaltusFrontend implements Processable {

	/** @var array<string, array<string, mixed>> */
	private static array $models              = [];
	private static bool $shortcode_registered = false;

	private string $name;
	/** @var array<string, mixed> */
	private array $project;
	/** @var array<string, mixed> */
	private array $config;
	private MetaFieldProvider $meta_field_provider;
	private ?Model $model = null;

	/**
	 * @param array<string, mixed> $project Project data.
	 * @param array<string, mixed> $config Frontend configuration.
	 */
	public function __construct(
		string $name,
		array $project = [],
		array $config = [],
		?MetaFieldProvider $meta_field_provider = null
	) {
		$this->name                = $name;
		$this->project             = $project;
		$this->config              = self::normalize_config( $config );
		$this->meta_field_provider = $meta_field_provider ?? new MetaFieldProvider();
	}

	public function process(): void {
		if ( ! $this->config['shortcode'] ) {
			return;
		}

		self::$models[ $this->name ] = [
			'config'              => $this->config,
			'project'             => $this->project,
			'meta_field_provider' => $this->meta_field_provider,
			'model'               => $this->model,
		];

		if ( ! self::$shortcode_registered ) {
			add_shortcode( 'saltus_cpt', [ self::class, 'shortcode' ] );
			self::$shortcode_registered = true;
		}

		$alias = $this->config['shortcode_alias'];
		if ( $alias !== '' ) {
			add_shortcode( $alias, [ self::class, 'shortcode' ] );
		}
	}

	/**
	 * @param mixed $value
	 * @return array{shortcode: bool, shortcode_alias: string, templates: array<string, string>}
	 */
	public static function normalize_config( $value ): array {
		if ( $value === true ) {
			$value = [];
		}
		if ( ! is_array( $value ) ) {
			return [
				'shortcode'       => false,
				'shortcode_alias' => '',
				'templates'       => [],
			];
		}

		$templates = is_array( $value['templates'] ?? null ) ? $value['templates'] : [];
		return [
			'shortcode'       => ! array_key_exists( 'shortcode', $value ) || (bool) $value['shortcode'],
			'shortcode_alias' => isset( $value['shortcode_alias'] ) ? sanitize_key( (string) $value['shortcode_alias'] ) : '',
			'templates'       => array_filter( $templates, 'is_string' ),
		];
	}

	/**
	 * Render [saltus_cpt] or a configured alias.
	 *
	 * @param array<string, mixed> $attributes Shortcode attributes.
	 */
	public static function shortcode( array $attributes = [] ): string {
		$type = sanitize_key( (string) ( $attributes['type'] ?? '' ) );
		if ( $type === '' || ! isset( self::$models[ $type ] ) ) {
			return '';
		}

		$definition = self::$models[ $type ];
		$renderer   = new FrontendRenderer( $type, $definition['project'], $definition['config'], $definition['meta_field_provider'], $definition['model'] );
		return $renderer->render( $attributes );
	}

	/** @internal Useful for isolated test suites and long-running processes. */
	public static function reset(): void {
		self::$models               = [];
		self::$shortcode_registered = false;
	}

	public function set_model( Model $model ): void {
		$this->model = $model;
	}
}
