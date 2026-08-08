<?php

namespace Saltus\WP\Framework\Rest;

use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;

/**
 * @api
 */
class ModelRestPolicy {

	public const CAPABILITY_MODELS    = 'models';
	public const CAPABILITY_META      = 'meta';
	public const CAPABILITY_SETTINGS  = 'settings';
	public const CAPABILITY_DUPLICATE = 'duplicate';
	public const CAPABILITY_EXPORT    = 'export';
	public const CAPABILITY_REORDER   = 'reorder';
	public const CAPABILITY_HEALTH    = 'health';
	public const CAPABILITY_BLOCKS    = 'blocks';

	public const CAPABILITY_RELATIONSHIPS = 'relationships';

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
		$options    = $this->get_model_options( $model );
		$global_val = null;
		if ( array_key_exists( 'show_in_rest', $options ) ) {
			$global_val = (bool) $options['show_in_rest'];
		}

		if ( $capability === self::CAPABILITY_HEALTH ) {
			return true;
		}

		if ( $capability === self::CAPABILITY_MODELS ) {
			return $global_val !== false;
		}

		$config      = $model->get_config();
		$feature_val = $this->resolve_feature_value( $config, $capability );

		if ( $feature_val !== null ) {
			return $feature_val;
		}

		return $global_val === true;
	}

	/**
	 * Get the configuration section for a specific capability.
	 *
	 * @param array<string, mixed> $config     The model configuration.
	 * @param string               $capability The capability.
	 * @return mixed
	 */
	private function get_capability_config( array $config, string $capability ) {
		if ( in_array( $capability, [ self::CAPABILITY_META, self::CAPABILITY_SETTINGS, self::CAPABILITY_BLOCKS, self::CAPABILITY_RELATIONSHIPS ], true ) ) {
			return $config[ $capability ] ?? null;
		}

		$features = $config['features'] ?? [];
		if ( ! is_array( $features ) ) {
			return null;
		}

		$feature_keys = [
			self::CAPABILITY_DUPLICATE => 'duplicate',
			self::CAPABILITY_EXPORT    => 'single_export',
			self::CAPABILITY_REORDER   => 'drag_and_drop',
		];

		$key = $feature_keys[ $capability ] ?? null;
		if ( $key === null ) {
			return null;
		}

		return $features[ $key ] ?? null;
	}

	/**
	 * Resolve the capability value from the feature configuration.
	 *
	 * @param array<string, mixed> $config
	 * @param string               $capability
	 * @return bool|null
	 */
	private function resolve_feature_value( array $config, string $capability ): ?bool {
		$section = $this->get_capability_config( $config, $capability );
		if ( $section === null ) {
			return null;
		}

		if ( ! is_array( $section ) ) {
			return (bool) $section;
		}

		if ( ! array_key_exists( 'show_in_rest', $section ) ) {
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
