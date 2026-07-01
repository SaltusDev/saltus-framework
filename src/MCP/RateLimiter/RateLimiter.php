<?php
namespace Saltus\WP\Framework\MCP\RateLimiter;

/**
 * Sliding-window rate limiter backed by WordPress transients.
 */
class RateLimiter {

	private int $default_max_requests;
	private int $default_window_seconds;

	/**
	 * @param int $max_requests  Maximum number of requests allowed within the window.
	 * @param int $window_seconds  Time window in seconds.
	 */
	public function __construct( int $max_requests = 60, int $window_seconds = 60 ) {
		$this->default_max_requests   = $max_requests;
		$this->default_window_seconds = $window_seconds;
	}

	/**
	 * Check whether the given identifier has exceeded the rate limit.
	 *
	 * @param string $identifier  Unique identifier to rate-limit by.
	 * @return RateLimitResult  Outcome of the rate limit check.
	 */
	public function check( string $identifier ): RateLimitResult {
		if ( ! $this->enabled() ) {
			return new RateLimitResult( true, $this->max_requests(), microtime( true ) + $this->window_seconds(), null );
		}

		$now      = microtime( true );
		$window   = $this->window_seconds();
		$max      = $this->max_requests();
		$cutoff   = $now - $window;
		$key      = $this->key( $identifier );
		$requests = $this->get( $key );
		$requests = array_values( array_filter( $requests, fn( float $timestamp ) => $timestamp >= $cutoff ) );

		if ( count( $requests ) >= $max ) {
			$oldest      = $requests[0];
			$reset_at    = $oldest + $window;
			$retry_after = (int) ceil( $reset_at - $now );

			$this->set( $key, $requests, $window );

			return new RateLimitResult( false, 0, $reset_at, max( $retry_after, 1 ) );
		}

		$requests[] = $now;
		$this->set( $key, $requests, $window );

		return new RateLimitResult( true, $max - count( $requests ), $now + $window, null );
	}

	/**
	 * Check whether rate limiting is enabled.
	 *
	 * @return bool
	 */
	private function enabled(): bool {
		return (bool) $this->filter( 'saltus/framework/mcp/rate_limit/enabled', true );
	}

	/**
	 * Get the maximum number of requests allowed per window.
	 *
	 * @return int<1, max>
	 */
	private function max_requests(): int {
		return max( 1, (int) $this->filter( 'saltus/framework/mcp/rate_limit/max_requests', $this->default_max_requests ) );
	}

	/**
	 * Get the rate limit window duration in seconds.
	 *
	 * @return int<1, max>
	 */
	private function window_seconds(): int {
		return max( 1, (int) $this->filter( 'saltus/framework/mcp/rate_limit/window_seconds', $this->default_window_seconds ) );
	}

	/**
	 * Build the transient key for a given identifier.
	 *
	 * @param string $identifier  The identifier to key by.
	 * @return string
	 */
	private function key( string $identifier ): string {
		return 'saltus_mcp_rate_' . hash( 'sha256', $identifier );
	}

	/**
	 * Retrieve the stored timestamps for a rate-limit key.
	 *
	 * @param string $key  The rate-limit cache key.
	 * @return list<float>
	 */
	private function get( string $key ): array {
		if ( ! function_exists( 'get_transient' ) ) {
			return [];
		}

		$value = get_transient( $key );

		return is_array( $value ) ? array_values( array_map( 'floatval', $value ) ) : [];
	}

	/**
	 * Store timestamps for a rate-limit key.
	 *
	 * @param list<float> $requests  Array of request timestamps.
	 * @param int $ttl  Time-to-live in seconds.
	 */
	private function set( string $key, array $requests, int $ttl ): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $key, $requests, $ttl );
		}
	}

	/**
	 * Apply a WordPress filter, falling back to the default value outside WordPress.
	 *
	 * @param non-empty-string $hook  The filter hook name.
	 * @param mixed $value  The value to filter.
	 * @return mixed
	 */
	private function filter( string $hook, mixed $value ): mixed {
		if ( function_exists( 'apply_filters' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook names are internal constants passed through this helper.
			return apply_filters( $hook, $value );
		}

		return $value;
	}
}
