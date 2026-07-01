<?php
namespace Saltus\WP\Framework\MCP\Audit;

class WpdbAuditDatabase implements AuditDatabase {
	private \wpdb $wpdb;

	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function prefix(): string {
		return $this->wpdb->prefix;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param list<string> $format
	 */
	public function insert( string $table, array $data, array $format = [] ): bool|int {
		return $this->wpdb->insert( $table, $data, $format );
	}

	public function query( string $query ): bool|int {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Queries are assembled internally by AuditLogger with controlled values.
		return $this->wpdb->query( $query );
	}

	public function get_charset_collate(): string {
		return $this->wpdb->get_charset_collate();
	}

	/**
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
