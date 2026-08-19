<?php
namespace Saltus\WP\Framework\MCP\Audit;

/**
 * Persists MCP audit entries to a custom database table.
 * @api
 */
class AuditLogger {
	use \Saltus\WP\Framework\Infrastructure\Services\FilterAwareTrait;

	private const TABLE_SUFFIX      = 'saltus_mcp_audit';
	private const DB_VERSION        = '1.0.0';
	private const SLOW_TABLE_SUFFIX = 'saltus_mcp_audit_slow_calls';

	/** Transient recording that the DDL has run recently. */
	private const VERIFIED_TRANSIENT = 'saltus_mcp_audit_table_verified';

	/** Seconds a table verification is trusted before the DDL runs again. */
	private const VERIFIED_TTL = 3600;

	/**
	 * Bound shared by the sampling draw and its divisor.
	 *
	 * A draw of 1..N over N gives a retained fraction of floor(rate * N) / N,
	 * which is exact for any rate expressible in millionths.
	 */
	private const SAMPLE_PRECISION = 1000000;

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

		$data    = $entry->to_array();
		$is_slow = $this->is_slow( $data['duration_ms'] );
		if ( $is_slow ) {
			$this->record_slow_call( $data );
		}

		$status = (string) $data['status'];
		if ( ! $this->should_record( $status ) ) {
			return;
		}

		$this->ensure_db();

		$wpdb = $this->wpdb();
		if ( $wpdb === null ) {
			return;
		}

