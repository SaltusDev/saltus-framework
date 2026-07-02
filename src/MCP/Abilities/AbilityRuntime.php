<?php
namespace Saltus\WP\Framework\MCP\Abilities;

use Saltus\WP\Framework\MCP\Audit\AuditEntry;
use Saltus\WP\Framework\MCP\Audit\AuditLogger;
use Saltus\WP\Framework\MCP\Cache\TransientCache;
use Saltus\WP\Framework\MCP\RateLimiter\RateLimiter;
use Saltus\WP\Framework\MCP\Tools\RestBackedToolInterface;
use Saltus\WP\Framework\MCP\Tools\ToolInterface;
use Saltus\WP\Framework\MCP\Validation\Validator;

/**
 * Coordinates validation, rate limiting, REST dispatch, caching, and audit logging for MCP tool execution.
 */
class AbilityRuntime {

	private AuditLogger $audit_logger;
	private RateLimiter $rate_limiter;
	private TransientCache $cache;

	/**
	 * @param AuditLogger|null $audit_logger  Optional audit logger.
	 * @param RateLimiter|null $rate_limiter  Optional rate limiter.
	 * @param TransientCache|null $cache  Optional cache backend.
	 */
	public function __construct(
		?AuditLogger $audit_logger = null,
		?RateLimiter $rate_limiter = null,
		?TransientCache $cache = null
	) {
		$this->audit_logger = $audit_logger ?? new AuditLogger();
		$this->rate_limiter = $rate_limiter ?? new RateLimiter();
		$this->cache        = $cache ?? new TransientCache();
	}

	/**
	 * Validate, rate-limit, dispatch, cache, and audit an MCP tool execution.
	 *
	 * @param ToolInterface $tool  The tool to execute.
	 * @param array<string, mixed> $args  Arguments to pass to the tool.
	 * @return array<string, mixed>|\WP_Error  Tool result or error.
	 */
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- Runtime coordinates validation, rate limiting, dispatch, cache, and audit.
	public function execute( ToolInterface $tool, array $args ): array|\WP_Error {
		$entry = new AuditEntry( $tool->get_name(), $args, $this->identifier() );

		$valid = Validator::validate( $args, $tool->get_parameters() );
		if ( ! $valid['valid'] ) {
			$error = $this->error( 'invalid_params', implode( '; ', $valid['errors'] ), 400 );
			$this->record_error( $entry, 'validation_error', $error );
			return $error;
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

		if ( ! $tool instanceof RestBackedToolInterface ) {
			$error = $this->error( 'unsupported_ability', 'This Saltus ability is registered for discovery only until a native dispatcher is available.', 501 );
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
			$data     = $response->get_data();
			$result   = is_array( $data ) ? $data : [ 'result' => $data ];

			if ( $this->is_cacheable( $tool ) ) {
				$this->cache->set( $cache_key, $result, $this->cache_ttl( $tool ) );
			} else {
				$this->cache->clear();
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

	/**
	 * Apply a WordPress filter, falling back to the default value outside WordPress.
	 *
	 * @param non-empty-string $hook  The filter hook name.
	 * @param mixed $value  The value to filter.
	 * @param mixed ...$args  Additional arguments passed to the filter.
	 * @return mixed
	 */
	private function filter( string $hook, mixed $value, mixed ...$args ): mixed {
		if ( function_exists( 'apply_filters' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook names are internal constants passed through this helper.
			return apply_filters( $hook, $value, ...$args );
		}

		return $value;
	}
}
