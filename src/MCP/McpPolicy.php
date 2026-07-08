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
	 * Resolve whether a capability should be shown in MCP.
	 *
	 * @param array<string, mixed> $config
	 *
	 * @return bool
	 */
	private function resolve_show_in_mcp( array $config, string $capability ): bool {
		$map = [
			ModelRestPolicy::CAPABILITY_META      => $config['meta'] ?? null,
			ModelRestPolicy::CAPABILITY_SETTINGS  => $config['settings'] ?? null,
			ModelRestPolicy::CAPABILITY_DUPLICATE => $config['features']['duplicate'] ?? null,
			ModelRestPolicy::CAPABILITY_EXPORT    => $config['features']['single_export'] ?? null,
			ModelRestPolicy::CAPABILITY_REORDER   => $config['features']['drag_and_drop'] ?? null,
		];

		$section = $map[ $capability ] ?? null;
		if ( $section === null ) {
			return false;
		}

		if ( ! is_array( $section ) || ! array_key_exists( 'show_in_mcp', $section ) ) {
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
