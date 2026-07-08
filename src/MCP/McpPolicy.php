<?php

namespace Saltus\WP\Framework\MCP;

use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Rest\ModelRestPolicy;

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
		$options = $model->get_options();

		if ( empty( $options['mcp_tools'] ) ) {
			return false;
		}

		if ( $capability === ModelRestPolicy::CAPABILITY_HEALTH || $capability === ModelRestPolicy::CAPABILITY_MODELS ) {
			return true;
		}

		$config = $model->get_config();

		return $this->resolve_show_in_mcp( $config, $capability );
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
	 *
	 * @return bool
	 */
	private function resolve_show_in_mcp( array $config, string $capability ): bool {
		$section = $this->get_capability_config( $config, $capability );
		if ( $section === null ) {
			return true;
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
