<?php

namespace Saltus\WP\Framework\WebMcp\Tools;

use Saltus\WP\Framework\Features\EditorialReview\ProposalService;
use Saltus\WP\Framework\MCP\Tools\RestBackedToolInterface;
use Saltus\WP\Framework\MCP\Tools\ToolInterface;
use Saltus\WP\Framework\WebMcp\WebMcpAnnotated;
use Saltus\WP\Framework\WebMcp\WebMcpTool;

/**
 * Projects an existing Saltus ability onto the admin WebMCP surface.
 *
 * This is a decorator, not a new tool. Name, description, parameters, and
 * capability checks all come from the ability already registered for
 * MCP/Abilities and `wp saltus`, so the browser surface cannot drift from the
 * other two consumers.
 *
 * What it adds is the write posture. WebMCP has no settled confirmation model —
 * `requestUserInteraction()` sits unresolved in the spec draft — so no mutating
 * call is ever applied here. `ProposalService` converts it into a `pending`
 * proposal and the agent receives its id and review URL, which is the answer
 * Saltus already shipped for MCP in Phase 6B.
 * @api
 */
final class AdminTool implements WebMcpTool, WebMcpAnnotated {

	/** Admin page slug of the editorial review queue. */
	private const REVIEW_PAGE = 'saltus-ai-review';

	private ToolInterface $tool;
	private ?ProposalService $proposals;

	/**
	 * @param ToolInterface        $tool      Ability to project.
	 * @param ProposalService|null $proposals Review queue, or null to disable queueing.
	 */
	public function __construct( ToolInterface $tool, ?ProposalService $proposals = null ) {
		$this->tool      = $tool;
		$this->proposals = $proposals;
	}

	public function get_name(): string {
		return $this->tool->get_name();
	}

