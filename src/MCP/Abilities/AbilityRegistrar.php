<?php
namespace Saltus\WP\Framework\MCP\Abilities;

use Saltus\WP\Framework\MCP\MCPConfig;
use Saltus\WP\Framework\MCP\McpPolicy;
use Saltus\WP\Framework\MCP\Tools\RestBackedToolInterface;
use Saltus\WP\Framework\MCP\Tools\ToolInterface;
use Saltus\WP\Framework\MCP\Tools\ToolProvider;

/**
 * Registers MCP abilities with the WordPress native wp_register_ability API.
 *
 * @phpstan-import-type AbilityDefinition from \Saltus\WP\Framework\MCP\Abilities\AbilityDefinitionFactory
 * @api
 */
class AbilityRegistrar {

	private ToolProvider $tool_provider;
	private AbilityDefinitionFactory $definition_factory;
	private ?McpPolicy $mcp_policy;

	/**
	 * @param ToolProvider|null $tool_provider  Optional injected tool provider.
	 * @param AbilityDefinitionFactory|null $definition_factory  Optional definition factory.
	 * @param McpPolicy|null $mcp_policy  Optional MCP policy for capability gating.
	 */
	public function __construct( ?ToolProvider $tool_provider = null, ?AbilityDefinitionFactory $definition_factory = null, ?McpPolicy $mcp_policy = null ) {
		$this->tool_provider      = $tool_provider ?? new ToolProvider();
		$this->definition_factory = $definition_factory ?? new AbilityDefinitionFactory();
		$this->mcp_policy         = $mcp_policy;
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

		$category = MCPConfig::get_ability_category();

		\wp_register_ability_category(
			$category['id'],
			[
				'label'       => $category['label'],
				'description' => $category['description'],
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
	 * Declare the `public` meta key so a client can filter the listing by it.
	 *
	 * WordPress declares `annotations` under `meta.properties` but leaves
	 * `public` to `additionalProperties`, which does not coerce. A query string
	 * carries `?meta[public]=true` as the string `'true'`, the meta matcher
	 * compares with `!==` against the real boolean the ability registered, and
	 * the request answers with nothing. Naming the type is what lets REST convert
	 * the value before the match, which is the stated purpose of this filter.
	 *
	 * Registering the callback is harmless on WordPress versions without the
	 * hook: it simply never fires.
	 *
	 * @param array<string, mixed> $params Collection parameters.
	 * @return array<string, mixed>
	 */
	public function declare_public_query_param( array $params ): array {
		if ( ! isset( $params['meta']['properties'] ) || ! is_array( $params['meta']['properties'] ) ) {
			return $params;
		}

		$params['meta']['properties']['public'] = [
			'description' => __( 'Limit results to abilities meant for clients such as the REST API, MCP, or AI agents.', 'saltus-framework' ),
			'type'        => 'boolean',
		];

		return $params;
	}

	/**
	 * Check whether a tool is enabled based on the MCP policy.
	 *
	 * @param ToolInterface $tool  The tool to check.
	 * @return bool
	 */
	private function is_enabled_tool( ToolInterface $tool ): bool {
		if ( ! $this->mcp_policy ) {
			return true;
		}

		if ( ! $tool instanceof RestBackedToolInterface ) {
			return true;
		}

		$requirement = $tool->get_rest_capability();
		if ( $requirement === null ) {
			return true;
		}

		return $this->mcp_policy->has_capability( $requirement->get_capability(), $requirement->get_model_type() );
	}
}
