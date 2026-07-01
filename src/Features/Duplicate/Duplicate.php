<?php
namespace Saltus\WP\Framework\Features\Duplicate;

use Saltus\WP\Framework\Infrastructure\Service\{
	Assembly,
	Service,
	Conditional
};
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Rest\DuplicateController;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Rest\RestRouteDefinition;
use Saltus\WP\Framework\Rest\RestRouteProvider;


/**
 */
class Duplicate implements Service, Conditional, Assembly, RestRouteProvider {

	/**
	 * Instantiate this Service object.
	 *
	 */
	public function __construct() {}

	/**
	 * Create a new instance of the service provider
	 *
	 * @return object The new instance
	 */
	public static function make( string $name, array $project, array $args ): object {
		return new SaltusDuplicate( $name, $args );
	}

	/**
	 * Check whether the conditional service is currently needed.
	 *
	 * @return bool Whether the conditional service is needed.
	 */
	public static function is_needed(): bool {

		/*
		 * This service loads in most screens:
		 * - admin: in the edit screen
		 * - ajax:  while updating menu order
		 * - front: during pre_get_posts, etc
		 */
		return is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/**
	 * @return list<RestRouteDefinition>
	 */
	public function get_rest_routes( Modeler $modeler, ModelRestPolicy $policy ): array {
		return [
			new RestRouteDefinition(
				ModelRestPolicy::CAPABILITY_DUPLICATE,
				new DuplicateController( $policy ),
				'post_type'
			),
		];
	}
}
