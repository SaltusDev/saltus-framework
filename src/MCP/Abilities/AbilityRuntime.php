<?php
namespace Saltus\WP\Framework\MCP\Abilities;

use Saltus\WP\Framework\MCP\Audit\AuditEntry;
use Saltus\WP\Framework\MCP\Audit\AuditLogger;
use Saltus\WP\Framework\MCP\Cache\TransientCache;
use Saltus\WP\Framework\MCP\Middleware\MiddlewarePipeline;
use Saltus\WP\Framework\MCP\Middleware\RequestContext;
use Saltus\WP\Framework\MCP\RateLimiter\RateLimiter;
use Saltus\WP\Framework\MCP\Tools\RestBackedToolInterface;
use Saltus\WP\Framework\MCP\Tools\ToolInterface;
use Saltus\WP\Framework\MCP\Validation\Validator;
use Saltus\WP\Framework\Features\AiContext\AiContextProvider;
use Saltus\WP\Framework\Features\Meta\FieldQueryGuard;
use Saltus\WP\Framework\Features\EditorialReview\ProposalService;

/**
 * Coordinates validation, rate limiting, REST dispatch, caching, and audit logging for MCP tool execution.
 *
 * Now delegates to the middleware pipeline when available; falls back to
 * the original inline orchestration for backward compatibility.
 * @api
 */
class AbilityRuntime {
	use \Saltus\WP\Framework\Infrastructure\Services\FilterAwareTrait;

	/**
	 * Audit status for a field-level denial.
	 *
	 * Distinct from `error` so an operator reading the log can tell a per-field
	 * permission or encryption rule from a capability failure — the fixes differ.
	 */
	public const STATUS_FIELD_DENIED = 'field_denied';

	private AuditLogger $audit_logger;
	private RateLimiter $rate_limiter;
	private TransientCache $cache;
	private ?MiddlewarePipeline $pipeline;
	private ?AiContextProvider $ai_context;
	private ?ProposalService $proposals;
	private ?FieldQueryGuard $field_queries;

	/**
	 * @param AuditLogger|null $audit_logger  Optional audit logger.
	 * @param RateLimiter|null $rate_limiter  Optional rate limiter.
	 * @param TransientCache|null $cache  Optional cache backend.
	 * @param MiddlewarePipeline|null $pipeline  Optional middleware pipeline.
	 */
	public function __construct(
		?AuditLogger $audit_logger = null,
		?RateLimiter $rate_limiter = null,
		?TransientCache $cache = null,
		?MiddlewarePipeline $pipeline = null,
		?AiContextProvider $ai_context = null,
		?ProposalService $proposals = null,
		?FieldQueryGuard $field_queries = null
	) {
		$this->audit_logger  = $audit_logger ?? new AuditLogger();
		$this->rate_limiter  = $rate_limiter ?? new RateLimiter();
		$this->cache         = $cache ?? new TransientCache();
		$this->pipeline      = $pipeline;
		$this->ai_context    = $ai_context;
		$this->proposals     = $proposals;
		$this->field_queries = $field_queries;
	}

	/**
	 * Validate, rate-limit, dispatch, cache, and audit an MCP tool execution.
	 *
	 * When a middleware pipeline is configured, delegates to it. Otherwise
	 * falls back to the original sequential orchestration.
	 *
	 * @param ToolInterface $tool  The tool to execute.
	 * @param array<string, mixed> $args  Arguments to pass to the tool.
	 * @return array<string, mixed>|\WP_Error  Tool result or error.
	 */
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
	public function execute( ToolInterface $tool, array $args ) {
		if ( $this->pipeline !== null ) {
			return $this->execute_via_pipeline( $tool, $args );
		}

		return $this->execute_legacy( $tool, $args );
	}

