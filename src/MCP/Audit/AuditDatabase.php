<?php
namespace Saltus\WP\Framework\MCP\Audit;

interface AuditDatabase {
	public function prefix(): string;

	/**
	 * @param array<string, mixed> $data
	 * @param list<string> $format
	 */
	public function insert( string $table, array $data, array $format = [] ): bool|int;

	public function query( string $query ): bool|int;

	public function get_charset_collate(): string;

	/**
	 * @return list<array<string, mixed>>|object|null
	 */
	public function get_results( string $query, mixed $output = null ): array|object|null;
}
