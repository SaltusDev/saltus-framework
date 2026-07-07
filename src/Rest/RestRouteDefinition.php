<?php

namespace Saltus\WP\Framework\Rest;

/**
 * Value object binding a capability, controller, and optional model type to a REST route.
 */
class RestRouteDefinition {

	private string $capability;
	private object $controller;
	private ?string $model_type;

	/**
	 * @param string $capability  The WordPress capability required for this route.
	 * @param object $controller  The REST controller instance.
	 * @param string|null $model_type  Optional model type to scope the route.
	 */
	public function __construct( string $capability, object $controller, ?string $model_type = null ) {
		$this->capability = $capability;
		$this->controller = $controller;
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
	 * Get the optional model type this route applies to.
	 *
	 * @return string|null  Model type, or null if not scoped.
	 */
	public function get_model_type(): ?string {
		return $this->model_type;
	}

	/**
	 * Register the controller's routes if the method exists.
	 */
	public function register_routes(): void {
		if ( ! method_exists( $this->controller, 'register_routes' ) ) {
			return;
		}

		$this->controller->register_routes();
	}
}
