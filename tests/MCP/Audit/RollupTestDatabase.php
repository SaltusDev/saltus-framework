<?php

namespace Saltus\WP\Framework\Tests\MCP\Audit;

use Saltus\WP\Framework\MCP\Audit\AuditDatabase;

/**
 * In-memory AuditDatabase that understands the handful of statements RollupStore
 * and MetricsCommand issue.
 *
 * The shared suite-wide `$wpdb` double answers every `get_results()` with the
 * rows that were inserted, ignoring the query. That is enough for AuditLogger,
 * which only ever writes, but it cannot express "aggregate yesterday's audit
 * rows into a rollup and read the rollup back" — the assertion would pass on a
 * store that never filtered by date at all. So this double keeps two real row
 * sets and interprets the WHERE clauses that matter.
 */
class RollupTestDatabase implements AuditDatabase {

	/** @var list<array<string, mixed>> Audit rows, as if already logged. */
	public array $audit_rows = [];

	/** @var list<array<string, mixed>> Rollup rows written through insert(). */
	public array $rollup_rows = [];

	/**
	 * Every rollup upsert seen, in order, as a column => value map.
	 *
	 * Rollups are written through `query()` as one atomic statement, so they do
	 * not appear in `$inserts`.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $rollup_writes = [];

	/** Distinguishes each NULL client identifier, which collides with nothing. */
	private int $null_key_counter = 0;

	/** @var list<array<string, mixed>> Slow-call rows written through insert(). */
	public array $slow_rows = [];

	/** @var list<string> Every statement seen, in order. */
	public array $queries = [];

	/** @var list<array<string, mixed>> Every insert seen, in order. */
	public array $inserts = [];

	/** @var bool Whether SHOW TABLES should report the audit table present. */
	public bool $audit_table_exists = true;

	/**
	 * When true, duration rows come back in insertion order instead of the
	 * ORDER BY the real statement carries. Models a driver or server that does
	 * not honor the sort, so the percentile maths cannot quietly depend on it.
	 *
	 * @var bool
	 */
	public bool $ignore_order_by = false;

