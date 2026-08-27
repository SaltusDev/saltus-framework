<?php

namespace Saltus\WP\Framework\WebMcp;

use Saltus\WP\Framework\MCP\Tools\ToolInterface;
use Saltus\WP\Framework\MCP\Validation\ParameterSchema;

/**
 * Projects Saltus tool definitions into WebMCP descriptors.
 *
 * Saltus tools return a bare property map from `get_parameters()`. WebMCP
 * expects a full JSON Schema object, so the map is wrapped by the shared
 * {@see ParameterSchema} projection rather than by every tool.
 *
 * Character budgets are enforced because agents apply their own limits and
 * silently degrade past them. See docs/discovery/webmcp.md.
 * @api
 */
final class ManifestBuilder {

	/** Maximum characters in a tool description before truncation. */
	public const MAX_DESCRIPTION = 500;

	/** Maximum characters in a parameter description before truncation. */
	public const MAX_PARAM_DESCRIPTION = 150;

	/** Maximum characters in a tool name. Longer names are rejected outright. */
	public const MAX_NAME = 30;

	/**
	 * Build descriptors for a list of tools.
	 *
	 * @param list<ToolInterface> $tools     Tools to project.
	 * @param string|null         $post_type Post type context, passed to the filter.
	 * @return list<ToolDescriptor>
	 */
	public function build( array $tools, ?string $post_type = null ): array {
		$descriptors = [];

		foreach ( $tools as $tool ) {
			$descriptor = $this->describe( $tool );
			if ( $descriptor === null ) {
				continue;
			}

			$descriptors[] = $descriptor;
		}

		/**
		 * Filter the WebMCP tool descriptors exposed to the browser.
		 *
		 * @param list<ToolDescriptor> $descriptors Projected descriptors.
		 * @param string|null          $post_type   Post type context, if any.
		 */
		return $this->accept_descriptors(
			apply_filters( 'saltus/framework/webmcp/manifest', $descriptors, $post_type ),
			$descriptors
		);
	}

	/**
	 * Narrow a filtered value back to a list of descriptors.
	 *
	 * A filter callback may return anything, so the result is validated
	 * rather than trusted, falling back to the unfiltered list.
	 *
	 * @param mixed                $filtered Filter return value.
	 * @param list<ToolDescriptor> $fallback Descriptors to use when unusable.
	 * @return list<ToolDescriptor>
	 */
	private function accept_descriptors( $filtered, array $fallback ): array {
		if ( ! is_array( $filtered ) ) {
			return $fallback;
		}

		$valid = [];
		foreach ( $filtered as $descriptor ) {
			if ( $descriptor instanceof ToolDescriptor ) {
				$valid[] = $descriptor;
			}
		}

		return $valid;
	}

	/**
	 * Project one tool into a descriptor.
	 *
	 * Returns null when the tool cannot be represented — an over-long name
	 * would be silently truncated by the agent and become uncallable.
	 *
	 * @param ToolInterface $tool Tool to project.
	 */
	public function describe( ToolInterface $tool ): ?ToolDescriptor {
		$name = $tool->get_name();
		if ( $name === '' || strlen( $name ) > self::MAX_NAME ) {
			return null;
		}

		return new ToolDescriptor(
			$name,
			$this->truncate( $tool->get_description(), self::MAX_DESCRIPTION ),
			$this->input_schema( $tool->get_parameters() ),
			$this->annotations( $tool )
		);
	}

	/**
	 * Serialize descriptors for transport.
	 *
	 * @param list<ToolDescriptor> $descriptors Descriptors to serialize.
	 * @return list<array<string, mixed>>
	 */
	public function to_array( array $descriptors ): array {
		return array_map(
			static fn( ToolDescriptor $descriptor ): array => $descriptor->to_array(),
			$descriptors
		);
	}

	/**
	 * Wrap a Saltus parameter map in a JSON Schema object.
	 *
	 * The wrapping itself is shared with the abilities surface; only the agent
	 * character budget on parameter descriptions is WebMCP's own.
	 *
	 * @param array<string, mixed> $parameters Saltus parameter definitions.
	 * @return array<string, mixed> JSON Schema object.
	 */
	private function input_schema( array $parameters ): array {
		foreach ( $parameters as $name => $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$description = $definition['description'] ?? null;
			if ( is_string( $description ) ) {
				$definition['description'] = $this->truncate( $description, self::MAX_PARAM_DESCRIPTION );
				$parameters[ $name ]       = $definition;
			}
		}

		return ParameterSchema::to_json_schema( $parameters );
	}

	/**
	 * Resolve agent annotations for a tool.
	 *
	 * Tools may declare their own hints; anything else is treated as
	 * state-changing and content-bearing, which is the safe default — a
	 * missing `readOnlyHint` makes the agent more likely to confirm, and a
	 * present `untrustedContentHint` makes it scrutinize output.
	 *
	 * @param ToolInterface $tool Tool to inspect.
	 * @return array<string, bool>
	 */
	private function annotations( ToolInterface $tool ): array {
		if ( $tool instanceof WebMcpAnnotated ) {
			return $tool->get_annotations();
		}

		return [
			'readOnlyHint'         => false,
			'untrustedContentHint' => true,
		];
	}

	/**
	 * Truncate a string to a character budget without cutting mid-word.
	 *
	 * @param string $value  Value to truncate.
	 * @param int    $budget Maximum length.
	 */
	private function truncate( string $value, int $budget ): string {
		if ( strlen( $value ) <= $budget ) {
			return $value;
		}

		$clipped = substr( $value, 0, $budget );
		$break   = strrpos( $clipped, ' ' );

		return $break === false ? $clipped : substr( $clipped, 0, $break );
	}
}
