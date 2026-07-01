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
}
