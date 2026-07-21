<?php

namespace Saltus\WP\Framework\MCP;

use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Rest\ModelRestPolicy;

/**
 * @api
 */
class McpPolicy {
	/**
	 * Modeler instance.
	 * It is responsible for providing models and model types.
	 *
	 * @var Modeler
	 */
	private Modeler $modeler;

	/**
	 * @param Modeler $modeler
	 */
	public function __construct( Modeler $modeler ) {
		$this->modeler = $modeler;
	}

	/**
	 * Check if a capability is enabled.
	 *
	 * @param string $capability      The capability.
	 * @param string|null $model_type The model type.
	 *
	 * @return bool
	 */
	public function has_capability( string $capability, ?string $model_type = null ): bool {
		if ( $capability === ModelRestPolicy::CAPABILITY_HEALTH ) {
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

	/**
	 * Check if a capability is enabled for a model.
	 *
	 * @param Model  $model      The model.
	 * @param string $capability The capability.
	 *
	 * @return bool
	 */
	public function is_enabled( Model $model, string $capability ): bool {
		$options    = $model->get_options();
		$global_val = null;
		if ( array_key_exists( 'mcp_tools', $options ) ) {
			$global_val = (bool) $options['mcp_tools'];
		}

		if ( $capability === ModelRestPolicy::CAPABILITY_HEALTH ) {
			return true;
		}

		if ( $capability === ModelRestPolicy::CAPABILITY_MODELS ) {
			return $global_val === true;
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
		if ( $capability === ModelRestPolicy::CAPABILITY_META || $capability === ModelRestPolicy::CAPABILITY_SETTINGS ) {
			return $config[ $capability ] ?? null;
		}

		$features = $config['features'] ?? [];
		if ( ! is_array( $features ) ) {
			return null;
		}

		$feature_keys = [
			ModelRestPolicy::CAPABILITY_DUPLICATE => 'duplicate',
			ModelRestPolicy::CAPABILITY_EXPORT    => 'single_export',
			ModelRestPolicy::CAPABILITY_REORDER   => 'drag_and_drop',
		];

		$key = $feature_keys[ $capability ] ?? null;
		if ( $key === null ) {
			return null;
		}

		return $features[ $key ] ?? null;
	}

	/**
	 * Resolve whether a capability should be shown in MCP.
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

		if ( ! array_key_exists( 'show_in_mcp', $section ) ) {
			return true;
		}

		return (bool) $section['show_in_mcp'];
	}

	/**
	 * Get a model by name.
	 *
	 * @param string $name The model name.
	 * @return Model|null
	 */
	public function get_model( string $name ): ?Model {
		$models = $this->modeler->get_models();

		return $models[ $name ] ?? null;
	}
}