	/** @var string Table prefix. */
	private string $prefix = 'wp_';
	public function prefix(): string {
		return $this->prefix;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param list<string> $format
	 */
	public function insert( string $table, array $data, array $format = [] ): bool {
		$this->inserts[] = compact( 'table', 'data', 'format' );

		if ( strpos( $table, 'saltus_mcp_audit_slow_calls' ) !== false ) {
			$this->slow_rows[] = $data;
		}

		return true;
	}

	public function query( string $query ) {
		$this->queries[] = $query;

		if ( strpos( $query, 'INSERT INTO' ) === 0 && strpos( $query, 'ON DUPLICATE KEY UPDATE' ) !== false ) {
			$this->applyRollupUpsert( $query );
		} elseif ( strpos( $query, 'UPDATE' ) === 0 && strpos( $query, "SET client_identifier = ''" ) !== false ) {
			// The 1.2.0 normalization: legacy aggregate rows onto the sentinel.
			foreach ( $this->rollup_rows as $index => $row ) {
				if ( ( $row['client_identifier'] ?? null ) === null ) {
					$this->rollup_rows[ $index ]['client_identifier'] = '';
				}
			}
		} elseif ( strpos( $query, 'DELETE stale FROM' ) === 0 ) {
			$this->collapseDuplicateRollups();
		}

		return true;
	}

	/**
	 * Apply the single-statement rollup upsert, modelling the unique key.
	 *
	 * Uniqueness over (rollup_date, ability, client_identifier) is modelled
	 * rather than assumed: without it a test could not tell an upsert that
	 * replaces from a plain insert that duplicates, which is the entire point of
	 * the statement.
	 *
	 * A quoted value containing a comma is handled. One containing an escaped
	 * quote is not — the real `prepare()` escapes, this double does not.
	 */
	private function applyRollupUpsert( string $query ): void {
		$columns_open = strpos( $query, '(' );
		$values_open  = strpos( $query, ') VALUES (' );
		$values_close = strpos( $query, ') ON DUPLICATE KEY UPDATE ' );

		if ( $columns_open === false || $values_open === false || $values_close === false ) {
			return;
		}

		$columns = array_map(
			'trim',
			explode( ',', substr( $query, $columns_open + 1, $values_open - $columns_open - 1 ) )
		);

		$offset = $values_open + strlen( ') VALUES (' );
		$values = $this->splitValues( substr( $query, $offset, $values_close - $offset ) );

		$row = [];
		foreach ( $columns as $index => $column ) {
			$row[ $column ] = $this->castRollupValue( $column, $values[ $index ] ?? '' );
		}

		$this->rollup_writes[] = $row;

		foreach ( $this->rollup_rows as $index => $existing ) {
			if ( $this->rollupKey( $existing ) === $this->rollupKey( $row ) ) {
				$row['id']                   = $existing['id'] ?? ( $index + 1 );
				$this->rollup_rows[ $index ] = $row;

				return;
			}
		}

		$row['id']           = count( $this->rollup_rows ) + 1;
		$this->rollup_rows[] = $row;
	}

	/**
	 * Split a prepared VALUES list, honoring quoted values that contain commas.
	 *
	 * @return list<string>
	 */
	private function splitValues( string $raw ): array {
		$values  = [];
		$current = '';
		$quoted  = false;
		$length  = strlen( $raw );

		for ( $index = 0; $index < $length; $index++ ) {
			$char = $raw[ $index ];

			if ( $char === "'" ) {
				$quoted = ! $quoted;
				continue;
			}

			if ( $char === ',' && ! $quoted ) {
				$values[] = trim( $current );
				$current  = '';
				continue;
			}

			$current .= $char;
		}

		$values[] = trim( $current );

		return $values;
	}

	/**
	 * Coerce a parsed value to the type the real column holds, so rows written
	 * through the upsert are shaped like rows seeded directly.
	 *
	 * @return mixed
	 */
	private function castRollupValue( string $column, string $value ) {
		$integers = [ 'call_count', 'error_count', 'exception_count', 'validation_error_count', 'rate_limited_count' ];
		$floats   = [ 'sample_rate', 'avg_duration_ms', 'p50_duration_ms', 'p95_duration_ms', 'p99_duration_ms', 'max_duration_ms' ];

		if ( in_array( $column, $integers, true ) ) {
			return (int) $value;
		}

		if ( in_array( $column, $floats, true ) ) {
			return (float) $value;
		}

		return $value;
	}

	/**
	 * The unique key of a rollup row.
	 *
	 * A NULL client identifier collides with nothing, matching the rule that
	 * makes this fix necessary: a unique index never treats two NULLs as equal,
	 * so aggregate rows stored as NULL are entirely unconstrained. Storing the
	 * sentinel instead is what brings them under the key, and modelling it here
	 * is what lets a test tell the two apart.
	 *
	 * @param array<string, mixed> $row
	 */
	private function rollupKey( array $row ): string {
		if ( ( $row['client_identifier'] ?? null ) === null ) {
			return "\x00unconstrained:" . ( ++$this->null_key_counter );
		}

		return (string) $row['rollup_date'] . "\x00"
			. (string) $row['ability'] . "\x00"
			. (string) $row['client_identifier'];
	}

	/**
	 * Collapse rows sharing a unique key, keeping the highest id — the row the
	 * migration's `newer.id > stale.id` join keeps.
	 */
	private function collapseDuplicateRollups(): void {
		$rows = $this->rollup_rows;
		usort(
			$rows,
			static fn( array $a, array $b ): int => ( (int) ( $a['id'] ?? 0 ) ) <=> ( (int) ( $b['id'] ?? 0 ) )
		);

		$kept = [];
		foreach ( $rows as $row ) {
			$kept[ $this->rollupKey( $row ) ] = $row;
		}

		$this->rollup_rows = array_values( $kept );
	}

	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = is_string( $arg ) ? "'" . $arg . "'" : (string) $arg;
			$query       = (string) preg_replace( '/%[dsf]/', $replacement, $query, 1 );
		}

