<?php
namespace Saltus\WP\Framework\MCP\Middleware;

use Saltus\WP\Framework\MCP\Cache\CacheInterface;

/**
 * Middleware that provides transparent response caching for GET requests.
 *
 * Checks the cache before dispatch and stores the response after a
 * successful dispatch. Only caches GET requests by default.
 */
class CacheMiddleware implements MiddlewareInterface {

	private CacheInterface $cache;

	public function __construct( CacheInterface $cache ) {
		$this->cache = $cache;
	}

	/**
	 * @param RequestContext $context
	 * @param callable $next
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( RequestContext $context, callable $next ) {
		if ( ! $this->is_get_request( $context ) ) {
			$result = $next( $context );
			$this->invalidate_on_mutation( $context );
			return $result;
		}

		$cache_key = $this->build_cache_key( $context );

		$cached = $this->cache->get( $cache_key );
		if ( $cached !== null ) {
			return \rest_ensure_response( $cached );
		}

		$result = $next( $context );

		if ( ! \is_wp_error( $result ) ) {
			$ttl  = $this->resolve_ttl( $context );
			$data = $result->get_data();
			if ( is_array( $data ) ) {
				$this->cache->set( $cache_key, $data, $ttl );
			}
		}

		return $result;
	}

	/**
	 * Check whether the current request is a GET.
	 *
	 * @param RequestContext $context
	 * @return bool
	 */
	private function is_get_request( RequestContext $context ): bool {
		$meta = $context->get_tool_metadata();
		if ( ! empty( $meta ) ) {
			return true;
		}

		$request = $context->get_rest_request();
		if ( $request === null ) {
			return false;
		}

		return $request->get_method() === 'GET';
	}

	/**
	 * Build a unique cache key from the context.
	 *
	 * @param RequestContext $context
	 * @return string
	 */
	private function build_cache_key( RequestContext $context ): string {
		$tool_name = $this->resolve_name( $context );
		$args      = $context->get_args();

		$payload = [
			'tool'   => $tool_name,
			'args'   => $args,
			'user'   => \function_exists( 'get_current_user_id' ) ? (int) \get_current_user_id() : 0,
			'locale' => \function_exists( 'get_locale' ) ? \get_locale() : '',
		];

		return 'saltus_mcp_' . \hash( 'sha256', $this->encode( $payload ) );
	}

	/**
	 * Resolve a name for cache key generation.
	 *
	 * @param RequestContext $context
	 * @return string
	 */
	private function resolve_name( RequestContext $context ): string {
		$meta = $context->get_tool_metadata();
		if ( ! empty( $meta['name'] ) && is_string( $meta['name'] ) ) {
			return $meta['name'];
		}

		$route = $context->get_route();
		if ( $route !== '' ) {
			return $route;
		}

		return 'unknown';
	}

	/**
	 * Resolve the cache TTL from context attributes.
	 *
	 * @param RequestContext $context
	 * @return int
	 */
	private function resolve_ttl( RequestContext $context ): int {
		$ttl = $context->get_attribute( 'cache_ttl' );
		if ( is_int( $ttl ) && $ttl > 0 ) {
			return $ttl;
		}

		return 300;
	}

	/**
	 * Invalidate cache on mutating requests.
	 *
	 * @param RequestContext $context
	 */
	private function invalidate_on_mutation( RequestContext $context ): void {
		if ( \function_exists( 'wp_defer_term_counting' ) && \function_exists( 'wp_defer_comment_counting' ) ) {
			if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
				return;
			}
		}

		$this->cache->clear();
	}

	/**
	 * Encode a payload as JSON for cache key generation.
	 *
	 * @param array<string, mixed> $payload
	 * @return string
	 */
	private function encode( array $payload ): string {
		if ( \function_exists( 'wp_json_encode' ) ) {
			$encoded = \wp_json_encode( $payload );
			return \is_string( $encoded ) ? $encoded : '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$encoded = \json_encode( $payload );
		return \is_string( $encoded ) ? $encoded : '';
	}
}
