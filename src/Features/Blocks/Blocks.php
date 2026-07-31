<?php
namespace Saltus\WP\Framework\Features\Blocks;

use Saltus\WP\Framework\Infrastructure\Plugin\Registerable;
use Saltus\WP\Framework\Infrastructure\Service\Service;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\MCP\Tools\ListBlockModels;
use Saltus\WP\Framework\MCP\Tools\ToolContributor;
use Saltus\WP\Framework\MCP\Tools\ToolInterface;
use Saltus\WP\Framework\Rest\BlocksController;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Rest\RestRouteDefinition;
use Saltus\WP\Framework\Rest\RestRouteProvider;

/**
 * Registers model-driven dynamic blocks and their discovery surfaces.
 * @api
 */
final class Blocks implements Service, Registerable, RestRouteProvider, ToolContributor {

	/** @var callable|null */
	private $modeler_resolver;
	/** @var array<string, mixed> */
	private array $project;

	/**
	 * @param array<string, mixed> $dependencies Framework dependencies.
	 */
	public function __construct( array $dependencies = [] ) {
		$this->modeler_resolver = is_callable( $dependencies['modeler_resolver'] ?? null ) ? $dependencies['modeler_resolver'] : null;
		$this->project          = is_array( $dependencies['project'] ?? null ) ? $dependencies['project'] : [];
	}

	public function register(): void {
		add_action(
			'init',
			function (): void {
				$modeler = $this->resolve_modeler();
				if ( $modeler instanceof Modeler ) {
					( new SaltusBlocks( $modeler, $this->project ) )->register();
				}
			},
			20
		);
	}

	/** @return list<RestRouteDefinition> */
	public function get_rest_routes( Modeler $modeler, ModelRestPolicy $policy ): array {
		return [
			new RestRouteDefinition(
				ModelRestPolicy::CAPABILITY_BLOCKS,
				new BlocksController( $modeler, $policy ),
				'post_type'
			),
		];
	}

	/** @return list<ToolInterface> */
	public function get_mcp_tools( Modeler $modeler, ?ModelRestPolicy $policy = null ): array {
		return [ new ListBlockModels() ];
	}

	private function resolve_modeler(): ?Modeler {
		if ( ! is_callable( $this->modeler_resolver ) ) {
			return null;
		}

		$modeler = ( $this->modeler_resolver )();
		return $modeler instanceof Modeler ? $modeler : null;
	}
}
