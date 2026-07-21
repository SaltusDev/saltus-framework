<?php
namespace Saltus\WP\Framework\MCP\Tools;

/**
 * @api
 */
interface ToolInterface {

	/**
	 * Get the tool name (used in MCP protocol).
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Get the tool description for the AI.
	 *
	 * @return string
	 */
	public function get_description(): string;

	/**
	 * Get the JSON Schema for tool parameters.
	 *
	 * @return array<string, mixed>
	 */
	public function get_parameters(): array;

	/**
	 * Check whether the current user can use this tool with the given arguments.
	 *
	 * @param array<string, mixed> $args  Ability arguments.
	 * @return bool
	 */
	public function has_permission( array $args ): bool;
}
