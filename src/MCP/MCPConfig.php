<?php
namespace Saltus\WP\Framework\MCP;

/**
 * Central configuration for MCP naming.
 *
 * All values are filterable so plugins can customise the MCP server name,
 * REST namespace, ability category, and ability name prefix without
 * modifying framework source files.
 */
class MCPConfig {

	/**
	 * Get the REST API namespace used for route registration and dispatch.
	 *
	 * Default: 'saltus-framework/v1'
	 *
	 * @return non-falsy-string
	 */
	public static function get_namespace(): string {
		/** @var non-falsy-string */
		return (string) \apply_filters(
			'saltus/framework/mcp/namespace',
			'saltus-framework/v1'
		);
	}

	/**
	 * Get the ability category registration array.
	 *
	 * Default:
	 * [
	 *     'id'          => 'saltus-framework',
	 *     'label'       => 'Saltus Framework',
	 *     'description' => 'Saltus Framework content modeling and administration abilities.',
	 * ]
	 *
	 * @return array{id: string, label: string, description: string}
	 */
	public static function get_ability_category(): array {
		/** @var array{id: string, label: string, description: string} */
		return (array) \apply_filters(
			'saltus/framework/mcp/ability_category',
			[
				'id'          => 'saltus-framework',
				'label'       => 'Saltus Framework',
				'description' => 'Saltus Framework content modeling and administration abilities.',
			]
		);
	}

	/**
	 * Get the prefix used when generating ability names from tool names.
	 *
	 * Default: 'saltus/'
	 *
	 * @return string
	 */
	public static function get_ability_prefix(): string {
		return (string) \apply_filters(
			'saltus/framework/mcp/ability_prefix',
			'saltus/'
		);
	}
}
