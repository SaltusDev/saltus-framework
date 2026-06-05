<?php
namespace Saltus\WP\Framework\MCP\Tools;

class ToolProvider {

	/** @var ToolInterface[] */
	private array $tools = [];

	public function register( ToolInterface $tool ): void {
		$this->tools[ $tool->getName() ] = $tool;
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
	*/
	public function getDefinitions(): array {
		$definitions = [];
		foreach ( $this->tools as $tool ) {
			$definitions[] = [
				'name'        => $tool->getName(),
				'description' => $tool->getDescription(),
				'inputSchema' => $tool->getParameters(),
			];
		}

		return $definitions;
	}
}
