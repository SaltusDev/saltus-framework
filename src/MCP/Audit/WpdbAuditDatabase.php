<?php
namespace Saltus\WP\Framework\MCP\Audit;

/**
 * wpdb adapter implementing the AuditDatabase interface.
 * @api
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
	public function insert( string $table, array $data, array $format = [] ) {
		return $this->wpdb->insert( $table, $data, $format );
	}

	/**
	 * Execute a raw SQL query.
	 *
	 * @param string $query  The SQL query to execute.
	 * @return bool|int
	 */
	public function query( string $query ) {
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
	 * Prepare a SQL query with placeholder substitution.
	 *
	 * @param string $query  The SQL query with placeholders.
	 * @param mixed ...$args  The values to substitute.
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
		/** @phpstan-ignore-next-line wpdb::prepare expects literal-string */
		return $this->wpdb->prepare( $query, ...$args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.WP.AlternativeFunctions -- Pass-through to wpdb::prepare.
	}

	/**
	 * Execute a SELECT query and return results.
	 *
	 * `ARRAY_A` is normalized to associative rows here so the declared shape is
	 * a guarantee callers can rely on; every other format is passed through as
	 * wpdb produced it.
	 *
	 * @param string $query  The SQL SELECT query.
	 * @param mixed $output  The output format constant (e.g. ARRAY_A, OBJECT).
	 * @return ($output is 'ARRAY_A' ? list<array<string, mixed>>|null : array<array-key, mixed>|object|null)
	 */
	public function get_results( string $query, $output = null ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is assembled internally by AuditLogger with an integer limit.
		$rows = $this->wpdb->get_results( $query, $output );
		if ( ! is_array( $rows ) ) {
			return $rows;
		}

		// Tested against the format's value rather than the ARRAY_A constant.
		// WordPress defines that constant as this identical string, so the value
		// test holds equally before wpdb is loaded, where a caller asking for
		// associative rows would otherwise be handed the raw wpdb shape.
		if ( $output !== 'ARRAY_A' ) {
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
