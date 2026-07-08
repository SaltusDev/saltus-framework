<?php

namespace Saltus\WP\Framework\MCP;

use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Rest\ModelRestPolicy;

class McpPolicy {

	private Modeler $modeler;

	public function __construct( Modeler $modeler ) {
		$this->modeler = $modeler;
	}

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
	 * @param array<string, mixed> $config
	 */
	private function resolve_show_in_mcp( array $config, string $capability ): bool {
		$section = match ( $capability ) {
			ModelRestPolicy::CAPABILITY_META      => $config['meta'] ?? null,
			ModelRestPolicy::CAPABILITY_SETTINGS  => $config['settings'] ?? null,
			ModelRestPolicy::CAPABILITY_DUPLICATE => $config['features']['duplicate'] ?? null,
			ModelRestPolicy::CAPABILITY_EXPORT    => $config['features']['single_export'] ?? null,
			ModelRestPolicy::CAPABILITY_REORDER   => $config['features']['drag_and_drop'] ?? null,
			default                               => null,
		};

		if ( $section === null ) {
			return false;
		}

		if ( ! is_array( $section ) || ! array_key_exists( 'show_in_mcp', $section ) ) {
			return true;
		}

		return (bool) $section['show_in_mcp'];
	}

	/**
	 * @param Model $model
	 */
	public function get_model( string $name ): ?Model {
		$models = $this->modeler->get_models();

		return $models[ $name ] ?? null;
	}
}
