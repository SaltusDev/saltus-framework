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

	/** @var list<string> Every statement passed through prepare(), before substitution. */
	public array $prepared = [];

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

	/**
	 * Rollup dates whose upsert reports failure, as the real `query()` does when
	 * the write is rejected: false, and no row stored.
	 *
	 * @var list<string>
	 */
	public array $failing_rollup_dates = [];

	/**
	 * The rollup table's columns as `information_schema` reports them: name =>
	 * whether the column accepts NULL.
	 *
	 * Modelled rather than assumed, because the migration now decides what to do
	 * from this and nothing else. A double that answered a fixed shape could not
	 * tell a fresh install (no ALTER at all) from an upgrade, which is the whole
	 * distinction the portable migration rests on.
	 *
	 * Defaults to the current schema, so a test that says nothing about the
	 * schema is a fresh install.
	 *
	 * @var array<string, bool>
	 */
	public array $table_columns = [
		'id'                     => false,
		'rollup_date'            => false,
		'ability'                => false,
		'client_identifier'      => false,
		'sample_rate'            => false,
		'call_count'             => false,
		'error_count'            => false,
		'exception_count'        => false,
		'validation_error_count' => false,
		'rate_limited_count'     => false,
		'avg_duration_ms'        => false,
		'p50_duration_ms'        => false,
		'p95_duration_ms'        => false,
		'p99_duration_ms'        => false,
		'max_duration_ms'        => false,
	];

	/** @var list<string> Index names on the rollup table. */
	public array $table_indexes = [ 'PRIMARY', 'rollup_date_ability_client', 'ability', 'rollup_date' ];

	/** @var string Table prefix. */
	private string $prefix = 'wp_';
	public function prefix(): string {
		return $this->prefix;
	}

	/**
	 * Put the rollup table into the shape a given schema version left it in.
	 *
	 * '1.0.0' predates client attribution: no `client_identifier`, no
	 * `sample_rate`, and the two-column unique key. '1.1.0' has both columns with
	 * a nullable identifier, still under the two-column key — the state that let
	 * unconstrained NULL aggregate rows accumulate.
	 *
	 * @param string $version Schema version to model.
	 */
	public function useSchemaVersion( string $version ): void {
		if ( $version === '1.0.0' ) {
			unset( $this->table_columns['client_identifier'], $this->table_columns['sample_rate'] );
			$this->table_indexes = [ 'PRIMARY', 'rollup_date_ability', 'ability', 'rollup_date' ];

			return;
		}

		if ( $version === '1.1.0' ) {
			$this->table_columns['client_identifier'] = true;
			$this->table_indexes                      = [ 'PRIMARY', 'rollup_date_ability', 'ability', 'rollup_date' ];
		}
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
			if ( $this->rollupUpsertFails( $query ) ) {
				return false;
			}

			$this->applyRollupUpsert( $query );
		} elseif ( strpos( $query, 'UPDATE' ) === 0 && strpos( $query, "SET client_identifier = ''" ) !== false ) {
			// The 1.2.0 normalization: legacy aggregate rows onto the sentinel.
			foreach ( $this->rollup_rows as $index => $row ) {
				if ( ( $row['client_identifier'] ?? null ) === null ) {
					$this->rollup_rows[ $index ]['client_identifier'] = '';
				}
			}
		} elseif ( strpos( $query, 'ALTER TABLE' ) === 0 ) {
			$this->applyAlter( $query );
		} elseif ( strpos( $query, 'DELETE FROM' ) === 0 && strpos( $query, ' WHERE id IN (' ) !== false ) {
			$this->deleteRollupIds( $query );
		}

		return true;
	}

	/**
	 * Whether this upsert is for a date configured to fail.
	 *
	 * The date is read out of the statement rather than counted per call, so the
	 * failure follows the row being written however many abilities a date has.
	 */
	private function rollupUpsertFails( string $query ): bool {
		if ( $this->failing_rollup_dates === [] ) {
			return false;
		}

		$values_open  = strpos( $query, ') VALUES (' );
		$values_close = strpos( $query, ') ON DUPLICATE KEY UPDATE ' );

		if ( $values_open === false || $values_close === false ) {
			return false;
		}

		$offset = $values_open + strlen( ') VALUES (' );
		$values = $this->splitValues( substr( $query, $offset, $values_close - $offset ) );

		// rollup_date leads ROLLUP_COLUMNS, so it is the first value.
		return in_array( $values[0] ?? '', $this->failing_rollup_dates, true );
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
	 * Apply a plain DDL statement to the modelled schema.
	 *
	 * The store reads the table back before it records the schema version, so a
	 * double that recorded the statements without applying them would report an
	 * upgrade that never converged. Only the unguarded forms are understood: a
	 * statement carrying `IF NOT EXISTS` or `IF EXISTS` is MariaDB-only syntax
	 * MySQL rejects, and this models MySQL.
	 */
	private function applyAlter( string $query ): void {
		if ( strpos( $query, 'IF NOT EXISTS' ) !== false || strpos( $query, 'IF EXISTS' ) !== false ) {
			return;
		}

		if ( preg_match( '/ADD COLUMN (\w+)/', $query, $match ) === 1 ) {
			$this->table_columns[ $match[1] ] = strpos( $query, 'NOT NULL' ) === false;
			$this->backfillColumn( $match[1], $query );

			return;
		}

		if ( preg_match( '/MODIFY (\w+)/', $query, $match ) === 1 ) {
			$this->table_columns[ $match[1] ] = strpos( $query, 'NOT NULL' ) === false;

			return;
		}

		if ( preg_match( '/DROP INDEX (\w+)/', $query, $match ) === 1 ) {
			$this->table_indexes = array_values(
				array_filter(
					$this->table_indexes,
					static fn( string $index ): bool => $index !== $match[1]
				)
			);

			return;
		}

		if ( preg_match( '/ADD UNIQUE KEY (\w+)/', $query, $match ) === 1 ) {
			$this->table_indexes[] = $match[1];
		}
	}

	/**
	 * Give every existing row the new column's default, as `ADD COLUMN … NOT NULL
	 * DEFAULT` does. Unconditionally: the column is being added, so whatever a
	 * seeded row carries under that name is a value the table did not have.
	 */
	private function backfillColumn( string $column, string $query ): void {
		if ( preg_match( "/DEFAULT (?:'([^']*)'|([\d.]+))/", $query, $match ) !== 1 ) {
			return;
		}

		$default = ( $match[2] ?? '' ) !== '' ? (float) $match[2] : $match[1];

		foreach ( $this->rollup_rows as $index => $row ) {
			$this->rollup_rows[ $index ][ $column ] = $default;
		}
	}

	/**
	 * The ids the migration's bounded duplicate read returns: every row of a
	 * unique key but the highest id, up to the statement's own LIMIT.
	 *
	 * NULL is folded onto the sentinel here, matching the `COALESCE` the real
	 * join carries. That is deliberately not `rollupKey()`, which models the
	 * server's rule that a NULL collides with nothing — true of the unique index,
	 * and the reason these duplicates exist, but not of this statement, whose job
	 * is to find them.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function staleDuplicateIds( string $query ): array {
		$limit = preg_match( '/LIMIT (\d+)/', $query, $match ) === 1 ? (int) $match[1] : PHP_INT_MAX;

		$rows = $this->rollup_rows;
		usort(
			$rows,
			static fn( array $a, array $b ): int => ( (int) ( $a['id'] ?? 0 ) ) <=> ( (int) ( $b['id'] ?? 0 ) )
		);

		$newest = [];
		foreach ( $rows as $row ) {
			$newest[ $this->coalescedKey( $row ) ] = (int) ( $row['id'] ?? 0 );
		}

		$stale = [];
		foreach ( $rows as $row ) {
			if ( count( $stale ) >= $limit ) {
				break;
			}

			$id = (int) ( $row['id'] ?? 0 );
			if ( $newest[ $this->coalescedKey( $row ) ] !== $id ) {
				$stale[] = [ 'id' => $id ];
			}
		}

		return $stale;
	}

	/**
	 * A rollup row's unique key with NULL read as the sentinel.
	 *
	 * @param array<string, mixed> $row
	 */
	private function coalescedKey( array $row ): string {
		return (string) ( $row['rollup_date'] ?? '' ) . "\x00"
			. (string) ( $row['ability'] ?? '' ) . "\x00"
			. (string) ( $row['client_identifier'] ?? '' );
	}

	/**
	 * Delete the rollup rows named by an `id IN (…)` list.
	 */
	private function deleteRollupIds( string $query ): void {
		if ( preg_match( '/ WHERE id IN \(([\d,]+)\)/', $query, $match ) !== 1 ) {
			return;
		}

		$ids = array_map( 'intval', explode( ',', $match[1] ) );

		$this->rollup_rows = array_values(
			array_filter(
				$this->rollup_rows,
				static fn( array $row ): bool => ! in_array( (int) ( $row['id'] ?? 0 ), $ids, true )
			)
		);
	}

	/**
	 * Substituted with substr_replace() rather than preg_replace(): a backslash in
	 * a value is an escape in a regex replacement string, so `\\` collapsed to `\`
	 * and a slashed value produced the same SQL as its unslashed form — which is
	 * exactly the difference an unslashing test has to be able to see.
	 */
	public function prepare( string $query, ...$args ): string {
		$this->prepared[] = $query;

		foreach ( $args as $arg ) {
			$replacement = is_string( $arg ) ? "'" . $arg . "'" : (string) $arg;

			if ( preg_match( '/%[dsf]/', $query, $match, PREG_OFFSET_CAPTURE ) !== 1 ) {
				continue;
			}

			$query = substr_replace( $query, $replacement, (int) $match[0][1], strlen( $match[0][0] ) );
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

		if ( strpos( $query, 'SELECT COLUMN_NAME' ) === 0 ) {
			return $this->informationSchemaColumns();
		}

		if ( strpos( $query, 'SELECT DISTINCT INDEX_NAME' ) === 0 ) {
			return $this->informationSchemaIndexes();
		}

		if ( strpos( $query, 'SELECT DISTINCT stale.id' ) === 0 ) {
			return $this->staleDuplicateIds( $query );
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
	 * The modelled columns in the shape `information_schema.COLUMNS` returns,
	 * under the lower-cased aliases the store's own statement asks for.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function informationSchemaColumns(): array {
		$rows = [];
		foreach ( $this->table_columns as $column => $nullable ) {
			$rows[] = [
				'column_name' => $column,
				'is_nullable' => $nullable ? 'YES' : 'NO',
			];
		}

		return $rows;
	}

	/**
	 * The modelled index names in the shape `information_schema.STATISTICS`
	 * returns.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function informationSchemaIndexes(): array {
		return array_map(
			static fn( string $index ): array => [ 'index_name' => $index ],
			$this->table_indexes
		);
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

		// The full ORDER BY both reads carry, primary key last. Modelled rather
		// than approximated: paging is only sound because the sort is total, so a
		// double that stopped at (date, ability) could not tell a deterministic
		// page from one that repeats or skips a row on the boundary.
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$by_date = (string) ( $a['rollup_date'] ?? '' ) <=> (string) ( $b['rollup_date'] ?? '' );
				if ( $by_date !== 0 ) {
					return $by_date;
				}

				$by_ability = (string) ( $a['ability'] ?? '' ) <=> (string) ( $b['ability'] ?? '' );
				if ( $by_ability !== 0 ) {
					return $by_ability;
				}

				$by_client = (string) ( $a['client_identifier'] ?? '' ) <=> (string) ( $b['client_identifier'] ?? '' );
				if ( $by_client !== 0 ) {
					return $by_client;
				}

				return ( (int) ( $a['id'] ?? 0 ) ) <=> ( (int) ( $b['id'] ?? 0 ) );
			}
		);

		return $this->applyPaging( $query, $rows );
	}

	/**
	 * Apply the LIMIT/OFFSET tail of a rollup read.
	 *
	 * Without this the bound would be unobservable: the store could stop sending
	 * LIMIT entirely and every read test would still pass.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	private function applyPaging( string $query, array $rows ): array {
		if ( preg_match( '/LIMIT (\d+) OFFSET (\d+)/', $query, $matches ) !== 1 ) {
			return $rows;
		}

		return array_values( array_slice( $rows, (int) $matches[2], (int) $matches[1] ) );
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

