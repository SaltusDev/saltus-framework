<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\MCP\Client\WordPressClient;

interface ToolInterface {

	/**
	* Get the tool name (used in MCP protocol).
	*/
	public function get_name(): string;

	/**
	* Get the tool description for the AI.
	*/
	public function get_description(): string;

	/**
	* Get the JSON Schema for tool parameters.
	*
	* @return array<string, mixed>
	*/
	public function get_parameters(): array;

	/**
	* Execute the tool with given arguments.
	*
	* @param array<string, mixed> $args Tool arguments from the AI.
	* @param WordPressClient $client WP REST API client.
	* @return array<string, mixed> Result data (will be wrapped in content).
	*/
	public function handle( array $args, WordPressClient $client ): array;
}