	/**
	 * Execute the tool via the middleware pipeline.
	 *
	 * @param ToolInterface $tool
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- Pipeline setup, dispatch, and result handling.
	private function execute_via_pipeline( ToolInterface $tool, array $args ) {
		$context = new RequestContext();
		$context->set_args( $args );
		$context->set_tool_metadata( [
			'name'           => $tool->get_name(),
			'parameters'     => $tool->get_parameters(),
			'has_permission' => [ $tool, 'has_permission' ],
			'capability'     => $tool instanceof RestBackedToolInterface && $tool->get_rest_capability() !== null
				? $tool->get_rest_capability()->get_capability()
				: 'edit_posts',
		] );

		$cache_ttl = $tool instanceof RestBackedToolInterface ? $tool->cache_ttl() : 300;
		$context->set_attribute( 'cache_ttl', $cache_ttl );

		$result = $this->pipeline->execute(
			$context,
			function ( RequestContext $ctx ) use ( $tool, $args ) {
				$gate_error = $this->pre_dispatch_gates( $tool, $args );
				if ( $gate_error !== null ) {
					return $gate_error;
				}
				if ( $this->proposals !== null && $this->proposals->should_queue( $tool->get_name() ) ) {
					return $this->proposals->propose( $tool->get_name(), $args );
				}
				if ( ! $tool instanceof RestBackedToolInterface ) {
					return \Saltus\WP\Framework\MCP\Error\ErrorResponse::internal_error(
						\__( 'This tool does not support REST dispatch.', 'saltus-framework' )
					);
				}

				$request = $tool->build_rest_request( $args );
				if ( $request === null ) {
					return \Saltus\WP\Framework\MCP\Error\ErrorResponse::internal_error(
						\__( 'This Saltus ability is registered for discovery only until a native dispatcher is available.', 'saltus-framework' )
					);
				}

				if ( ! \function_exists( 'rest_do_request' ) ) {
					return \Saltus\WP\Framework\MCP\Error\ErrorResponse::internal_error(
						\__( 'WordPress REST dispatch is not available.', 'saltus-framework' )
					);
				}

				$ctx->set_rest_request( $request );

				try {
					$response = \rest_do_request( $request );

					$status = (int) $response->get_status();
					$data   = $response->get_data();
					$result = \is_array( $data ) ? $data : [ 'result' => $data ];

					if ( $status >= 400 ) {
						$error_code = \is_array( $data ) ? (string) ( $data['code'] ?? 'rest_error' ) : 'rest_error';
						$error_msg  = \is_array( $data ) ? (string) ( $data['message'] ?? 'REST error' ) : 'REST error';

						return new \WP_Error( $error_code, $error_msg, [ 'status' => $status ] );
					}

					$ctx->set_response( $response );

					return $result;
				} catch ( \Throwable $e ) {
					return \Saltus\WP\Framework\MCP\Error\ErrorResponse::internal_error(
						$e->getMessage(),
						\__( 'An unexpected error occurred during tool execution.', 'saltus-framework' )
					);
				}
			}
		);

		if ( $result instanceof \WP_REST_Response ) {
			$data = $result->get_data();
			return \is_array( $data ) ? $data : [ 'result' => $data ];
		}

		return $result;
	}

	/**
	 * Original sequential execution (backward compatibility path).
	 *
	 * @param ToolInterface $tool
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.MaxExceeded
	private function execute_legacy( ToolInterface $tool, array $args ) {
		$entry = new AuditEntry( $tool->get_name(), $args, $this->identifier() );

		$valid = Validator::validate( $args, $tool->get_parameters() );
		if ( ! $valid['valid'] ) {
			$error = $this->error( 'invalid_params', implode( '; ', $valid['errors'] ), 400 );
			$this->record_error( $entry, 'validation_error', $error );
			return $error;
		}

		if ( ! $tool->has_permission( $args ) ) {
			$error = $this->error( 'forbidden', 'You do not have permission to use this tool.', 403 );
			$this->record_error( $entry, 'error', $error );
			return $error;
		}

		if ( $this->ai_context !== null ) {
			$governance_error = $this->ai_context->validate_mutation( $tool->get_name(), $args );
			if ( $governance_error instanceof \WP_Error ) {
				$this->record_error( $entry, 'error', $governance_error );
				return $governance_error;
			}
		}

		$query_error = $this->check_field_queries( $args );
		if ( $query_error instanceof \WP_Error ) {
			$this->record_error( $entry, self::STATUS_FIELD_DENIED, $query_error );
			return $query_error;
		}

		$rate_limit = $this->rate_limiter->check( $this->identifier() );
		if ( ! $rate_limit->allowed ) {
			$error = $this->error(
				'rate_limited',
				'Rate limit exceeded.',
				429,
				[
					'retry_after' => $rate_limit->retry_after,
					'remaining'   => $rate_limit->remaining,
					'reset_at'    => $rate_limit->reset_at,
				]
			);
			$this->record_error( $entry, 'rate_limited', $error );
			return $error;
		}

		if ( $this->proposals !== null && $this->proposals->should_queue( $tool->get_name() ) ) {
			return $this->proposals->propose( $tool->get_name(), $args );
		}

		if ( ! $tool instanceof RestBackedToolInterface ) {
			$error = $this->error( 'unsupported_ability', 'This tool does not support REST dispatch.', 501 );
			$this->record_error( $entry, 'error', $error );
			return $error;
		}

		$request = $tool->build_rest_request( $args );
		if ( $request === null ) {
			$error = $this->error( 'unsupported_ability', 'This Saltus ability is registered for discovery only until a native dispatcher is available.', 501 );
			$this->record_error( $entry, 'error', $error );
			return $error;
		}

		if ( ! function_exists( 'rest_do_request' ) ) {
			$error = $this->error( 'rest_unavailable', 'WordPress REST dispatch is not available.', 501 );
			$this->record_error( $entry, 'error', $error );
			return $error;
		}

		$cache_key = $this->cache_key( $tool->get_name(), $args );
		if ( $this->is_cacheable( $tool ) ) {
			$cached = $this->cache->get( $cache_key );
			if ( $cached !== null ) {
				$entry->complete( 'cache_hit' );
				$this->audit_logger->record( $entry );
				return $cached;
			}
		}

		try {
			$response = rest_do_request( $request );

			$status = (int) $response->get_status();
			$data   = $response->get_data();
			$result = is_array( $data ) ? $data : [ 'result' => $data ];

			if ( $status >= 400 ) {
				$error_code = is_array( $data ) ? (string) ( $data['code'] ?? 'rest_error' ) : 'rest_error';
				$error_msg  = is_array( $data ) ? (string) ( $data['message'] ?? 'REST error' ) : 'REST error';
				$error      = $this->error( $error_code, $error_msg, $status );
				$this->record_error( $entry, 'error', $error );
				return $error;
			}

			if ( $this->is_cacheable( $tool ) ) {
				$this->cache->set( $cache_key, $result, $this->cache_ttl( $tool ) );
			}

			$entry->complete( 'success' );
			$this->audit_logger->record( $entry );

			return $result;
		} catch ( \Throwable $e ) {
			$error = $this->error( 'ability_exception', $e->getMessage(), 500 );
			$this->record_error( $entry, 'exception', $error );
			return $error;
		}
	}

	/**
	 * Run the checks that must pass before a tool reaches dispatch.
	 *
	 * Permission, then governance, then field-level query rules. Grouped so the
	 * pipeline closure stays readable and both dispatch paths gate identically —
	 * a check added here cannot be forgotten in one of them.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return \WP_Error|null Error to return, or null to proceed.
	 */
	private function pre_dispatch_gates( ToolInterface $tool, array $args ): ?\WP_Error {
		if ( ! $tool->has_permission( $args ) ) {
			return \Saltus\WP\Framework\MCP\Error\ErrorResponse::forbidden(
				'edit_posts',
				\__( 'You do not have permission to use this tool.', 'saltus-framework' )
			);
		}

		if ( $this->ai_context !== null ) {
			$governance_error = $this->ai_context->validate_mutation( $tool->get_name(), $args );
			if ( $governance_error instanceof \WP_Error ) {
				return $governance_error;
			}
		}

		return $this->check_field_queries( $args );
	}

