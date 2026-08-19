<?php
namespace Saltus\WP\Framework\MCP\Audit;

/**
 * @api
 */
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
	public function insert( string $table, array $data, array $format = [] );

	/**
	 * Execute a raw SQL query.
	 *
	 * @param string $query  The SQL query to execute.
	 * @return bool|int
	 */
	public function query( string $query );

	/**
	 * Get the database charset collation string.
	 *
	 * @return string
	 */
	public function get_charset_collate(): string;

	/**
	 * Execute a SELECT query and return results.
	 *
	 * The row shape is decided by the requested format, so the return type is
	 * conditional on it rather than claiming associative rows for every format.
	 *
	 * @param string $query  The SQL SELECT query.
	 * @param mixed $output  The output format constant (e.g. ARRAY_A, OBJECT).
	 * @return ($output is 'ARRAY_A' ? list<array<string, mixed>>|null : array<array-key, mixed>|object|null)
	 */
	public function get_results( string $query, $output = null );

	/**
	 * Prepare a SQL query with placeholder substitution.
	 *
	 * @param string $query  The SQL query with placeholders.
	 * @param mixed ...$args  The values to substitute.
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string;
}
