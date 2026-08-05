<?php

namespace Saltus\WP\Framework\Features\AiContext;

use Saltus\WP\Framework\Infrastructure\Service\Service;
use Saltus\WP\Framework\MCP\Tools\GetContext;
use Saltus\WP\Framework\MCP\Tools\ToolContributor;
use Saltus\WP\Framework\MCP\Tools\ToolInterface;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Rest\AiContextController;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Rest\RestRouteDefinition;
use Saltus\WP\Framework\Rest\RestRouteProvider;

/** Registers model-scoped AI governance discovery and policy services. */
final class AiContext implements Service, RestRouteProvider, ToolContributor {

	private AiContextProvider $provider;

	/** @param array<string, mixed> $dependencies */
	public function __construct( array $dependencies = [] ) {
		$this->provider = new AiContextProvider( $dependencies['modeler_resolver'] ?? null );
	}

	/** @return list<RestRouteDefinition> */
	public function get_rest_routes( Modeler $modeler, ModelRestPolicy $policy ): array {
		return [ new RestRouteDefinition( ModelRestPolicy::CAPABILITY_MODELS, new AiContextController( $policy, $this->provider ), 'post_type' ) ];
	}

	/** @return list<ToolInterface> */
	public function get_mcp_tools( Modeler $modeler, ?ModelRestPolicy $policy = null ): array {
		return [ new GetContext() ];
	}

	public function get_provider(): AiContextProvider {
		return $this->provider;
	}
}