	/**
	 * Reject a call that would query, sort, or filter on an encrypted field.
	 *
	 * Inert when no guard is configured, so an encrypted field is never the reason
	 * a site without this wiring starts failing calls.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 */
	private function check_field_queries( array $args ): ?\WP_Error {
		if ( $this->field_queries === null ) {
			return null;
		}

		return $this->field_queries->check( $args );
	}

	/**
	 * Build a WP_Error response.
	 *
	 * @param string $code  Error code.
	 * @param string $message  Error message.
	 * @param int $status  HTTP status code.
	 * @param array<string, mixed> $extra  Additional error data.
	 * @return \WP_Error
	 */
	private function error( string $code, string $message, int $status, array $extra = [] ): \WP_Error {
		return new \WP_Error( $code, $message, array_merge( [ 'status' => $status ], $extra ) );
	}

	/**
	 * Record a failed execution as an audit entry.
	 *
	 * @param AuditEntry $entry  The audit entry to record.
	 * @param string $status  The completion status.
	 * @param \WP_Error $error  The error that occurred.
	 */
	private function record_error( AuditEntry $entry, string $status, \WP_Error $error ): void {
		$entry->complete( $status, (string) $error->get_error_code(), $error->get_error_message() );
		$this->audit_logger->record( $entry );
	}

