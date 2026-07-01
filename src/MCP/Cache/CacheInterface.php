<?php
namespace Saltus\WP\Framework\MCP\Cache;

interface CacheInterface {

	/**
	 * Retrieve a cached value by key.
	 *
	 * @param string $key  The cache key.
	 * @return array<string, mixed>|null
	 */
	public function get( string $key ): ?array;

	/**
	 * Store a value in the cache.
	 *
	 * @param string $key  The cache key.
	 * @param array<string, mixed> $value  The value to cache.
	 * @param int $ttl  Time-to-live in seconds.
	 */
	public function set( string $key, array $value, int $ttl ): void;

	/**
	 * Check whether a cached value exists for the given key.
	 *
	 * @param string $key  The cache key.
	 * @return bool
	 */
	public function has( string $key ): bool;

	/**
	 * Delete a cached value by key.
	 *
	 * @param string $key  The cache key to delete.
	 */
	public function delete( string $key ): void;

	/**
	 * Clear all cached values.
	 */
	public function clear(): void;
}
