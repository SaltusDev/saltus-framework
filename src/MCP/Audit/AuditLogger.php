<?php
namespace Saltus\WP\Framework\MCP\Audit;

/**
 * Persists MCP audit entries to a custom database table.
 * @api
 */
class AuditLogger {
	use \Saltus\WP\Framework\Infrastructure\Services\FilterAwareTrait;

	private const TABLE_SUFFIX = 'saltus_mcp_audit';
	private const DB_VERSION   = '1.0.0';

	/** Transient recording that the DDL has run recently. */
	private const VERIFIED_TRANSIENT = 'saltus_mcp_audit_table_verified';

	/** Seconds a table verification is trusted before the DDL runs again. */
	private const VERIFIED_TTL = 3600;

	/** @var list<string> */
	private const VALID_STATUSES = [
		'started',
		'success',
		'error',
		'cache_hit',
		'validation_error',
		'rate_limited',
		'exception',
		// Field-level denial, kept distinct from `error` so an operator can tell a
		// per-field permission or encryption rule from a capability failure. The
		// two have different fixes: one is model config, the other is a role.
		'field_denied',
	];

	private bool $db_initialized = false;

	/**
	 * Persist an audit entry to the database.
	 *
	 * @param AuditEntry $entry  The audit entry to persist.
	 */
	public function record( AuditEntry $entry ): void {
		if ( ! $this->enabled() ) {
			return;
		}

		$this->ensure_db();

		$wpdb = $this->wpdb();
		if ( $wpdb === null ) {
			return;
		}

		$data = $entry->to_array();
		$wpdb->insert(
			$this->table_name(),
			[
				'created_at'    => $data['timestamp'],
				'user_id'       => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
				'identifier'    => $data['identifier'] !== null ? $this->sanitize( $data['identifier'], 191 ) : null,
				'ability'       => $this->sanitize( $data['tool'], 191 ),
				'arguments'     => $this->encode( is_array( $data['arguments'] ) ? $data['arguments'] : [] ),
				'status'        => $this->validate_status( $data['status'] ),
				'duration_ms'   => $data['duration_ms'],
				'error_code'    => $data['error_code'] !== null ? $this->sanitize( $data['error_code'], 191 ) : null,
				'error_message' => $data['error_message'] !== null ? $this->sanitize( $data['error_message'], 65535 ) : null,
			],
			[ '%s', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s' ]
		);
	}

	/**
	 * Retrieve the most recent audit entries.
	 *
	 * @param int $limit  Maximum number of entries to return.
	 * @return list<array<string, mixed>>
	 */
	public function get_recent_entries( int $limit = 100 ): array {
		// Reads must create the table too. On an install that has only ever been
		// read from — the health endpoint, the retention cron — record() has never
		// run, so nothing else would have created it, and querying a missing table
		// reports zero errors rather than a broken table.
		$this->ensure_db();

		$wpdb = $this->wpdb();
		if ( $wpdb === null ) {
			return [];
		}

		$sql = 'SELECT * FROM ' . $this->table_name() . ' ORDER BY id DESC LIMIT ' . max( 1, $limit );

		$output = defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is assembled from an internal table name and integer limit.
		$rows = $wpdb->get_results( $sql, $output );

		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : [];
	}

	/**
	 * Create the audit log database table if it does not exist.
	 *
	 * @return bool True when the DDL ran and the table can be trusted to exist.
	 *              False when there is no database to run it against, or the
	 *              server rejected the statement.
	 */
	private function ensure_table(): bool {
		$wpdb = $this->wpdb();
		if ( $wpdb === null ) {
			return false;
		}

		$table           = $this->table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE IF NOT EXISTS {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime(3) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			identifier varchar(191) NULL,
			ability varchar(191) NOT NULL,
			arguments longtext NULL,
			status varchar(32) NOT NULL,
			duration_ms double NULL,
			error_code varchar(191) NULL,
			error_message text NULL,
			PRIMARY KEY  (id),
			KEY ability (ability),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) {$charset_collate}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL uses the internal audit table name.
		$result = $wpdb->query( $sql );

		// `wpdb::query()` answers a CREATE with `true` and any failure with
		// `false`, but the interface also permits the affected-row count other
		// statements return. Compare against `false` so a legitimate `0` is not
		// read as a rejected statement.
		return $result !== false;
	}

	/**
	 * Ensure the audit table exists, at most once per request.
	 *
	 * Creation is not gated on the stored schema version: that would mean a table
	 * dropped after the option was set is never recreated, and every read then
	 * reports zero errors instead of a missing table. The option is kept as a
	 * schema marker for future migrations.
	 *
	 * It is gated on a short-lived transient instead. A busy site would otherwise
	 * send `CREATE TABLE IF NOT EXISTS` on every request that touches the log —
	 * cheap individually, but it is DDL against the same table from every worker,
	 * and it buys nothing once the table is known to exist. The transient keeps
	 * the self-healing property with a bounded delay: a dropped table comes back
	 * within the TTL rather than on the very next read.
	 *
	 * Only a DDL that actually succeeded is recorded. Marking the table verified
	 * after a failed create would suppress the retry for the whole TTL, and every
	 * read in that window queries a table that is not there and reports zero
	 * errors — a broken log that looks like a healthy one.
	 */
	private function ensure_db(): void {
		if ( $this->db_initialized ) {
			return;
		}

		// Set before the work, not after: a failed DDL should not have every
		// subsequent call in this request retry it.
		$this->db_initialized = true;

		if ( $this->table_verified() ) {
			return;
		}

		if ( ! $this->ensure_table() ) {
			return;
		}

		$this->mark_table_verified();

		if ( function_exists( 'get_option' ) && function_exists( 'update_option' )
			&& get_option( 'saltus_mcp_audit_db_version' ) !== self::DB_VERSION ) {
			update_option( 'saltus_mcp_audit_db_version', self::DB_VERSION );
		}
	}

	/**
	 * Whether the table was confirmed to exist recently enough to trust.
	 *
	 * Absent transient support — unit tests, a very early boot — the answer is no,
	 * which falls back to the previous behavior of running the DDL every request.
	 */
	private function table_verified(): bool {
		if ( ! function_exists( 'get_transient' ) ) {
			return false;
		}

		return get_transient( self::VERIFIED_TRANSIENT ) === self::DB_VERSION;
	}

	/**
	 * Record that the table exists, so the next requests can skip the DDL.
	 *
	 * Stores the schema version rather than a bare flag, so bumping DB_VERSION
	 * invalidates every site's marker without needing a separate upgrade step.
	 */
	private function mark_table_verified(): void {
		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}

		$ttl = (int) $this->filter( 'saltus/framework/mcp/audit/table_check_ttl', self::VERIFIED_TTL );
		if ( $ttl <= 0 ) {
			return;
		}

		set_transient( self::VERIFIED_TRANSIENT, self::DB_VERSION, $ttl );
	}

