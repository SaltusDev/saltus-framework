<?php

namespace Saltus\WP\Framework\MCP\Tools;

/**
 * Value object holding a capability and optional model type requirement.
 */
class RestCapabilityRequirement {

	private string $capability;
	private ?string $model_type;

	/**
	 * @param string $capability  The WordPress capability required.
	 * @param string|null $model_type  Optional model type to scope the capability (e.g. "post_type").
	 */
	public function __construct( string $capability, ?string $model_type = null ) {
		$this->capability = $capability;
		$this->model_type = $model_type;
	}

	/**
	 * Get the required capability string.
	 *
	 * @return string  The WordPress capability.
	 */
	public function get_capability(): string {
		return $this->capability;
	}

	/**
	 * Get the optional model type this capability applies to.
	 *
	 * @return string|null  Model type, or null if not scoped.
	 */
	public function get_model_type(): ?string {
		return $this->model_type;
	}
}
