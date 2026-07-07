<?php

namespace Saltus\WP\Framework\Rest;

/**
 * Registers REST routes filtered by ModelRestPolicy capability checks.
 */
class RestServer {

	private ModelRestPolicy $policy;

	/** @var list<RestRouteDefinition> */
	private array $routes;

	/**
	 * @param ModelRestPolicy $policy  The REST policy for capability gating.
	 * @param list<RestRouteDefinition> $routes  The route definitions to register.
	 */
	public function __construct( ModelRestPolicy $policy, array $routes ) {
		$this->policy = $policy;
		$this->routes = $routes;
	}

	/**
	 * Register all routes whose capability checks pass.
	 */
	public function register_routes(): void {
		foreach ( $this->routes as $route ) {
			if ( ! $this->policy->has_capability( $route->get_capability(), $route->get_model_type() ) ) {
				continue;
			}

			$route->register_routes();
		}
	}
}