	/**
	 * Delete audit entries older than the retention period.
	 */
	public function cleanup_expired_entries(): void {
		$days = (int) $this->filter( 'saltus/framework/mcp/audit/retention_days', 30 );
		if ( $days <= 0 ) {
			return;
		}

		$this->ensure_db();

		$wpdb = $this->wpdb();
		if ( $wpdb === null ) {
			return;
		}

		$day_seconds = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
		$cutoff      = gmdate( 'Y-m-d H:i:s.000', time() - ( $days * $day_seconds ) );
		$table       = $this->table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}

	/**
	 * Check whether audit logging is enabled.
	 *
	 * @return bool
	 */
	private function enabled(): bool {
		return (bool) $this->filter( 'saltus/framework/mcp/audit/enabled', true );
	}

	/**
	 * Get the full audit table name with prefix.
	 *
	 * @return string
	 */
	private function table_name(): string {
		$wpdb   = $this->wpdb();
		$prefix = $wpdb !== null ? $wpdb->prefix() : '';

		return $prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Get the global wpdb instance wrapped in an AuditDatabase adapter.
	 *
	 * @return AuditDatabase|null
	 */
	private function wpdb(): ?AuditDatabase {
		global $wpdb;

		if ( $wpdb instanceof AuditDatabase ) {
			return $wpdb;
		}

		if ( $wpdb instanceof \wpdb ) {
			return new WpdbAuditDatabase( $wpdb );
		}

		return null;
	}

	/**
	 * Sanitize a string value, truncating to a maximum length.
	 *
	 * @param string $value  The value to sanitize.
	 * @param positive-int $max_length  Maximum allowed string length.
	 * @return string
	 */
	private function sanitize( string $value, int $max_length ): string {
		$value = str_replace( "\0", '', $value );

		if ( function_exists( 'sanitize_text_field' ) ) {
			$value = sanitize_text_field( $value );
		}

		if ( mb_strlen( $value ) > $max_length ) {
			$value = mb_substr( $value, 0, $max_length );
		}

		return $value;
	}

	/**
	 * Validate that a status string is one of the allowed values.
	 *
	 * @param string $status  The status to validate.
	 * @return string
	 */
	private function validate_status( string $status ): string {
		$status = $this->sanitize( $status, 32 );

		if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
			return 'error';
		}

		return $status;
	}

	/**
	 * Encode data as JSON for database storage.
	 *
	 * @param array<string, mixed> $data  The data to encode.
	 * @return string
	 */
	private function encode( array $data ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			$encoded = wp_json_encode( $data );
			return is_string( $encoded ) ? $encoded : '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fallback for non-WordPress contexts.
		$encoded = json_encode( $data );
		return is_string( $encoded ) ? $encoded : '';
	}
}
