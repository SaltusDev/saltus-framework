<?php

namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Rest\ModelRestPolicy;

interface ToolContributor {

	/**
	 * Get the MCP tools contributed by this service.
	 *
	 * @param Modeler $modeler  The model registry for tool construction.
	 * @param ModelRestPolicy|null $policy  Optional REST policy for capability gating.
	 * @return list<ToolInterface>
	 */
	public function get_mcp_tools( Modeler $modeler, ?ModelRestPolicy $policy = null ): array;
}
