<?php
namespace Saltus\WP\Framework\MCP;

/**
 * Central configuration for MCP naming.
 *
 * All values are filterable so plugins can customise the MCP server name,
 * REST namespace, ability category, and ability name prefix without
 * modifying framework source files.
 * @api
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
		$namespace = \apply_filters(
			'saltus/framework/mcp/namespace',
			'saltus-framework/v1'
		);
		if ( ! is_string( $namespace ) || trim( $namespace ) === '' ) {
			return 'saltus-framework/v1';
		}
		/** @var non-falsy-string */
		return trim( $namespace );
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
		$default = [
			'id'          => 'saltus-framework',
			'label'       => 'Saltus Framework',
			'description' => 'Saltus Framework content modeling and administration abilities.',
		];

		$filtered = \apply_filters(
			'saltus/framework/mcp/ability_category',
			$default
		);

		if ( ! is_array( $filtered ) ) {
			return $default;
		}

		$sanitized = [];
		foreach ( [ 'id', 'label', 'description' ] as $key ) {
			$val               = $filtered[ $key ] ?? null;
			$sanitized[ $key ] = is_string( $val ) ? $val : $default[ $key ];
		}

		/** @var array{id: string, label: string, description: string} */
		return $sanitized;
	}

	/**
	 * Get the prefix used when generating ability names from tool names.
	 *
	 * Default: 'saltus/'
	 *
	 * @return string
	 */
	public static function get_ability_prefix(): string {
		$prefix = \apply_filters(
			'saltus/framework/mcp/ability_prefix',
			'saltus/'
		);
		if ( ! is_string( $prefix ) ) {
			return 'saltus/';
		}
		return $prefix;
	}
}
