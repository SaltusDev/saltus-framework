<?php

namespace Saltus\WP\Framework\WebMcp;

use Saltus\WP\Framework\MCP\Tools\ToolInterface;

/**
 * Contract for a tool the WebMCP bridge can register and invoke.
 *
 * `ToolInterface` describes a tool well enough to *project* it into a
 * descriptor, but not well enough to *run* it from the browser: the execute
 * route needs to know which surface a tool belongs to, whether the call has to
 * be authenticated, and how to invoke it. Those three answers live here, so the
 * controller holds one list rather than branching on concrete classes.
 *
 * Two surfaces exist. Frontend tools are callable by an anonymous visitor and
 * read published content only. Admin tools are callable by a capable, nonce-
 * authenticated user, and every mutating one is queued for human review rather
 * than applied.
 * @api
 */
interface WebMcpTool extends ToolInterface {

	/** Public views: anonymous callers, published content only. */
	public const SURFACE_FRONTEND = 'frontend';

	/** Admin screens: authenticated callers, capability gated. */
	public const SURFACE_ADMIN = 'admin';

	/**
	 * Which surface this tool belongs to.
	 *
	 * @return string One of the SURFACE_* constants.
	 */
	public function get_surface(): string;

	/**
	 * Whether an invocation must carry a valid REST nonce.
	 *
	 * Read tools on public views do not; anything reaching authenticated data
	 * or queueing a change does, so a cross-site request cannot act as the
	 * logged-in user.
	 */
	public function requires_authentication(): bool;

	/**
	 * Coarse capability a user needs before this tool is even listed.
	 *
	 * Discovery is deliberately separate from invocation. `has_permission()`
	 * answers "may this call proceed with these arguments" and often needs a
	 * target id, which the manifest has no way to supply. This answers the
	 * broader "should this user see the tool at all", so listing a tool never
	 * depends on inventing arguments for it. Null means no gate.
	 */
	public function get_discovery_capability(): ?string;

	/**
	 * Run the tool.
	 *
	 * Arguments arrive already validated against `get_parameters()` and past
	 * `has_permission()`. A returned payload carrying an `error` key of
	 * `{code, message, status}` is converted into a REST error by the
	 * controller, so a failed dispatch keeps its HTTP status instead of being
	 * reported to the agent as a successful call.
	 *
	 * @param array<string, mixed> $args Validated arguments.
	 * @return array<string, mixed> Result payload.
	 */
	public function execute( array $args ): array;
}