	/**
	 * Resolve a unique identifier for the current user for rate limiting and audit.
	 *
	 * @return string
	 */
	private function identifier(): string {
		$identifier = function_exists( 'get_current_user_id' ) ? 'user:' . (int) get_current_user_id() : 'user:0';
		if ( $identifier === 'user:0' && isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$identifier = 'ip:' . hash( 'sha256', (string) $_SERVER['REMOTE_ADDR'] );
		}

		return (string) $this->filter( 'saltus/framework/mcp/rate_limit/identifier', $identifier );
	}

	/**
	 * Build a unique cache key for a tool invocation.
	 *
	 * @param string $tool_name  The tool name.
	 * @param array<string, mixed> $args  The tool arguments.
	 * @return string  Cache key.
	 */
	private function cache_key( string $tool_name, array $args ): string {
		$payload = [
			'tool'   => $tool_name,
			'args'   => $args,
			'user'   => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'locale' => function_exists( 'get_locale' ) ? get_locale() : '',
		];

		return 'saltus_mcp_' . hash( 'sha256', $this->encode( $payload ) );
	}

	/**
	 * Check whether caching is enabled for a given tool.
	 *
	 * @param RestBackedToolInterface $tool  The tool to check.
	 * @return bool
	 */
	private function is_cacheable( RestBackedToolInterface $tool ): bool {
		return (bool) $this->filter( 'saltus/framework/mcp/cache/cacheable', $tool->is_cacheable(), $tool->get_name() );
	}

	/**
	 * Resolve the cache TTL for a given tool.
	 *
	 * @param RestBackedToolInterface $tool  The tool to check.
	 * @return int
	 */
	private function cache_ttl( RestBackedToolInterface $tool ): int {
		return (int) $this->filter( 'saltus/framework/mcp/cache/ttl', $tool->cache_ttl(), $tool->get_name() );
	}

	/**
	 * Encode a payload as JSON for cache key generation.
	 *
	 * @param array<string, mixed> $payload  The payload to encode.
	 * @return string
	 */
	private function encode( array $payload ): string {
		if ( \function_exists( 'wp_json_encode' ) ) {
			$encoded = \wp_json_encode( $payload );
			return \is_string( $encoded ) ? $encoded : '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fallback for non-WordPress contexts.
		$encoded = \json_encode( $payload );
		return \is_string( $encoded ) ? $encoded : '';
	}
}
