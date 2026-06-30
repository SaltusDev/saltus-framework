<?php
namespace Saltus\WP\Framework\MCP\Cache;

interface CacheInterface {

	/**
	 * @return array<string, mixed>|null
	 */
	public function get( string $key ): ?array;

	/**
	 * @param array<string, mixed> $value
	 */
	public function set( string $key, array $value, int $ttl ): void;

	public function has( string $key ): bool;

	public function delete( string $key ): void;

	public function clear(): void;
}