		return $query;
	}

	public function get_charset_collate(): string {
		return '';
	}

	/**
	 * Interpret the statements RollupStore and MetricsCommand actually send.
	 *
	 * @param string $query  The SQL query.
	 * @param mixed  $output Row format (ignored; rows are always associative).
	 * @return list<array<string, mixed>>
	 */
	public function get_results( string $query, $output = null ) {
		$this->queries[] = $query;

		if ( strpos( $query, 'SHOW TABLES LIKE' ) === 0 ) {
			return $this->audit_table_exists ? [ [ 'Tables_in_wp' => $this->prefix . 'saltus_mcp_audit' ] ] : [];
		}

		if ( strpos( $query, 'SELECT DISTINCT ability' ) === 0 ) {
			return $this->distinctAbilities( $query );
		}

		if ( strpos( $query, 'SELECT status, duration_ms' ) === 0 ) {
			return $this->auditRowsFor( $query );
		}

		if ( strpos( $query, 'SELECT created_at FROM' ) === 0 ) {
			return $this->latestAuditEntry();
		}

		if ( strpos( $query, 'SELECT * FROM' ) === 0 && strpos( $query, 'saltus_mcp_audit_rollups' ) !== false ) {
			return $this->rollupsInRange( $query );
		}

		return [];
	}

	/**
	 * Seed one audit row.
	 *
	 * @param string      $ability     Ability name.
	 * @param string      $status      Audit status.
	 * @param float|null  $duration    Duration in ms, or null for a missing value.
	 * @param string      $created_at  Timestamp, `Y-m-d H:i:s.v`.
	 * @param string|null $identifier  Persisted client identifier (optional).
	 */
	public function addAuditRow(
		string $ability,
		string $status,
		?float $duration,
		string $created_at,
		?string $identifier = null
	): void {
		$this->audit_rows[] = [
			'ability'     => $ability,
			'status'      => $status,
			'duration_ms' => $duration,
			'created_at'  => $created_at,
			'identifier'  => $identifier,
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function distinctAbilities( string $query ): array {
		[ $start, $end ] = $this->captureRange( $query );

		// Client mode uses SELECT DISTINCT ability, identifier FROM …
		$include_identifier = strpos( $query, 'ability, identifier' ) !== false;

		$seen   = [];
		$result = [];

		foreach ( $this->audit_rows as $row ) {
			$created = (string) ( $row['created_at'] ?? '' );
			if ( $created < $start || $created > $end ) {
				continue;
			}

			$ability    = (string) ( $row['ability'] ?? '' );
			$identifier = array_key_exists( 'identifier', $row ) ? $row['identifier'] : null;

			if ( $ability === '' ) {
				continue;
			}

			// Null-safe dedup key: NUL byte separates the two fields so an empty
			// identifier and a missing one are treated identically.
			$key = $include_identifier
				? $ability . "\x00" . ( $identifier ?? '' )
				: $ability;

			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$out          = [ 'ability' => $ability ];
				if ( $include_identifier ) {
					$out['identifier'] = $identifier;
				}
				$result[] = $out;
			}
		}

		return $result;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function auditRowsFor( string $query ): array {
		$ability         = $this->captureQuoted( $query, "ability = '" );
		[ $start, $end ] = $this->captureRange( $query );

		// Optional client identifier scoping (client-mode rollup queries).
		$client_scoped     = strpos( $query, "AND identifier = '" ) !== false;
		$client_identifier = $client_scoped
			? $this->captureQuoted( $query, "AND identifier = '" )
			: null;

		$rows = [];
		foreach ( $this->audit_rows as $row ) {
			$created = (string) ( $row['created_at'] ?? '' );
			if ( (string) ( $row['ability'] ?? '' ) !== $ability || $created < $start || $created > $end ) {
				continue;
			}

			if ( $client_scoped ) {
				$row_id = array_key_exists( 'identifier', $row ) && $row['identifier'] !== null
					? (string) $row['identifier']
					: '';
				if ( $row_id !== $client_identifier ) {
					continue;
				}
			}

			$rows[] = [
				'status'      => $row['status'],
				'duration_ms' => $row['duration_ms'],
			];
		}

		if ( $this->ignore_order_by ) {
			return $rows;
		}

		// Mirror the ORDER BY duration_ms ASC the real query carries.
		usort(
			$rows,
			static fn( array $a, array $b ): int => ( (float) ( $a['duration_ms'] ?? 0 ) ) <=> ( (float) ( $b['duration_ms'] ?? 0 ) )
		);

		return $rows;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function latestAuditEntry(): array {
		if ( $this->audit_rows === [] ) {
			return [];
		}

		$timestamps = array_map( static fn( array $row ): string => (string) ( $row['created_at'] ?? '' ), $this->audit_rows );
		rsort( $timestamps );

		return [ [ 'created_at' => $timestamps[0] ] ];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function rollupsInRange( string $query ): array {
		$start   = $this->captureQuoted( $query, "rollup_date >= '" );
		$end     = $this->captureQuoted( $query, "rollup_date <= '" );
		$ability = strpos( $query, "AND ability = '" ) !== false
			? $this->captureQuoted( $query, "AND ability = '" )
			: null;

		// The two mutually exclusive client predicates the store sends. Aggregate
		// rows are the sentinel, tolerating NULL for a table not yet normalized.
		$aggregate_only    = strpos( $query, "client_identifier = '' OR client_identifier IS NULL" ) !== false;
		$clients_only      = strpos( $query, "client_identifier IS NOT NULL AND client_identifier <> ''" ) !== false;
		$client_identifier = strpos( $query, "AND client_identifier = '" ) !== false
			? $this->captureQuoted( $query, "AND client_identifier = '" )
			: null;

		$rows = [];
		foreach ( $this->rollup_rows as $row ) {
			$date = (string) ( $row['rollup_date'] ?? '' );
			if ( $start !== '' && $date < $start ) {
				continue;
			}
			if ( $end !== '' && $date > $end ) {
				continue;
			}
			if ( $ability !== null && (string) ( $row['ability'] ?? '' ) !== $ability ) {
				continue;
			}

			$row_client = (string) ( $row['client_identifier'] ?? '' );

			if ( $aggregate_only && $row_client !== '' ) {
				continue;
			}
			if ( $clients_only && $row_client === '' ) {
				continue;
			}
			if ( $client_identifier !== null && $row_client !== $client_identifier ) {
				continue;
			}

			$rows[] = $row;
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$by_date = (string) ( $a['rollup_date'] ?? '' ) <=> (string) ( $b['rollup_date'] ?? '' );
				if ( $by_date !== 0 ) {
					return $by_date;
				}

				return (string) ( $a['ability'] ?? '' ) <=> (string) ( $b['ability'] ?? '' );
			}
		);

		return $rows;
	}

	/**
	 * Pull the value that follows a quoted marker in a prepared statement.
	 */
	private function captureQuoted( string $query, string $marker ): string {
		$offset = strpos( $query, $marker );
		if ( $offset === false ) {
			return '';
		}

		$offset += strlen( $marker );
		$end     = strpos( $query, "'", $offset );

		return $end === false ? '' : substr( $query, $offset, $end - $offset );
	}

	/**
	 * Pull the created_at range bounds out of a prepared statement.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function captureRange( string $query ): array {
		return [
			$this->captureQuoted( $query, "created_at >= '" ),
			$this->captureQuoted( $query, "created_at <= '" ),
		];
	}
}

