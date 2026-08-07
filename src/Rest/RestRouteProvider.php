<?php

namespace Saltus\WP\Framework\Rest;

use Saltus\WP\Framework\Modeler;

/**
 * @api
 */
interface RestRouteProvider {

	/**
	 * @return list<RestRouteDefinition>
	 */
	public function get_rest_routes( Modeler $modeler, ModelRestPolicy $policy ): array;
}