		$inserted = $wpdb->insert(
			$this->table_name(),
			[
				'created_at'    => $data['timestamp'],
				'user_id'       => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
				'identifier'    => $data['identifier'] !== null ? $this->sanitize( (string) $data['identifier'], 191 ) : null,
				'ability'       => $this->sanitize( (string) $data['tool'], 191 ),
				'arguments'     => $this->encode( is_array( $data['arguments'] ) ? $data['arguments'] : [] ),
				'status'        => $this->validate_status( $status ),
				'duration_ms'   => $data['duration_ms'],
				'error_code'    => $data['error_code'] !== null ? $this->sanitize( (string) $data['error_code'], 191 ) : null,
				'error_message' => $data['error_message'] !== null ? $this->sanitize( (string) $data['error_message'], 65535 ) : null,
			],
			[ '%s', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s' ]
		);

		if ( $inserted !== false && in_array( $status, [ 'error', 'exception' ], true ) ) {
			/**
			 * Hand a persisted failure to an optional external collector.
			 *
			 * @param AuditEntry $entry Completed, persisted audit entry.
			 */
			do_action( 'saltus/framework/observability/error', $entry );
		}
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
	 *
	 * Computes rollups for yesterday before deleting old rows, so aggregates
	 * survive retention pruning.
	 */
	public function cleanup_expired_entries(): void {
		// Compute rollups for yesterday before pruning
		$this->compute_recent_rollups();

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
		$this->cleanup_slow_calls();
	}

	/**
	 * Compute rollups for recent days that haven't been rolled up yet.
	 *
	 * Runs yesterday and the day before to handle late-arriving entries.
	 */
	private function compute_recent_rollups(): void {
		// Hand the store this logger's own adapter. Letting it resolve the global
		// itself would mean the rollup read and the prune could run against two
		// different databases, and the rollup would silently no-op wherever the
		// global is an AuditDatabase rather than a \wpdb.
		$rollup_store = new RollupStore( $this->wpdb() );

		// Roll up yesterday
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		$rollup_store->compute_and_store_rollup( $yesterday );

		// Roll up day before yesterday to catch any late entries
		$day_before = gmdate( 'Y-m-d', strtotime( '-2 days' ) );
		$rollup_store->compute_and_store_rollup( $day_before );
		$rollup_store->record_completion( $yesterday );
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
	 * Get the separate slow-call table name.
	 */
	private function slow_table_name(): string {
		$wpdb   = $this->wpdb();
		$prefix = $wpdb !== null ? $wpdb->prefix() : '';

		return $prefix . self::SLOW_TABLE_SUFFIX;
	}

	/**
	 * Decide whether a normal audit row is retained under sampling.
	 *
	 * Failures bypass sampling so error visibility is never lost. Slow calls
	 * are persisted separately before this decision and do not need a second
	 * bypass here.
	 *
	 * @param string $status Completed audit status.
	 */
	private function should_record( string $status ): bool {
		if ( in_array( $status, [ 'error', 'exception' ], true ) ) {
			return true;
		}

		$rate = RollupStore::normalize_sample_rate(
			$this->filter( 'saltus/framework/mcp/audit/sample_rate', 1.0 )
		);

		if ( $rate >= 1.0 ) {
			return true;
		}
		if ( $rate <= 0.0 ) {
			return false;
		}

		return $this->sample_value() <= $rate;
	}

	/**
	 * Is a completed audit payload above the slow-call threshold?
	 *
	 * @param mixed $duration Duration in milliseconds.
	 */
	private function is_slow( $duration ): bool {
		if ( ! is_numeric( $duration ) ) {
			return false;
		}

		$threshold = $this->filter( 'saltus/framework/mcp/audit/slow_threshold_ms', 5000.0 );
		if ( ! is_numeric( $threshold ) || ! is_finite( (float) $threshold ) ) {
			$threshold = 5000.0;
		}

		return (float) $duration >= max( 0.0, (float) $threshold );
	}

	/**
	 * Sampling value in [0.0, 1.0], with a filterable seam for deterministic tests.
	 *
	 * The draw and the divisor share one explicit bound. `wp_rand()` called
	 * without arguments draws from 0..PHP_INT_MAX, which has no relation to
	 * `getrandmax()`; dividing one by the other put the result above 1.0 on all
	 * but a vanishing fraction of draws, so any rate below 1.0 discarded very
	 * nearly everything while the rate persisted with each rollup claimed the
	 * configured proportion had been kept.
	 */
	private function sample_value(): float {
		$value = $this->filter( 'saltus/framework/mcp/audit/sample_value', null );
		if ( is_numeric( $value ) ) {
			return max( 0.0, min( 1.0, (float) $value ) );
		}

		return (float) wp_rand( 1, self::SAMPLE_PRECISION ) / (float) self::SAMPLE_PRECISION;
	}

	/**
	 * Persist one slow call independently of normal audit sampling.
	 *
	 * @param array<string, mixed> $data Completed audit payload.
	 */
	private function record_slow_call( array $data ): void {
		$wpdb = $this->wpdb();
		if ( $wpdb === null ) {
			return;
		}

		$table           = $this->slow_table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE IF NOT EXISTS {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime(3) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			identifier varchar(191) NULL,
			ability varchar(191) NOT NULL,
			arguments longtext NULL,
			status varchar(32) NOT NULL,
			duration_ms double NOT NULL,
			error_code varchar(191) NULL,
			error_message text NULL,
			PRIMARY KEY (id),
			KEY created_at (created_at),
			KEY ability (ability),
			KEY duration_ms (duration_ms)
		) {$charset_collate}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Internal table name.
		$wpdb->query( $sql );
		$wpdb->insert(
			$table,
			[
				'created_at'    => $data['timestamp'],
				'user_id'       => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
				'identifier'    => $data['identifier'] !== null ? $this->sanitize( (string) $data['identifier'], 191 ) : null,
				'ability'       => $this->sanitize( (string) $data['tool'], 191 ),
				'arguments'     => $this->encode( is_array( $data['arguments'] ) ? $data['arguments'] : [] ),
				'status'        => $this->validate_status( (string) $data['status'] ),
				'duration_ms'   => (float) $data['duration_ms'],
				'error_code'    => $data['error_code'] !== null ? $this->sanitize( (string) $data['error_code'], 191 ) : null,
				'error_message' => $data['error_message'] !== null ? $this->sanitize( (string) $data['error_message'], 65535 ) : null,
			],
			[ '%s', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s' ]
		);
	}

	/**
	 * Prune slow calls independently from normal audit retention.
	 */
	private function cleanup_slow_calls(): void {
		$days = (int) $this->filter( 'saltus/framework/mcp/audit/slow_retention_days', 90 );
		if ( $days <= 0 ) {
			return;
		}

		$wpdb = $this->wpdb();
		if ( $wpdb === null ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s.000', time() - ( $days * ( defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 ) ) );
		$table  = $this->slow_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
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
