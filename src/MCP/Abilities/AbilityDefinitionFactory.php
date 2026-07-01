<?php
namespace Saltus\WP\Framework\MCP\Abilities;

use Saltus\WP\Framework\MCP\Tools\ToolInterface;
use Saltus\WP\Framework\MCP\Tools\ToolProvider;

/**
 * Converts ToolInterface instances into wp_register_ability-compatible definition arrays.
 *
 * @phpstan-type AbilityDefinition array{
 *     name: lowercase-string&non-falsy-string,
 *     label: string,
 *     description: string,
 *     category: string,
 *     input_schema: array<string, mixed>,
 *     inputSchema: array<string, mixed>,
 *     execute_callback: callable,
 *     permission_callback: callable,
 *     callback: callable,
 *     meta: array<string, mixed>
 * }
 */
class AbilityDefinitionFactory {

	private AbilityRuntime $runtime;

	/**
	 * @param AbilityRuntime|null $runtime  Optional runtime override.
	 */
	public function __construct( ?AbilityRuntime $runtime = null ) {
		$this->runtime = $runtime ?? new AbilityRuntime();
	}

	/**
	 * Generate ability definitions for all tools in a provider.
	 *
	 * @param ToolProvider $provider  The tool provider to generate abilities for.
	 * @return list<AbilityDefinition>
	 */
	public function from_tool_provider( ToolProvider $provider ): array {
		$definitions = [];

		foreach ( $provider->all() as $tool ) {
			$definitions[] = $this->from_tool( $tool );
		}

		return $definitions;
	}

	/**
	 * Generate an ability definition for a single tool.
	 *
	 * @param ToolInterface $tool  The tool to generate an ability for.
	 * @return AbilityDefinition
	 */
	public function from_tool( ToolInterface $tool ): array {
		$schema = $tool->get_parameters();

		return [
			'name'                => $this->ability_name( $tool->get_name() ),
			'label'               => $this->label_from_tool_name( $tool->get_name() ),
			'description'         => $tool->get_description(),
			'category'            => 'saltus-framework',
			'input_schema'        => $schema,
			'inputSchema'         => $schema,
			'execute_callback'    => function ( array $args = [] ) use ( $tool ) {
				return $this->runtime->execute( $tool, $args );
			},
			'permission_callback' => [ $this, 'can_use_saltus_abilities' ],
			'callback'            => function ( array $args = [] ) use ( $tool ) {
				return $this->runtime->execute( $tool, $args );
			},
			'meta'                => [
				'mcp_tool'     => $tool->get_name(),
				'namespace'    => 'saltus-framework/v1',
				'transport'    => 'wordpress-rest',
				'show_in_rest' => true,
			],
		];
	}

	/**
	 * Permission callback checking whether the current user can use Saltus abilities.
	 *
	 * @return bool
	 */
	public function can_use_saltus_abilities(): bool {
		return function_exists( 'current_user_can' ) && current_user_can( 'edit_posts' );
	}

	/**
	 * Convert a tool name to a namespaced ability name.
	 *
	 * @param string $tool_name  The raw tool name.
	 * @return lowercase-string&non-falsy-string
	 */
	private function ability_name( string $tool_name ): string {
		return strtolower( 'saltus/' . str_replace( '_', '-', $tool_name ) );
	}

	/**
	 * Convert a tool name to a human-readable label.
	 *
	 * @param string $tool_name  The raw tool name.
	 * @return string
	 */
	private function label_from_tool_name( string $tool_name ): string {
		return ucwords( str_replace( '_', ' ', $tool_name ) );
	}
}
