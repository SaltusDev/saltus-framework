<?php
namespace Saltus\WP\Framework\MCP\Audit;

/**
 * wpdb adapter implementing the AuditDatabase interface.
 */
class WpdbAuditDatabase implements AuditDatabase {
	private \wpdb $wpdb;

	/**
	 * @param \wpdb $wpdb  The WordPress database object.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Get the WordPress database table prefix.
	 *
	 * @return string
	 */
	public function prefix(): string {
		return $this->wpdb->prefix;
	}

	/**
	 * Insert a row into the audit table.
	 *
	 * @param string $table  The table name.
	 * @param array<string, mixed> $data  Column name/value pairs.
	 * @param list<string> $format  Format strings for the data columns.
	 * @return bool|int
	 */
	public function insert( string $table, array $data, array $format = [] ): bool|int {
		return $this->wpdb->insert( $table, $data, $format );
	}

	/**
	 * Execute a raw SQL query.
	 *
	 * @param string $query  The SQL query to execute.
	 * @return bool|int
	 */
	public function query( string $query ): bool|int {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Queries are assembled internally by AuditLogger with controlled values.
		return $this->wpdb->query( $query );
	}

	/**
	 * Get the database charset collation string.
	 *
	 * @return string
	 */
	public function get_charset_collate(): string {
		return $this->wpdb->get_charset_collate();
	}

	/**
	 * Execute a SELECT query and return results.
	 *
	 * @param string $query  The SQL SELECT query.
	 * @param mixed $output  The output format constant (e.g. ARRAY_A, OBJECT).
	 * @return list<array<string, mixed>>|object|null
	 */
	public function get_results( string $query, mixed $output = null ): array|object|null {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is assembled internally by AuditLogger with an integer limit.
		$rows = $this->wpdb->get_results( $query, $output );
		if ( ! is_array( $rows ) ) {
			return $rows;
		}

		$result = [];
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$result[] = $row;
			}
		}

		return $result;
	}
}
