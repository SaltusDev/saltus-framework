<?php
namespace Saltus\WP\Framework\Features\DragAndDrop;

use Saltus\WP\Framework\Infrastructure\Service\{
	Assembly,
	Actionable,
	Service,
	Conditional
};
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Rest\ReorderController;
use Saltus\WP\Framework\Rest\RestRouteDefinition;
use Saltus\WP\Framework\Rest\RestRouteProvider;


/**
 * Class DragAndDrop
 *
 * Enable an option to manage drag and drop functionality in the admin area.
 */
class DragAndDrop implements Service, Conditional, Actionable, Assembly, RestRouteProvider {

	/**
	 * Instantiate this Service object.
	 *
	 */
	public function __construct() {}

	/**
	 * Check whether the conditional service is currently needed.
	 *
	 * @return bool Whether the conditional service is needed.
	 */
	public static function is_needed(): bool {
		return is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/**
	 * Create a new instance of the service provider
	 *
	 * @return object The new instance
	 */
	public static function make( string $name, array $project, array $args ): object {
		return new SaltusDragAndDrop( $name, $project );
	}

	/**
	 * Update menu drag and drop in the database
	 *
	 */
	public function add_action(): void {
		$actions = new UpdateMenuDragAndDrop();
		$actions->add_action();
	}

	/**
	 * @return list<RestRouteDefinition>
	 */
	public function get_rest_routes( Modeler $modeler, ModelRestPolicy $policy ): array {
		return [
			new RestRouteDefinition(
				ModelRestPolicy::CAPABILITY_REORDER,
				new ReorderController( $policy ),
				'post_type'
			),
		];
	}
}
