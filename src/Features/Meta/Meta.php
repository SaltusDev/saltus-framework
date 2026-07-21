<?php
namespace Saltus\WP\Framework\Features\Meta;

use Saltus\WP\Framework\Infrastructure\Service\{
	Assembly,
	Service,
	Conditional
};
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\MCP\Tools\GetMetaFields;
use Saltus\WP\Framework\MCP\Tools\ListMetaFields;
use Saltus\WP\Framework\MCP\Tools\UpdateMetaFields;
use Saltus\WP\Framework\MCP\Tools\ToolContributor;
use Saltus\WP\Framework\MCP\Tools\ToolInterface;
use Saltus\WP\Framework\Rest\MetaController;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Rest\RestRouteDefinition;
use Saltus\WP\Framework\Rest\RestRouteProvider;

/**
 * Class Meta
 *
 * Enable an option to manage meta fields
 * @api
 */
final class Meta implements Service, Conditional, Assembly, RestRouteProvider, ToolContributor {

	private MetaFieldProvider $meta_field_provider;

	/**
	 * Instantiate this Service object.
	 *
	 * @param mixed $meta_field_provider Optional shared meta provider; ignored when the service container passes args.
	 */
	public function __construct( $meta_field_provider = null ) {
		$this->meta_field_provider = $meta_field_provider instanceof MetaFieldProvider ? $meta_field_provider : new MetaFieldProvider();
	}

	/**
	 * Check whether the conditional service is currently needed.
	 *
	 * @return bool Whether the conditional service is needed.
	 */
	public static function is_needed(): bool {
		/*
		 * Load everywhere since its needed via REST API
		 */
		return true;
	}

	/**
	 * Create a new instance of the service provider
	 *
	 * @param string $name        The name of the custom post type (CPT)
	 * @param array<string, mixed> $project Project information.
	 * @param array<string, mixed> $args    Additional arguments.
	 *
	 * @return object The new instance
	 */
	public static function make( string $name, array $project, array $args ): object {
		return new CodestarMeta( $name, $args );
	}

	/**
	 * @return list<RestRouteDefinition>
	 */
	public function get_rest_routes( Modeler $modeler, ModelRestPolicy $policy ): array {
		return [
			new RestRouteDefinition(
				ModelRestPolicy::CAPABILITY_META,
				new MetaController( $modeler, $policy, $this->meta_field_provider ),
				'post_type'
			),
		];
	}

	/**
	 * @return list<ToolInterface>
	 */
	public function get_mcp_tools( Modeler $modeler, ?ModelRestPolicy $policy = null ): array {
		return [
			new ListMetaFields( $this->meta_field_provider ),
			new GetMetaFields( $this->meta_field_provider ),
			new UpdateMetaFields( $this->meta_field_provider ),
		];
	}
}
