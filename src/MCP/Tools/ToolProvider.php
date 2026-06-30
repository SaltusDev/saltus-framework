<?php
namespace Saltus\WP\Framework\MCP\Tools;

class ToolProvider {

	/** @var ToolInterface[] */
	private array $tools = [];

	public function register( ToolInterface $tool ): void {
		$this->tools[ $tool->get_name() ] = $tool;
	}

	public function get( string $name ): ?ToolInterface {
		return $this->tools[ $name ] ?? null;
	}

	/**
	* @return ToolInterface[]
	*/
	public function all(): array {
		return $this->tools;
	}

	/**
	* Get all tool definitions for MCP tools/list response.
	*
	* @return list<array{name: string, description: string, inputSchema: array<string, mixed>}>
	*/
	public function get_definitions(): array {
		$definitions = [];
		foreach ( $this->tools as $tool ) {
			$definitions[] = [
				'name'        => $tool->get_name(),
				'description' => $tool->get_description(),
				'inputSchema' => $tool->get_parameters(),
			];
		}

		return $definitions;
	}
}
