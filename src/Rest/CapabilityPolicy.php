<?php
namespace Saltus\WP\Framework\Rest;

use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;

/**
 * Unified capability policy supporting both REST and MCP gate keys.
 *
 * Replaces ModelRestPolicy and McpPolicy with a single implementation
 * parameterized by the config gate key (show_in_rest or show_in_mcp).
 * @api
 */
class CapabilityPolicy {

	public const CAPABILITY_MODELS    = 'models';
	public const CAPABILITY_META      = 'meta';
	public const CAPABILITY_SETTINGS  = 'settings';
	public const CAPABILITY_DUPLICATE = 'duplicate';
	public const CAPABILITY_EXPORT    = 'export';
	public const CAPABILITY_REORDER   = 'reorder';
	public const CAPABILITY_HEALTH    = 'health';

	public const GATE_REST = 'show_in_rest';
	public const GATE_MCP  = 'show_in_mcp';

	private Modeler $modeler;

	public function __construct( Modeler $modeler ) {
		$this->modeler = $modeler;
	}

	/**
	 * Check whether any model has the given capability enabled.
	 *
	 * @param string      $gate       The gate key (GATE_REST or GATE_MCP).
	 * @param string      $capability The capability to check.
	 * @param string|null $model_type Optional model type filter.
	 * @return bool
	 */
	public function has_capability( string $gate, string $capability, ?string $model_type = null ): bool {
		if ( $capability === self::CAPABILITY_HEALTH ) {
			return true;
		}

		foreach ( $this->modeler->get_models() as $model ) {
			if ( $model_type !== null && $model->get_type() !== $model_type ) {
				continue;
			}

			if ( $this->is_enabled( $model, $gate, $capability ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a given model has the capability enabled for the given gate.
	 *
	 * @param Model  $model      The model to check.
	 * @param string $gate       The gate key (GATE_REST or GATE_MCP).
	 * @param string $capability The capability to check.
	 * @return bool
	 */
	public function is_enabled( Model $model, string $gate, string $capability ): bool {
		$options    = $model->get_options();
		$global_val = null;
		if ( array_key_exists( $gate, $options ) ) {
			$global_val = (bool) $options[ $gate ];
		}

		if ( $capability === self::CAPABILITY_HEALTH ) {
			return true;
		}

		if ( $capability === self::CAPABILITY_MODELS ) {
			if ( $gate === self::GATE_REST ) {
				return $global_val !== false;
			}
			return $global_val === true;
		}

		$config      = $model->get_config();
		$feature_val = $this->resolve_feature_value( $config, $gate, $capability );

		if ( $feature_val !== null ) {
			return $feature_val;
		}

		return $global_val === true;
	}

	/**
	 * Get models that have the given capability enabled.
	 *
	 * @param string      $gate       The gate key (GATE_REST or GATE_MCP).
	 * @param string      $capability The capability to check.
	 * @param string|null $model_type Optional model type filter.
	 * @return array<string, Model>
	 */
	public function get_enabled_models( string $gate, string $capability, ?string $model_type = null ): array {
		$enabled = [];

		foreach ( $this->modeler->get_models() as $name => $model ) {
			if ( $model_type !== null && $model->get_type() !== $model_type ) {
				continue;
			}

			if ( $this->is_enabled( $model, $gate, $capability ) ) {
				$enabled[ $name ] = $model;
			}
		}

		return $enabled;
	}

	/**
	 * Get a model by name from the modeler.
	 *
	 * @param string $name
	 * @return Model|null
	 */
	public function get_model( string $name ): ?Model {
		$models = $this->modeler->get_models();

		return $models[ $name ] ?? null;
	}

	/**
	 * Check whether a specific post type has the capability enabled.
	 *
	 * @param string $post_type
	 * @param string $gate
	 * @param string $capability
	 * @return bool
	 */
	public function is_post_type_enabled( string $post_type, string $gate, string $capability ): bool {
		$model = $this->get_model( $post_type );

		return $model !== null
			&& $model->get_type() === 'post_type'
			&& $this->is_enabled( $model, $gate, $capability );
	}

	/**
	 * Check whether a specific post has the capability enabled.
	 *
	 * @param int    $post_id
	 * @param string $gate
	 * @param string $capability
	 * @return bool
	 */
	public function is_post_enabled( int $post_id, string $gate, string $capability ): bool {
		if ( ! function_exists( 'get_post' ) ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		return $this->is_post_type_enabled( (string) $post->post_type, $gate, $capability );
	}

	/**
	 * Get the model options array.
	 *
	 * @param Model $model
	 * @return array<string, mixed>
	 */
	public function get_model_args( Model $model ): array {
		return $model->get_args();
	}

	/**
	 * Get the configuration section for a specific capability.
	 *
	 * @param array<string, mixed> $config
	 * @param string               $capability
	 * @return mixed
	 */
	private function get_capability_config( array $config, string $capability ) {
		if ( $capability === self::CAPABILITY_META || $capability === self::CAPABILITY_SETTINGS ) {
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
	 * Resolve whether a capability is enabled from feature config.
	 *
	 * @param array<string, mixed> $config
	 * @param string               $gate
	 * @param string               $capability
	 * @return bool|null
	 */
	private function resolve_feature_value( array $config, string $gate, string $capability ): ?bool {
		$section = $this->get_capability_config( $config, $capability );
		if ( $section === null ) {
			return null;
		}

		if ( ! is_array( $section ) ) {
			return (bool) $section;
		}

		if ( ! array_key_exists( $gate, $section ) ) {
			return true;
		}

		return (bool) $section[ $gate ];
	}
}
