<?php

namespace Saltus\WP\Framework\Rest;

use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;

class ModelRestPolicy {

	public const CAPABILITY_MODELS    = 'models';
	public const CAPABILITY_META      = 'meta';
	public const CAPABILITY_SETTINGS  = 'settings';
	public const CAPABILITY_DUPLICATE = 'duplicate';
	public const CAPABILITY_EXPORT    = 'export';
	public const CAPABILITY_REORDER   = 'reorder';
	public const CAPABILITY_HEALTH    = 'health';

	private Modeler $modeler;

	public function __construct( Modeler $modeler ) {
		$this->modeler = $modeler;
	}

	public function has_capability( string $capability, ?string $model_type = null ): bool {
		if ( $capability === self::CAPABILITY_HEALTH ) {
			return true;
		}

		foreach ( $this->modeler->get_models() as $model ) {
			if ( $model_type !== null && $model->get_type() !== $model_type ) {
				continue;
			}

			if ( $this->is_enabled( $model, $capability ) ) {
				return true;
			}
		}

		return false;
	}

	public function is_enabled( Model $model, string $capability ): bool {
		$options = $this->get_model_options( $model );

		if ( array_key_exists( 'show_in_rest', $options ) && $options['show_in_rest'] === false ) {
			return false;
		}

		if ( $capability === self::CAPABILITY_HEALTH || $capability === self::CAPABILITY_MODELS ) {
			return true;
		}

		$config = $model->get_config();

		return $this->resolve_show_in_rest( $config, $capability );
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function resolve_show_in_rest( array $config, string $capability ): bool {
		$features = ( isset( $config['features'] ) && is_array( $config['features'] ) ) ? $config['features'] : [];

		$map = [
			self::CAPABILITY_META      => $config['meta'] ?? null,
			self::CAPABILITY_SETTINGS  => $config['settings'] ?? null,
			self::CAPABILITY_DUPLICATE => $features['duplicate'] ?? null,
			self::CAPABILITY_EXPORT    => $features['single_export'] ?? null,
			self::CAPABILITY_REORDER   => $features['drag_and_drop'] ?? null,
		];

		$section = $map[ $capability ] ?? null;
		if ( $section === null ) {
			return false;
		}

		if ( ! is_array( $section ) || ! array_key_exists( 'show_in_rest', $section ) ) {
			return true;
		}

		return (bool) $section['show_in_rest'];
	}

	public function is_post_type_enabled( string $post_type, string $capability ): bool {
		$model = $this->get_model( $post_type );

		return $model !== null
			&& $model->get_type() === 'post_type'
			&& $this->is_enabled( $model, $capability );
	}

	public function is_post_enabled( int $post_id, string $capability ): bool {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		return $this->is_post_type_enabled( (string) $post->post_type, $capability );
	}

	public function get_model( string $name ): ?Model {
		$models = $this->modeler->get_models();

		return $models[ $name ] ?? null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_model_args( Model $model ): array {
		return $model->get_args();
	}

	/**
	 * @return array<string, Model>
	 */
	public function get_enabled_models( string $capability, ?string $model_type = null ): array {
		$enabled = [];

		foreach ( $this->modeler->get_models() as $name => $model ) {
			if ( $model_type !== null && $model->get_type() !== $model_type ) {
				continue;
			}

			if ( $this->is_enabled( $model, $capability ) ) {
				$enabled[ $name ] = $model;
			}
		}

		return $enabled;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_model_options( Model $model ): array {
		return $model->get_options();
	}
}
