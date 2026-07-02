<?php
namespace Saltus\WP\Framework\MCP\Tools;

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
}
