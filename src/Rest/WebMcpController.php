<?php

namespace Saltus\WP\Framework\Rest;

use Saltus\WP\Framework\Features\WebMcp\WebMcpPolicy;
use Saltus\WP\Framework\MCP\Audit\AuditEntry;
use Saltus\WP\Framework\MCP\Audit\AuditLogger;
use Saltus\WP\Framework\MCP\MCPConfig;
use Saltus\WP\Framework\MCP\RateLimiter\RateLimiter;
use Saltus\WP\Framework\MCP\Validation\Validator;
use Saltus\WP\Framework\WebMcp\ClientIdentity;
use Saltus\WP\Framework\WebMcp\ManifestBuilder;
use Saltus\WP\Framework\WebMcp\ResultBudget;
use Saltus\WP\Framework\WebMcp\WebMcpTool;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Serves the WebMCP tool manifest and executes tool calls from the browser.
 *
 * The browser is an untrusted client: arguments arrive from a language model,
 * so every call re-validates against the tool's own schema, is rate limited,
 * and is audit logged before the tool ever runs.
 * @api
 */
final class WebMcpController extends WP_REST_Controller {

	/** Requests allowed per window, per client, on the execute route. */
	private const RATE_LIMIT = 60;

	/** Rate limit window in seconds. */
	private const RATE_WINDOW = 60;

	/** Audit identifier prefix, so WebMCP calls are distinguishable from ability calls. */
	private const AUDIT_PREFIX = ClientIdentity::PREFIX;

	/**
	 * Capability floor for learning that an admin-only model exists.
	 *
	 * Matches AdminTool's discovery fallback, so a caller who would be offered no
	 * admin tool is not told which private types back them either.
	 */
	private const ADMIN_MODEL_CAPABILITY = 'edit_posts';

	private WebMcpPolicy $policy;
	private ManifestBuilder $manifest_builder;
	/** @var list<WebMcpTool> */
	private array $tools;
	private ?AuditLogger $audit;
	private RateLimiter $rate_limiter;
	private ClientIdentity $client;
	private ResultBudget $budget;

	/**
	 * @param WebMcpPolicy     $policy           Model gating policy.
	 * @param list<WebMcpTool> $tools            Tools available to the browser.
	 * @param ManifestBuilder|null $manifest_builder Descriptor projector.
	 * @param AuditLogger|null $audit            Audit logger, or null to skip logging.
	 * @param RateLimiter|null $rate_limiter     Rate limiter for the execute route.
	 * @param ClientIdentity|null $client        Client identity resolver for per-client limiting.
	 * @param ResultBudget|null $budget          Output budget clamp.
	 */
	public function __construct(
		WebMcpPolicy $policy,
		array $tools,
		?ManifestBuilder $manifest_builder = null,
		?AuditLogger $audit = null,
		?RateLimiter $rate_limiter = null,
		?ClientIdentity $client = null,
		?ResultBudget $budget = null
	) {
		$this->policy           = $policy;
		$this->tools            = $tools;
		$this->manifest_builder = $manifest_builder ?? new ManifestBuilder();
		$this->audit            = $audit;
		$this->rate_limiter     = $rate_limiter ?? new RateLimiter( self::RATE_LIMIT, self::RATE_WINDOW );
		$this->client           = $client ?? new ClientIdentity();
		$this->budget           = $budget ?? new ResultBudget();
		$this->namespace        = MCPConfig::get_namespace();
		$this->rest_base        = 'webmcp';
	}

	public function register_routes(): void {
		/** @var non-falsy-string $namespace */
		$namespace = $this->namespace;

		register_rest_route(
			$namespace,
			'/' . $this->rest_base . '/manifest',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_manifest' ],
				'permission_callback' => [ $this, 'manifest_permissions_check' ],
			]
		);

