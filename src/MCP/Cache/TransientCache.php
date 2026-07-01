<?php
namespace Saltus\WP\Framework\MCP\Cache;

class TransientCache implements CacheInterface {

	private const INDEX_OPTION = 'saltus_mcp_cache_keys';

	/**
	 * @return array<string, mixed>|null
	 */
	public function get( string $key ): ?array {
		if ( ! $this->enabled() || ! function_exists( 'get_transient' ) ) {
			return null;
		}

		$value = get_transient( $key );

		return is_array( $value ) ? $value : null;
	}

	/**
	 * @param array<string, mixed> $value
	 */
	public function set( string $key, array $value, int $ttl ): void {
		if ( ! $this->enabled() || ! function_exists( 'set_transient' ) ) {
			return;
		}

		set_transient( $key, $value, max( 1, $ttl ) );
		$this->index_key( $key );
	}

	public function has( string $key ): bool {
		return $this->get( $key ) !== null;
	}

	public function delete( string $key ): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( $key );
		}
	}

	public function clear(): void {
		foreach ( $this->keys() as $key ) {
			$this->delete( $key );
		}

		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::INDEX_OPTION );
		}
	}

	private function enabled(): bool {
		return (bool) $this->filter( 'saltus/framework/mcp/cache/enabled', true );
	}

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
	 * @param non-empty-string $hook
	 */
	private function filter( string $hook, mixed $value ): mixed {
		if ( function_exists( 'apply_filters' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook names are internal constants passed through this helper.
			return apply_filters( $hook, $value );
		}

		return $value;
	}
}
