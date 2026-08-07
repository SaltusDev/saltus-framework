<?php
namespace Saltus\WP\Framework\MCP\Middleware;

use Saltus\WP\Framework\MCP\RateLimiter\RateLimiter;

/**
 * Middleware that applies rate limiting to incoming requests.
 *
 * Checks the rate limit before dispatch. Currently applies to all requests;
 * per-route configuration can be set via context attributes.
 */
class RateLimitMiddleware implements MiddlewareInterface {

	private RateLimiter $limiter;

	public function __construct( RateLimiter $limiter ) {
		$this->limiter = $limiter;
	}

	/**
	 * @param RequestContext $context
	 * @param callable $next
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( RequestContext $context, callable $next ) {
		$identifier = $this->resolve_identifier( $context );

		$result = $this->limiter->check( $identifier );

		if ( ! $result->allowed ) {
			$retry_after = is_int( $result->retry_after ) ? $result->retry_after : 60;

			return \Saltus\WP\Framework\MCP\Error\ErrorResponse::rate_limited( $retry_after );
		}

		$context->set_attribute( 'rate_limit_remaining', $result->remaining );
		$context->set_attribute( 'rate_limit_reset_at', $result->reset_at );

		return $next( $context );
	}

	/**
	 * Resolve a unique identifier for rate limiting.
	 *
	 * @param RequestContext $context
	 * @return string
	 */
	private function resolve_identifier( RequestContext $context ): string {
		$meta = $context->get_tool_metadata();
		if ( ! empty( $meta['rate_limit_identifier'] ) && is_string( $meta['rate_limit_identifier'] ) ) {
			return $meta['rate_limit_identifier'];
		}

		$identifier = \function_exists( 'get_current_user_id' ) ? 'user:' . (int) \get_current_user_id() : 'user:0';
		if ( $identifier === 'user:0' && isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$identifier = 'ip:' . \hash( 'sha256', (string) $_SERVER['REMOTE_ADDR'] );
		}

		return $identifier;
	}
}
