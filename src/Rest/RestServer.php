<?php

namespace Saltus\WP\Framework\Rest;

class RestServer {

	private ModelRestPolicy $policy;

	/** @var list<RestRouteDefinition> */
	private array $routes;

	/**
	 * @param list<RestRouteDefinition> $routes
	 */
	public function __construct( ModelRestPolicy $policy, array $routes ) {
		$this->policy = $policy;
		$this->routes = $routes;
	}

	public function register_routes(): void {
		foreach ( $this->routes as $route ) {
			if ( ! $this->policy->has_capability( $route->get_capability(), $route->get_model_type() ) ) {
				continue;
			}

			$route->register_routes();
		}
	}
}
