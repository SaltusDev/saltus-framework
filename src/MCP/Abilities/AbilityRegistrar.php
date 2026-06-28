<?php
namespace Saltus\WP\Framework\MCP\Abilities;

use Saltus\WP\Framework\MCP\Tools\ToolFactory;
use Saltus\WP\Framework\MCP\Tools\ToolProvider;

class AbilityRegistrar {

	private ToolProvider $toolProvider;
	private AbilityDefinitionFactory $definitionFactory;

	public function __construct( ?ToolProvider $toolProvider = null, ?AbilityDefinitionFactory $definitionFactory = null ) {
		$this->toolProvider      = $toolProvider ?? ToolFactory::createDefaultProvider();
		$this->definitionFactory = $definitionFactory ?? new AbilityDefinitionFactory();
	}

	public function hasNativeApi(): bool {
		return function_exists( 'wp_register_ability' );
	}

	public function registerCategory(): void {
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
		if ( ! $this->hasNativeApi() ) {
			return [];
		}

		$registered = [];
		foreach ( $this->definitionFactory->fromToolProvider( $this->toolProvider ) as $definition ) {
			$name = (string) $definition['name'];
			$args = $definition;
			unset( $args['name'] );

			wp_register_ability( $name, $args );

			$registered[] = $name;
		}

		return $registered;
	}
}
