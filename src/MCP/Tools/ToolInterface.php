<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\MCP\Client\WordPressClient;

interface ToolInterface {

	/**
	* Get the tool name (used in MCP protocol).
	*/
	public function getName(): string;

	/**
	* Get the tool description for the AI.
	*/
	public function getDescription(): string;

	/**
	* Get the JSON Schema for tool parameters.
	*/
	public function getParameters(): array;

	/**
	* Execute the tool with given arguments.
	*
	* @param array $args Tool arguments from the AI.
	* @param WordPressClient $client WP REST API client.
	* @return array Result data (will be wrapped in content).
	*/
	public function handle( array $args, WordPressClient $client ): array;
}
