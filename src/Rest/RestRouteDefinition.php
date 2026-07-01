<?php

namespace Saltus\WP\Framework\Rest;

class RestRouteDefinition {

	private string $capability;
	private object $controller;
	private ?string $model_type;

	public function __construct( string $capability, object $controller, ?string $model_type = null ) {
		$this->capability = $capability;
		$this->controller = $controller;
		$this->model_type = $model_type;
	}

	public function get_capability(): string {
		return $this->capability;
	}

	public function get_model_type(): ?string {
		return $this->model_type;
	}

	public function register_routes(): void {
		if ( ! method_exists( $this->controller, 'register_routes' ) ) {
			return;
		}

		$this->controller->register_routes();
	}
}
