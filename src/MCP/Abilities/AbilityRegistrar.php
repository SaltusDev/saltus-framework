<?php
namespace Saltus\WP\Framework\MCP\Abilities;

use Saltus\WP\Framework\MCP\Tools\ToolFactory;
use Saltus\WP\Framework\MCP\Tools\ToolProvider;
use Saltus\WP\Framework\Rest\ModelRestPolicy;

class AbilityRegistrar {

	private ToolProvider $tool_provider;
	private AbilityDefinitionFactory $definition_factory;
	private ?ModelRestPolicy $policy;

	public function __construct( ?ToolProvider $tool_provider = null, ?AbilityDefinitionFactory $definition_factory = null, ?ModelRestPolicy $policy = null ) {
		$this->tool_provider      = $tool_provider ?? ToolFactory::create_default_provider();
		$this->definition_factory = $definition_factory ?? new AbilityDefinitionFactory();
		$this->policy             = $policy;
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
			if ( ! $this->is_enabled_definition( $definition ) ) {
				continue;
			}

			$name = (string) $definition['name'];
			$args = $definition;
			unset( $args['name'] );

			wp_register_ability( $name, $args );

			$registered[] = $name;
		}

		return $registered;
	}

	/**
	 * @param array<string, mixed> $definition
	 */
	private function is_enabled_definition( array $definition ): bool {
		if ( ! $this->policy ) {
			return true;
		}

		$tool_name = (string) ( $definition['meta']['mcp_tool'] ?? '' );
		$map       = [
			'list_models'      => [ ModelRestPolicy::CAPABILITY_MODELS, null ],
			'get_model'        => [ ModelRestPolicy::CAPABILITY_MODELS, null ],
			'duplicate_post'   => [ ModelRestPolicy::CAPABILITY_DUPLICATE, 'post_type' ],
			'export_post'      => [ ModelRestPolicy::CAPABILITY_EXPORT, 'post_type' ],
			'get_settings'     => [ ModelRestPolicy::CAPABILITY_SETTINGS, 'post_type' ],
			'update_settings'  => [ ModelRestPolicy::CAPABILITY_SETTINGS, 'post_type' ],
			'reorder_posts'    => [ ModelRestPolicy::CAPABILITY_REORDER, 'post_type' ],
			'list_meta_fields' => [ ModelRestPolicy::CAPABILITY_META, 'post_type' ],
			'get_meta_fields'  => [ ModelRestPolicy::CAPABILITY_META, 'post_type' ],
		];

		if ( ! isset( $map[ $tool_name ] ) ) {
			return true;
		}

		[ $capability, $model_type ] = $map[ $tool_name ];

		return $this->policy->has_capability( $capability, $model_type );
	}
}
