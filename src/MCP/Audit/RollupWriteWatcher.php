<?php
namespace Saltus\WP\Framework\MCP\Audit;

/**
 * AuditDatabase decorator that reports whether a rollup row failed to persist.
 *
 * The rollup freshness marker doubles as the backfill cursor, so advancing it
 * after a write that never landed both claims stale metrics are fresh and skips
 * the day forever — retention deletes the source rows and nothing can recompute
 * them. RollupStore writes rollups through the seam and does not surface the
 * result, so the retention pass wraps its adapter in this and asks afterwards.
 *
 * Only writes aimed at the rollup table are judged, and only an explicit false.
 * Two other outcomes reach the same seam and are not lost rollups: an upsert
 * that changes nothing reports zero affected rows, and the schema path issues
 * reads and DDL whose failure the migration already detects for itself by
 * re-reading the table. A schema failure that would cost a rollup shows up here
 * anyway, because the rollup write that follows it is the one that fails.
 */
class RollupWriteWatcher implements AuditDatabase {

	/** Marks a write aimed at the rollup table, wherever it appears. */
	private const ROLLUP_TABLE = 'saltus_mcp_audit_rollups';

	private AuditDatabase $database;

	private bool $write_failed = false;

	/**
	 * @param AuditDatabase $database  The adapter to write through.
	 */
	public function __construct( AuditDatabase $database ) {
		$this->database = $database;
	}

	/**
	 * Whether any rollup write reported failure since this instance was made.
	 */
	public function write_failed(): bool {
		return $this->write_failed;
	}

	public function prefix(): string {
		return $this->database->prefix();
	}

	/**
	 * @param array<string, mixed> $data
	 * @param list<string> $format
	 * @return bool|int
	 */
	public function insert( string $table, array $data, array $format = [] ) {
		$result = $this->database->insert( $table, $data, $format );

		if ( strpos( $table, self::ROLLUP_TABLE ) !== false ) {
			$this->judge( $result );
		}

		return $result;
	}

	/**
	 * @return bool|int
	 */
	public function query( string $query ) {
		$result = $this->database->query( $query );

		// An upsert is the only write RollupStore sends for a rollup row; the
		// prefix test is the same one the rollup suite uses to pick it out.
		if ( strpos( $query, 'INSERT INTO' ) === 0 && strpos( $query, self::ROLLUP_TABLE ) !== false ) {
			$this->judge( $result );
		}

		return $result;
	}

	public function get_charset_collate(): string {
		return $this->database->get_charset_collate();
	}

	/**
	 * @param mixed $output
	 * @return ($output is 'ARRAY_A' ? list<array<string, mixed>>|null : array<array-key, mixed>|object|null)
	 */
	public function get_results( string $query, $output = null ) {
		return $this->database->get_results( $query, $output );
	}

	/**
	 * @param mixed ...$args
	 */
	public function prepare( string $query, ...$args ): string {
		return $this->database->prepare( $query, ...$args );
	}

	/**
	 * Record a failure, judging only an explicit false.
	 *
	 * `query()` answers an upsert with the affected-row count, and re-running a
	 * rollup that changed nothing affects zero rows. Treating falsiness as
	 * failure would read that no-op as a lost write and freeze the freshness
	 * marker on every site whose rollups are already up to date.
	 *
	 * @param mixed $result Result returned by the wrapped adapter.
	 */
	private function judge( $result ): void {
		if ( $result === false ) {
			$this->write_failed = true;
		}
	}
}
