<?php
namespace Saltus\WP\Framework\MCP\Cache;

/**
 * Transient-backed cache implementing CacheInterface.
 */
class TransientCache implements CacheInterface {

	private const INDEX_OPTION = 'saltus_mcp_cache_keys';

	/**
	 * Retrieve a cached value by key.
	 *
	 * @param string $key  Cache key.
	 * @return array<string, mixed>|null  Cached value, or null if not found.
	 */
	public function get( string $key ): ?array {
		if ( ! $this->enabled() || ! function_exists( 'get_transient' ) ) {
			return null;
		}

		$value = get_transient( $key );

		return is_array( $value ) ? $value : null;
	}

	/**
	 * Store a value in the cache.
	 *
	 * @param string $key  Cache key.
	 * @param array<string, mixed> $value  Value to cache.
	 * @param int $ttl  Time-to-live in seconds.
	 */
	public function set( string $key, array $value, int $ttl ): void {
		if ( ! $this->enabled() || ! function_exists( 'set_transient' ) ) {
			return;
		}

		set_transient( $key, $value, max( 1, $ttl ) );
		$this->index_key( $key );
	}

	/**
	 * Check whether a cached value exists for the given key.
	 *
	 * @param string $key  Cache key.
	 * @return bool  True if the key has a cached value.
	 */
	public function has( string $key ): bool {
		return $this->get( $key ) !== null;
	}

	/**
	 * Delete a cached value by key.
	 *
	 * @param string $key  Cache key to delete.
	 */
	public function delete( string $key ): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( $key );
		}
	}

	/**
	 * Clear all cached values tracked by this cache.
	 */
	public function clear(): void {
		foreach ( $this->keys() as $key ) {
			$this->delete( $key );
		}

		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::INDEX_OPTION );
		}
	}

	/**
	 * Check whether caching is enabled.
	 *
	 * @return bool
	 */
	private function enabled(): bool {
		return (bool) $this->filter( 'saltus/framework/mcp/cache/enabled', true );
	}

	/**
	 * Track a cache key in the global index for later cleanup.
	 *
	 * @param string $key  The cache key to index.
	 */
	private function index_key( string $key ): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		$keys = $this->keys();
		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
		}

		update_option( self::INDEX_OPTION, $keys, false );
	}

	/**
	 * Retrieve all tracked cache keys from the index option.
	 *
	 * @return list<string>
	 */
	private function keys(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return [];
		}

		$keys = get_option( self::INDEX_OPTION, [] );

		return is_array( $keys ) ? array_values( array_filter( $keys, 'is_string' ) ) : [];
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
