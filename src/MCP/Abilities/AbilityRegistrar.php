<?php
namespace Saltus\WP\Framework\MCP\Abilities;

use Saltus\WP\Framework\MCP\Tools\ToolFactory;
use Saltus\WP\Framework\MCP\Tools\ToolProvider;

class AbilityRegistrar {

	private ToolProvider $tool_provider;
	private AbilityDefinitionFactory $definition_factory;

	public function __construct( ?ToolProvider $tool_provider = null, ?AbilityDefinitionFactory $definition_factory = null ) {
		$this->tool_provider      = $tool_provider ?? ToolFactory::create_default_provider();
		$this->definition_factory = $definition_factory ?? new AbilityDefinitionFactory();
	}

	public function has_native_api(): bool {
		return function_exists( 'wp_register_ability' );
	}

	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			'saltus-framework',
			[
				'label'       => 'Saltus Framework',
				'description' => 'Saltus Framework content modeling and administration abilities.',
			]
		);
	}

	/**
	 * @return list<string>
	 */
	public function register(): array {
		if ( ! $this->has_native_api() ) {
			return [];
		}

		$registered = [];
		foreach ( $this->definition_factory->from_tool_provider( $this->tool_provider ) as $definition ) {
			$name = (string) $definition['name'];
			$args = $definition;
			unset( $args['name'] );

			wp_register_ability( $name, $args );

			$registered[] = $name;
		}

		return $registered;
	}
}
