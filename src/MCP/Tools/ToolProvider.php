<?php
namespace Saltus\WP\Framework\MCP\Tools;

/**
 * Registry of ToolInterface instances.
 */
class ToolProvider {

	/** @var ToolInterface[] */
	private array $tools = [];

	/**
	 * Register a tool instance.
	 *
	 * @param ToolInterface $tool  The tool to register.
	 */
	public function register( ToolInterface $tool ): void {
		$this->tools[ $tool->get_name() ] = $tool;
	}

	/**
	 * Get a registered tool by name.
	 *
	 * @param string $name  The tool name.
	 * @return ToolInterface|null  The tool, or null if not found.
	 */
	public function get( string $name ): ?ToolInterface {
		return $this->tools[ $name ] ?? null;
	}

	/**
	 * Return all registered tools.
	 *
	 * @return ToolInterface[]
	 */
	public function all(): array {
		return $this->tools;
	}
}
