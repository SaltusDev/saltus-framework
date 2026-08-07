<?php

namespace Saltus\WP\Framework\WebMcp;

/**
 * Implemented by tools that declare their own WebMCP agent annotations.
 *
 * Tools that do not implement this are annotated as state-changing and
 * content-bearing, which is the conservative default.
 * @api
 */
interface WebMcpAnnotated {

	/**
	 * Get the WebMCP annotations for this tool.
	 *
	 * Recognized keys are `readOnlyHint` and `untrustedContentHint`.
	 *
	 * @return array<string, bool>
	 */
	public function get_annotations(): array;
}
