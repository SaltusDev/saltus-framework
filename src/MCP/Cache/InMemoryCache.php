<?php
namespace Saltus\WP\Framework\MCP\Cache;

class InMemoryCache implements CacheInterface {

	/** @var array<string, array{data: array<string, mixed>, expiresAt: float}> */
	private array $store = [];

	public function get( string $key ): ?array {
		$entry = $this->store[ $key ] ?? null;

		if ( $entry === null ) {
			return null;
		}

		if ( microtime( true ) >= $entry['expiresAt'] ) {
			unset( $this->store[ $key ] );
			return null;
		}

		return $entry['data'];
	}

	public function set( string $key, array $value, int $ttl ): void {
		$this->store[ $key ] = [
			'data'      => $value,
			'expiresAt' => microtime( true ) + $ttl,
		];
	}

	public function has( string $key ): bool {
		$entry = $this->store[ $key ] ?? null;

		if ( $entry === null ) {
			return false;
		}

		if ( microtime( true ) >= $entry['expiresAt'] ) {
			unset( $this->store[ $key ] );
			return false;
		}

		return true;
	}

	public function delete( string $key ): void {
		unset( $this->store[ $key ] );
	}

	public function clear(): void {
		$this->store = [];
	}
}
