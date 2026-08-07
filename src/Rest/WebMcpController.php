<?php

namespace Saltus\WP\Framework\Rest;

use Saltus\WP\Framework\Features\WebMcp\WebMcpPolicy;
use Saltus\WP\Framework\MCP\Audit\AuditEntry;
use Saltus\WP\Framework\MCP\Audit\AuditLogger;
use Saltus\WP\Framework\MCP\MCPConfig;
use Saltus\WP\Framework\MCP\RateLimiter\RateLimiter;
use Saltus\WP\Framework\MCP\Validation\Validator;
use Saltus\WP\Framework\WebMcp\ManifestBuilder;
use Saltus\WP\Framework\WebMcp\Tools\PublicTool;
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

	/** Requests allowed per window on the execute route. */
	private const RATE_LIMIT = 60;

	/** Rate limit window in seconds. */
	private const RATE_WINDOW = 60;

	/** Audit identifier prefix, so WebMCP calls are distinguishable from ability calls. */
	private const AUDIT_PREFIX = 'webmcp';

	private WebMcpPolicy $policy;
	private ManifestBuilder $manifest_builder;
	/** @var list<PublicTool> */
	private array $tools;
	private ?AuditLogger $audit;
	private RateLimiter $rate_limiter;

	/**
	 * @param WebMcpPolicy     $policy           Model gating policy.
	 * @param list<PublicTool> $tools            Public tools available to the browser.
	 * @param ManifestBuilder|null $manifest_builder Descriptor projector.
	 * @param AuditLogger|null $audit            Audit logger, or null to skip logging.
	 * @param RateLimiter|null $rate_limiter     Rate limiter for the execute route.
	 */
	public function __construct(
		WebMcpPolicy $policy,
		array $tools,
		?ManifestBuilder $manifest_builder = null,
		?AuditLogger $audit = null,
		?RateLimiter $rate_limiter = null
	) {
		$this->policy           = $policy;
		$this->tools            = $tools;
		$this->manifest_builder = $manifest_builder ?? new ManifestBuilder();
		$this->audit            = $audit;
		$this->rate_limiter     = $rate_limiter ?? new RateLimiter( self::RATE_LIMIT, self::RATE_WINDOW );
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
	}

	/**
	 * The manifest is public, but only where a model opted in.
	 *
	 * @param mixed $request Unused.
	 * @return bool|WP_Error
	 */
	public function manifest_permissions_check( $request ) {
		if ( ! $this->policy->has_frontend_surface() ) {
			return new WP_Error(
				'saltus_webmcp_disabled',
				__( 'No content type exposes WebMCP tools.', 'saltus-framework' ),
				[ 'status' => 404 ]
			);
		}

		return true;
	}

	/**
	 * Execution is public for read tools, gated on the surface existing.
	 *
	 * @param mixed $request Unused.
	 * @return bool|WP_Error
	 */
	public function execute_permissions_check( $request ) {
		return $this->manifest_permissions_check( $request );
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
				'models' => $this->policy->frontend_models(),
			]
		);
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

		$tool = $this->find_tool( $name );
		if ( ! $tool instanceof PublicTool ) {
			return new WP_Error(
				'saltus_webmcp_unknown_tool',
				__( 'The requested tool is not available.', 'saltus-framework' ),
				[ 'status' => 404 ]
			);
		}

		$limit = $this->rate_limiter->check( self::AUDIT_PREFIX );
		if ( ! $limit->allowed ) {
			$this->record( $name, $args, 'rate_limited' );

			return new WP_Error(
				'saltus_webmcp_rate_limited',
				__( 'Too many tool calls. Try again shortly.', 'saltus-framework' ),
				[
					'status'      => 429,
					'retry_after' => $limit->retry_after,
				]
			);
		}

		// The browser is untrusted: re-validate against the tool's own schema
		// rather than relying on whatever the agent claims it sent.
		$validation = Validator::validate( $args, $tool->get_parameters() );
		if ( ! $validation['valid'] ) {
			$this->record( $name, $args, 'validation_error' );

			return new WP_Error(
				'saltus_webmcp_invalid_arguments',
				implode( '; ', $validation['errors'] ),
				[ 'status' => 400 ]
			);
		}

		if ( ! $tool->has_permission( $args ) ) {
			$this->record( $name, $args, 'error' );

			return new WP_Error(
				'saltus_webmcp_forbidden',
				__( 'You do not have permission to call this tool.', 'saltus-framework' ),
				[ 'status' => 403 ]
			);
		}

		$result = $tool->execute( $args );
		$this->record( $name, $args, 'success' );

		return rest_ensure_response(
			[
				'tool'   => $name,
				'result' => $result,
			]
		);
	}

	/**
	 * Resolve the tools available for a post type context.
	 *
	 * @param string|null $post_type Post type context, or null for site-wide.
	 * @return list<PublicTool>
	 */
	private function available_tools( ?string $post_type ): array {
		$available = [];

		foreach ( $this->tools as $tool ) {
			if ( $post_type !== null && ! $this->policy->allows_tool( $post_type, $tool->get_name() ) ) {
				continue;
			}

			if ( $post_type === null && ! $this->allowed_by_any_model( $tool->get_name() ) ) {
				continue;
			}

			$available[] = $tool;
		}

		return $available;
	}

	/**
	 * Whether any frontend-enabled model permits a tool.
	 *
	 * @param string $tool_name Tool name.
	 */
	private function allowed_by_any_model( string $tool_name ): bool {
		foreach ( $this->policy->frontend_models() as $model ) {
			if ( $this->policy->allows_tool( $model, $tool_name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Find an available tool by name.
	 *
	 * @param string $name Tool name.
	 */
	private function find_tool( string $name ): ?PublicTool {
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
	 */
	private function record( string $tool, array $args, string $status ): void {
		if ( ! $this->audit instanceof AuditLogger ) {
			return;
		}

		$entry = new AuditEntry( $tool, $args, self::AUDIT_PREFIX );
		$entry->complete( $status );
		$this->audit->record( $entry );
	}
}