	public function get_description(): string {
		$description = $this->tool->get_description();
		if ( ! $this->is_mutating() ) {
			return $description;
		}

		// Stated in the description because the agent plans against this text.
		// An agent that believes a write applied immediately will report the
		// task done; one that knows it queued will tell the user to review it.
		return rtrim( $description, '. ' ) . '. Queues the change for human review instead of applying it.';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_parameters(): array {
		return $this->tool->get_parameters();
	}

	/**
	 * @param array<string, mixed> $args Tool arguments.
	 */
	public function has_permission( array $args ): bool {
		return $this->tool->has_permission( $args );
	}

	public function get_surface(): string {
		return self::SURFACE_ADMIN;
	}

	/**
	 * Admin calls act as the logged-in user, so they carry a nonce.
	 */
	public function requires_authentication(): bool {
		return true;
	}

	/**
	 * Capability gating discovery, taken from the ability's own requirement.
	 *
	 * Falls back to `edit_posts` when a tool declares none: every current Saltus
	 * ability gates at least that far, so an unknown tool inherits the floor
	 * rather than becoming visible to subscribers.
	 */
	public function get_discovery_capability(): string {
		if ( $this->tool instanceof RestBackedToolInterface ) {
			$requirement = $this->tool->get_rest_capability();
			if ( $requirement !== null ) {
				return $requirement->get_capability();
			}
		}

		return 'edit_posts';
	}

	/**
	 * Read tools are marked read-only; writes are not, so agents confirm them.
	 *
	 * A queued write still changes state — a pending proposal appears for a human
	 * to act on — so claiming `readOnlyHint` would suppress the one confirmation
	 * signal the agent has.
	 *
	 * @return array<string, bool>
	 */
	public function get_annotations(): array {
		if ( $this->tool instanceof WebMcpAnnotated ) {
			return $this->tool->get_annotations();
		}

		return [
			'readOnlyHint'         => ! $this->is_mutating(),
			'untrustedContentHint' => true,
		];
	}

	/**
	 * Queue a mutation for review, or dispatch a read.
	 *
	 * @param array<string, mixed> $args Validated arguments.
	 * @return array<string, mixed>
	 */
	public function execute( array $args ): array {
		if ( $this->proposals instanceof ProposalService && $this->proposals->should_queue( $this->get_name() ) ) {
			return $this->review_payload( $this->proposals->propose( $this->get_name(), $args ) );
		}

		// A mutating tool must never reach dispatch without the queue. Losing the
		// service should fail the call, not silently downgrade it to a direct
		// write — that would turn a missing dependency into an unreviewed change.
		if ( $this->is_mutating() ) {
			return $this->error(
				'saltus_webmcp_review_unavailable',
				__( 'Changes cannot be queued for review right now, so this tool is unavailable.', 'saltus-framework' ),
				503
			);
		}

		return $this->dispatch( $args );
	}

	/**
	 * Whether this tool changes state.
	 */
	private function is_mutating(): bool {
		if ( ! $this->proposals instanceof ProposalService ) {
			// Without the service the mutation list is unavailable, so a
			// non-read-backed tool is assumed to write. Every Saltus read tool
			// is REST-backed and cacheable; mutating ones are not.
			return ! ( $this->tool instanceof RestBackedToolInterface && $this->tool->is_cacheable() );
		}

		return $this->proposals->is_mutating( $this->get_name() );
	}

	/**
	 * Add the human review URL to a proposal result.
	 *
	 * The agent gets a link it can hand to the user rather than an opaque id,
	 * so the review is one click away from where the conversation happened.
	 *
	 * @param array<string, mixed> $proposal Proposal payload.
	 * @return array<string, mixed>
	 */
	private function review_payload( array $proposal ): array {
		$id = (int) ( $proposal['proposal_id'] ?? 0 );
		if ( $id <= 0 ) {
			return $proposal;
		}

		$proposal['review_url'] = $this->review_url( $id );

		return $proposal;
	}

	/**
	 * Build the admin URL of the review queue for one proposal.
	 *
	 * @param int $id Proposal id.
	 */
	private function review_url( int $id ): string {
		if ( ! function_exists( 'admin_url' ) ) {
			return '';
		}

		$url = admin_url( 'tools.php?page=' . self::REVIEW_PAGE );
		if ( function_exists( 'add_query_arg' ) ) {
			return (string) add_query_arg( 'proposal', $id, $url );
		}

		return $url . '&proposal=' . $id;
	}

	/**
	 * Dispatch a read through the same REST route the ability uses.
	 *
	 * @param array<string, mixed> $args Validated arguments.
	 * @return array<string, mixed>
	 */
	private function dispatch( array $args ): array {
		if ( ! $this->tool instanceof RestBackedToolInterface ) {
			return $this->error(
				'saltus_webmcp_unsupported_tool',
				__( 'This tool cannot be called from the browser.', 'saltus-framework' ),
				501
			);
		}

		if ( ! function_exists( 'rest_do_request' ) ) {
			return $this->error(
				'saltus_webmcp_rest_unavailable',
				__( 'WordPress REST dispatch is not available.', 'saltus-framework' ),
				503
			);
		}

		$request = $this->tool->build_rest_request( $args );
		if ( $request === null ) {
			return $this->error(
				'saltus_webmcp_request_invalid',
				__( 'The tool request could not be built.', 'saltus-framework' ),
				400
			);
		}

		return $this->interpret( $this->dispatch_rest_request( $request ) );
	}

	/**
	 * Convert a dispatch outcome into a result or an error payload.
	 *
	 * @param mixed $response Dispatch return value.
	 * @return array<string, mixed>
	 */
	private function interpret( $response ): array {
		if ( is_wp_error( $response ) ) {
			return $this->error(
				(string) $response->get_error_code(),
				(string) $response->get_error_message(),
				500
			);
		}

		if ( ! $response instanceof \WP_REST_Response ) {
			return $this->error(
				'saltus_webmcp_dispatch_failed',
				__( 'The tool returned an invalid response.', 'saltus-framework' ),
				500
			);
		}

		$data   = $response->get_data();
		$status = (int) $response->get_status();

		if ( $status >= 400 ) {
			$fallback = __( 'The tool call failed.', 'saltus-framework' );

			return $this->error(
				is_array( $data ) ? (string) ( $data['code'] ?? 'saltus_webmcp_dispatch_failed' ) : 'saltus_webmcp_dispatch_failed',
				is_array( $data ) ? (string) ( $data['message'] ?? $fallback ) : $fallback,
				$status
			);
		}

		return is_array( $data ) ? $data : [ 'result' => $data ];
	}

	/**
	 * Dispatch a REST request.
	 *
	 * Wrapped so the `WP_Error` branch above stays reachable to static analysis:
	 * `rest_do_request()` can return one, but the WordPress stubs declare only
	 * `WP_REST_Response`. Mirrors `ProposalService::dispatch_rest_request()`.
	 *
	 * @param \WP_REST_Request $request Request to dispatch.
	 * @return mixed
	 */
	private function dispatch_rest_request( \WP_REST_Request $request ) {
		return rest_do_request( $request );
	}

	/**
	 * Build an error payload the controller converts into a REST error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status.
	 * @return array<string, mixed>
	 */
	private function error( string $code, string $message, int $status ): array {
		return [
			'error' => [
				'code'    => $code,
				'message' => $message,
				'status'  => $status,
			],
		];
	}
}
