<?php
namespace Saltus\WP\Framework\MCP\Audit;

interface AuditDatabase {

	/**
	 * Get the database table prefix.
	 *
	 * @return string
	 */
	public function prefix(): string;

	/**
	 * Insert a row into a database table.
	 *
	 * @param string $table  The table name.
	 * @param array<string, mixed> $data  Column name/value pairs.
	 * @param list<string> $format  Format strings for the data columns.
	 * @return bool|int
	 */
	public function insert( string $table, array $data, array $format = [] ): bool|int;

	/**
	 * Execute a raw SQL query.
	 *
	 * @param string $query  The SQL query to execute.
	 * @return bool|int
	 */
	public function query( string $query ): bool|int;

	/**
	 * Get the database charset collation string.
	 *
	 * @return string
	 */
	public function get_charset_collate(): string;

	/**
	 * Execute a SELECT query and return results.
	 *
	 * @param string $query  The SQL SELECT query.
	 * @param mixed $output  The output format constant (e.g. ARRAY_A, OBJECT).
	 * @return list<array<string, mixed>>|object|null
	 */
	public function get_results( string $query, mixed $output = null ): array|object|null;
}
