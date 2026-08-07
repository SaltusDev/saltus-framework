<?php

namespace Saltus\WP\Framework\WebMcp;

/**
 * Value object holding one WebMCP tool descriptor.
 *
 * Mirrors the shape a page passes to `document.modelContext.registerTool()`:
 * a name, a description, a JSON Schema `inputSchema`, and agent annotations.
 * @api
 */
final class ToolDescriptor {

	private string $name;
	private string $description;
	/** @var array<string, mixed> */
	private array $input_schema;
	/** @var array<string, bool> */
	private array $annotations;

	/**
	 * @param string               $name         Tool name.
	 * @param string               $description  Natural-language description for the agent.
	 * @param array<string, mixed> $input_schema JSON Schema object describing arguments.
	 * @param array<string, bool>  $annotations  Agent hints (readOnlyHint, untrustedContentHint).
	 */
	public function __construct( string $name, string $description, array $input_schema, array $annotations = [] ) {
		$this->name         = $name;
		$this->description  = $description;
		$this->input_schema = $input_schema;
		$this->annotations  = $annotations;
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_description(): string {
		return $this->description;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_input_schema(): array {
		return $this->input_schema;
	}

	/**
	 * @return array<string, bool>
	 */
	public function get_annotations(): array {
		return $this->annotations;
	}

	/**
	 * Serialize for transport to the browser bridge.
	 *
	 * @return array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, bool>}
	 */
	public function to_array(): array {
		return [
			'name'        => $this->name,
			'description' => $this->description,
			'inputSchema' => $this->input_schema,
			'annotations' => $this->annotations,
		];
	}
}