		register_rest_route(
			$namespace,
			'/' . $this->rest_base . '/execute',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'execute_tool' ],
				'permission_callback' => [ $this, 'execute_permissions_check' ],
			]
		);

		register_rest_route(
			$namespace,
			'/' . $this->rest_base . '/nonce',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_nonce' ],
				'permission_callback' => [ $this, 'nonce_permissions_check' ],
			]
		);
	}

	/**
	 * The manifest is public where a model opted into either surface.
	 *
	 * @param mixed $request Unused.
	 * @return bool|WP_Error
	 */
	public function manifest_permissions_check( $request ) {
		if ( ! $this->policy->has_frontend_surface() && ! $this->policy->has_admin_surface() ) {
			return new WP_Error(
				'saltus_webmcp_disabled',
				__( 'No content type exposes WebMCP tools.', 'saltus-framework' ),
				[ 'status' => 404 ]
			);
		}

		return true;
	}

	/**
	 * Execution is gated on the surface existing; per-tool checks run inside.
	 *
	 * The nonce and capability checks deliberately live in the callback rather
	 * than here: they are per-tool, and the requested tool is in the body, which
	 * a permission callback should not be reaching into to decide a 401.
	 *
	 * @param mixed $request Unused.
	 * @return bool|WP_Error
	 */
	public function execute_permissions_check( $request ) {
		return $this->manifest_permissions_check( $request );
	}

	/**
	 * Issuing a nonce requires an existing session to issue it for.
	 *
	 * @param mixed $request Unused.
	 * @return bool|WP_Error
	 */
	public function nonce_permissions_check( $request ) {
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return new WP_Error(
				'saltus_webmcp_not_logged_in',
				__( 'A WebMCP nonce is only issued to a logged-in user.', 'saltus-framework' ),
				[ 'status' => 401 ]
			);
		}

		return true;
	}

	/**
	 * Issue a fresh REST nonce for the current session.
	 *
	 * An admin screen left open beside an agent conversation will outlive its
	 * nonce. Without this the agent's next write fails and the user has to
	 * reload; with it the bridge re-fetches and retries once, invisibly.
	 *
	 * @param mixed $request Unused.
	 */
	public function get_nonce( $request ): WP_REST_Response {
		return rest_ensure_response(
			[
				'nonce' => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '',
			]
		);
	}

	/**
	 * Return the tool descriptors for the current context.
	 *
	 * @param mixed $request REST request.
	 */
	public function get_manifest( $request ): WP_REST_Response {
		$post_type   = $this->requested_post_type( $request );
		$descriptors = $this->manifest_builder->build( $this->available_tools( $post_type ), $post_type );

		return rest_ensure_response(
			[
				'tools'  => $this->manifest_builder->to_array( $descriptors ),
				'models' => $this->visible_models(),
			]
		);
	}

	/**
	 * Model slugs the current caller is allowed to learn about.
	 *
	 * The route is public, so the two surfaces cannot report the same list.
	 * `admin_models()` deliberately skips the publicly-queryable filter — an
	 * admin agent is a capable user, so a private post type is in scope for it —
	 * which means echoing `enabled_models()` unconditionally would hand an
	 * anonymous visitor the slugs of every private type on the site. Admin slugs
	 * are gated on the same capability floor that gates admin tool discovery, so
	 * `models` and `tools` agree about who is looking.
	 *
	 * @return list<string> Post type slugs.
	 */
	private function visible_models(): array {
		if ( ! $this->policy->has_admin_surface() || ! $this->can_see_admin_models() ) {
			return $this->policy->frontend_models();
		}

		return $this->policy->enabled_models();
	}

	/**
	 * Whether the caller is a logged-in user cleared for admin model slugs.
	 */
	private function can_see_admin_models(): bool {
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return false;
		}

		return function_exists( 'current_user_can' ) && current_user_can( self::ADMIN_MODEL_CAPABILITY );
	}

	/**
	 * Validate and run one tool call.
	 *
	 * @param mixed $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function execute_tool( $request ) {
		$params = $this->request_params( $request );
		$name   = isset( $params['tool'] ) && is_string( $params['tool'] ) ? $params['tool'] : '';
		$args   = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : [];

		// Resolved once and reused for both the rate-limit window and the audit
		// row, so a throttled call is attributable to the client it throttled.
		$client = $this->client->resolve();

		$tool = $this->find_tool( $name );
		if ( ! $tool instanceof WebMcpTool ) {
			return new WP_Error(
				'saltus_webmcp_unknown_tool',
				__( 'The requested tool is not available.', 'saltus-framework' ),
				[ 'status' => 404 ]
			);
		}

		$limit = $this->rate_limiter->check( $client );
		if ( ! $limit->allowed ) {
			$this->record( $name, $args, 'rate_limited', $client );

			return new WP_Error(
				'saltus_webmcp_rate_limited',
				__( 'Too many tool calls. Try again shortly.', 'saltus-framework' ),
				[
					'status'      => 429,
					'retry_after' => $limit->retry_after,
				]
			);
		}

		// Checked before validation so an expired nonce is reported as an expired
		// nonce. The bridge keys its silent refresh-and-retry on this code, and
		// would have no way to tell a stale session from bad arguments.
		$authenticated = $this->check_authentication( $tool, $request );
		if ( $authenticated instanceof WP_Error ) {
			$this->record( $name, $args, 'error', $client );

			return $authenticated;
		}

		// The browser is untrusted: re-validate against the tool's own schema
		// rather than relying on whatever the agent claims it sent.
		$validation = Validator::validate( $args, $tool->get_parameters() );
		if ( ! $validation['valid'] ) {
			$this->record( $name, $args, 'validation_error', $client );

			return new WP_Error(
				'saltus_webmcp_invalid_arguments',
				implode( '; ', $validation['errors'] ),
				[ 'status' => 400 ]
			);
		}

		if ( ! $tool->has_permission( $args ) ) {
			$this->record( $name, $args, 'error', $client );

			return new WP_Error(
				'saltus_webmcp_forbidden',
				__( 'You do not have permission to call this tool.', 'saltus-framework' ),
				[ 'status' => 403 ]
			);
		}

		$result = $tool->execute( $args );

		// A tool reporting a failed dispatch keeps its own status rather than
		// being handed to the agent as a successful call whose payload happens to
		// contain an error.
		$failure = $this->tool_error( $result );
		if ( $failure instanceof WP_Error ) {
			$this->record( $name, $args, 'error', $client );

			return $failure;
		}

		// Clamped before it leaves the server: an oversized payload is cut
		// mid-token by the agent's own limit, which corrupts the JSON it reads.
		$result = $this->budget->apply( $result );
		$this->record( $name, $args, 'success', $client );

		return rest_ensure_response(
			[
				'tool'   => $name,
				'result' => $result,
			]
		);
	}

	/**
	 * Enforce nonce authentication for tools that require it.
	 *
	 * A read tool on a public view needs nothing. Anything acting as the
	 * logged-in user needs a valid `wp_rest` nonce, or a cross-site page could
	 * drive the admin surface using the visitor's cookies.
	 *
	 * @param WebMcpTool $tool    Tool being invoked.
	 * @param mixed      $request REST request.
	 * @return true|WP_Error
	 */
	private function check_authentication( WebMcpTool $tool, $request ) {
		if ( ! $tool->requires_authentication() ) {
			return true;
		}

		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return new WP_Error(
				'saltus_webmcp_not_logged_in',
				__( 'This tool requires a logged-in user.', 'saltus-framework' ),
				[ 'status' => 401 ]
			);
		}

		if ( ! $this->has_valid_nonce( $request ) ) {
			return new WP_Error(
				'saltus_webmcp_invalid_nonce',
				__( 'The security token is missing or expired. Request a new one and retry.', 'saltus-framework' ),
				[
					'status'  => 403,
					'refresh' => true,
				]
			);
		}

		return true;
	}

	/**
	 * Whether the request carries a valid REST nonce.
	 *
	 * @param mixed $request REST request.
	 */
	private function has_valid_nonce( $request ): bool {
		if ( ! function_exists( 'wp_verify_nonce' ) ) {
			return false;
		}

		return (bool) wp_verify_nonce( $this->request_nonce( $request ), 'wp_rest' );
	}

	/**
	 * Read the nonce from the request header, falling back to a parameter.
	 *
	 * @param mixed $request REST request.
	 */
	private function request_nonce( $request ): string {
		if ( is_object( $request ) && method_exists( $request, 'get_header' ) ) {
			$header = $request->get_header( 'x_wp_nonce' );
			if ( is_string( $header ) && $header !== '' ) {
				return $header;
			}
		}

		$params = $this->request_params( $request );
		$nonce  = $params['_wpnonce'] ?? '';

		return is_string( $nonce ) ? $nonce : '';
	}

	/**
	 * Convert a tool's error payload into a REST error.
	 *
	 * @param array<string, mixed> $result Tool result.
	 * @return WP_Error|null
	 */
	private function tool_error( array $result ): ?WP_Error {
		$error = $result['error'] ?? null;
		if ( ! is_array( $error ) ) {
			return null;
		}

		$status = isset( $error['status'] ) ? (int) $error['status'] : 500;

		return new WP_Error(
			isset( $error['code'] ) ? (string) $error['code'] : 'saltus_webmcp_tool_failed',
			isset( $error['message'] ) ? (string) $error['message'] : __( 'The tool call failed.', 'saltus-framework' ),
			[ 'status' => $status > 0 ? $status : 500 ]
		);
	}

	/**
	 * Resolve the tools available for a post type context.
	 *
	 * Admin tools are listed only to a user who could actually call them, so an
	 * agent is never handed a tool that will refuse every invocation.
	 *
	 * @param string|null $post_type Post type context, or null for site-wide.
	 * @return list<WebMcpTool>
	 */
	private function available_tools( ?string $post_type ): array {
		$available = [];

		foreach ( $this->tools as $tool ) {
			$surface = $tool->get_surface();

			if ( $surface === WebMcpTool::SURFACE_ADMIN && ! $this->can_discover( $tool ) ) {
				continue;
			}

			if ( $post_type !== null && ! $this->policy->allows_tool( $post_type, $tool->get_name() ) ) {
				continue;
			}

			if ( $post_type === null && ! $this->policy->allowed_by_any_model( $tool->get_name(), $surface ) ) {
				continue;
			}

			$available[] = $tool;
		}

		return $available;
	}

	/**
	 * Whether the current user may see an admin tool.
	 *
	 * @param WebMcpTool $tool Tool to check.
	 */
	private function can_discover( WebMcpTool $tool ): bool {
		$capability = $tool->get_discovery_capability();
		if ( $capability === null ) {
			return true;
		}

		return function_exists( 'current_user_can' ) && current_user_can( $capability );
	}

	/**
	 * Find an available tool by name.
	 *
	 * @param string $name Tool name.
	 */
	private function find_tool( string $name ): ?WebMcpTool {
		if ( $name === '' ) {
			return null;
		}

		foreach ( $this->available_tools( null ) as $tool ) {
			if ( $tool->get_name() === $name ) {
				return $tool;
			}
		}

		return null;
	}

	/**
	 * Read the requested post type context from the request.
	 *
	 * @param mixed $request REST request.
	 */
	private function requested_post_type( $request ): ?string {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_param' ) ) {
			return null;
		}

		$post_type = $request->get_param( 'post_type' );

		return is_string( $post_type ) && $post_type !== '' ? $post_type : null;
	}

	/**
	 * Read body parameters, falling back to query parameters.
	 *
	 * @param mixed $request REST request.
	 * @return array<string, mixed>
	 */
	private function request_params( $request ): array {
		if ( ! is_object( $request ) ) {
			return [];
		}

		if ( method_exists( $request, 'get_json_params' ) ) {
			$json = $request->get_json_params();
			if ( is_array( $json ) && $json !== [] ) {
				return $json;
			}
		}

		if ( method_exists( $request, 'get_params' ) ) {
			$params = $request->get_params();

			return is_array( $params ) ? $params : [];
		}

		return [];
	}

	/**
	 * Record an audit entry for a tool call.
	 *
	 * The identifier is prefixed so WebMCP traffic is separable from
	 * WordPress-native ability traffic in the audit trail.
	 *
	 * @param string               $tool   Tool name.
	 * @param array<string, mixed> $args   Tool arguments.
	 * @param string               $status Outcome status.
	 * @param string|null          $client Resolved client identifier.
	 */
	private function record( string $tool, array $args, string $status, ?string $client = null ): void {
		if ( ! $this->audit instanceof AuditLogger ) {
			return;
		}

		$entry = new AuditEntry( $tool, $args, $client ?? self::AUDIT_PREFIX );
		$entry->complete( $status );
		$this->audit->record( $entry );
	}
}
