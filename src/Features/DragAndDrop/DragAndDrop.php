<?php
namespace Saltus\WP\Framework\Features\DragAndDrop;

use Saltus\WP\Framework\Infrastructure\Service\{
	Assembly,
	Actionable,
	Service,
	Conditional
};
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\MCP\Tools\ReorderPosts;
use Saltus\WP\Framework\MCP\Tools\ToolContributor;
use Saltus\WP\Framework\MCP\Tools\ToolInterface;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Rest\ReorderController;
use Saltus\WP\Framework\Rest\RestRouteDefinition;
use Saltus\WP\Framework\Rest\RestRouteProvider;


/**
 * Class DragAndDrop
 *
 * Enable an option to manage drag and drop functionality in the admin area.
 * @api
 */
class DragAndDrop implements Service, Conditional, Actionable, Assembly, RestRouteProvider, ToolContributor {

	private ReorderPostsService $reorder_service;

	/**
	 * Instantiate this Service object.
	 *
	 * @param mixed $reorder_service Optional shared reorder service; ignored when the service container passes args.
	 */
	public function __construct( $reorder_service = null ) {
		$this->reorder_service = $reorder_service instanceof ReorderPostsService ? $reorder_service : new ReorderPostsService();
	}

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
				new ReorderController( $policy, $this->reorder_service ),
				'post_type'
			),
		];
	}

	/**
	 * @return list<ToolInterface>
	 */
	public function get_mcp_tools( Modeler $modeler, ?ModelRestPolicy $policy = null ): array {
		return [ new ReorderPosts( $this->reorder_service ) ];
	}
}
