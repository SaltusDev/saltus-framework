<?php
namespace Saltus\WP\Framework\MCP\Abilities;

use Saltus\WP\Framework\MCP\Tools\RestBackedToolInterface;
use Saltus\WP\Framework\MCP\Tools\ToolInterface;
use Saltus\WP\Framework\MCP\Tools\ToolProvider;
use Saltus\WP\Framework\Rest\ModelRestPolicy;

/**
 * Registers MCP abilities with the WordPress native wp_register_ability API.
 *
 * @phpstan-import-type AbilityDefinition from \Saltus\WP\Framework\MCP\Abilities\AbilityDefinitionFactory
 */
class AbilityRegistrar {

	private ToolProvider $tool_provider;
	private AbilityDefinitionFactory $definition_factory;
	private ?ModelRestPolicy $policy;

	/**
	 * @param ToolProvider|null $tool_provider  Optional injected tool provider.
	 * @param AbilityDefinitionFactory|null $definition_factory  Optional definition factory.
	 * @param ModelRestPolicy|null $policy  Optional REST policy for capability gating.
	 */
	public function __construct( ?ToolProvider $tool_provider = null, ?AbilityDefinitionFactory $definition_factory = null, ?ModelRestPolicy $policy = null ) {
		$this->tool_provider      = $tool_provider ?? new ToolProvider();
		$this->definition_factory = $definition_factory ?? new AbilityDefinitionFactory();
		$this->policy             = $policy;
	}

	/**
	 * Check whether the WordPress native wp_register_ability API is available.
	 *
	 * @return bool  True if the API function exists.
	 */
	public function has_native_api(): bool {
		return function_exists( 'wp_register_ability' );
	}

	/**
	 * Register the saltus-framework ability category.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		\wp_register_ability_category(
			'saltus-framework',
			[
				'label'       => 'Saltus Framework',
				'description' => 'Saltus Framework content modeling and administration abilities.',
			]
		);
	}

	/**
	 * Register all enabled tools with the WordPress ability API.
	 *
	 * @return list<string>  Names of the registered abilities.
	 */
	public function register(): array {
		if ( ! $this->has_native_api() ) {
			return [];
		}

		$registered = [];
		foreach ( $this->tool_provider->all() as $tool ) {
			if ( ! $this->is_enabled_tool( $tool ) ) {
				continue;
			}

			$definition = $this->definition_factory->from_tool( $tool );
			$name       = (string) $definition['name'];
			$args       = $definition;
			unset( $args['name'] );

			wp_register_ability( $name, $args );

			$registered[] = $name;
		}

		return $registered;
	}

	/**
	 * Check whether a tool is enabled based on the model REST policy.
	 *
	 * @param ToolInterface $tool  The tool to check.
	 * @return bool
	 */
	private function is_enabled_tool( ToolInterface $tool ): bool {
		if ( ! $this->policy ) {
			return true;
		}

		if ( ! $tool instanceof RestBackedToolInterface ) {
			return true;
		}

		$requirement = $tool->get_rest_capability();
		if ( $requirement === null ) {
			return true;
		}

		return $this->policy->has_capability( $requirement->get_capability(), $requirement->get_model_type() );
	}
}
