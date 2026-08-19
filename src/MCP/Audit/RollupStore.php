<?php
namespace Saltus\WP\Framework\MCP\Audit;

/**
 * Stores pre-aggregated daily metrics to avoid full audit table scans.
 * @api
 */
class RollupStore {
	use \Saltus\WP\Framework\Infrastructure\Services\FilterAwareTrait;

	private const TABLE_SUFFIX    = 'saltus_mcp_audit_rollups';
	private const DB_VERSION      = '1.2.0';
	public const FRESHNESS_OPTION = 'saltus_mcp_rollup_last_completed_at';

	/**
	 * Stored value standing in for "every client" on an aggregate row.
	 *
	 * Deliberately not NULL. The unique key that keeps one row per day and
	 * ability cannot constrain NULLs — a unique index permits unlimited ones —
	 * so aggregate rows, which is what the default mode writes, would have no
	 * uniqueness at all and repeated rollups would stack duplicates.
	 */
	private const AGGREGATE_CLIENT = '';

	/**
	 * Rollup columns in write order, each with its `prepare()` format.
	 *
	 * @var array<string, string>
	 */
	private const ROLLUP_COLUMNS = [
		'rollup_date'            => '%s',
		'ability'                => '%s',
		'client_identifier'      => '%s',
		'sample_rate'            => '%f',
		'call_count'             => '%d',
		'error_count'            => '%d',
		'exception_count'        => '%d',
		'validation_error_count' => '%d',
		'rate_limited_count'     => '%d',
		'avg_duration_ms'        => '%f',
		'p50_duration_ms'        => '%f',
		'p95_duration_ms'        => '%f',
		'p99_duration_ms'        => '%f',
		'max_duration_ms'        => '%f',
	];

	/** Columns of the unique key, left out of the ON DUPLICATE update list. */
	private const ROLLUP_KEY_COLUMNS = [ 'rollup_date', 'ability', 'client_identifier' ];

	private bool $table_initialized = false;

	/**
	 * Injected database adapter, or null to resolve the global on each call.
	 *
	 * Rollups read the audit table and write the rollup table, so they must go
	 * through the same adapter the writes went through. Resolving the global
	 * directly would silently no-op wherever `$wpdb` is an `AuditDatabase`
	 * rather than a `\wpdb` — including every test in this suite.
	 *
	 * @var AuditDatabase|null
	 */
	private ?AuditDatabase $database;

	/**
	 * @param AuditDatabase|null $database  Optional database adapter. Resolved
	 *                                      from the global when omitted.
	 */
	public function __construct( ?AuditDatabase $database = null ) {
		$this->database = $database;
	}

	/**
	 * Compute rollup metrics for all abilities on a given date.
	 *
	 * Reads from the audit table, aggregates per ability (and optionally per
	 * client), and stores results. Must be called before retention cleanup
	 * deletes the source rows.
	 *
	 * @param string $date Date in Y-m-d format.
	 * @return int Number of rollup rows written.
	 */
	public function compute_and_store_rollup( string $date ): int {
		if ( ! $this->enabled() ) {
			return 0;
		}

		$this->ensure_table();

		$wpdb = $this->wpdb();
		if ( $wpdb === null ) {
			return 0;
		}

		$audit_table = $this->audit_table_name();
		$start       = $date . ' 00:00:00.000';
		$end         = $date . ' 23:59:59.999';
		$client_mode = (bool) $this->filter( 'saltus/framework/mcp/audit/rollup_by_client', false );

		// `get_results` rather than `get_col`, because the scalar form is not on
		// the AuditDatabase seam. When client mode is off, select only ability so
		// legacy rows (which lack an identifier column) still work.
		if ( $client_mode ) {
			$ability_rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name; values are placeholders.
					"SELECT DISTINCT ability, identifier FROM {$audit_table} WHERE created_at >= %s AND created_at <= %s",
					$start,
					$end
				),
				$this->array_output()
			);
		} else {
			$ability_rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name; values are placeholders.
					"SELECT DISTINCT ability FROM {$audit_table} WHERE created_at >= %s AND created_at <= %s",
					$start,
					$end
				),
				$this->array_output()
			);
		}

		if ( ! is_array( $ability_rows ) || $ability_rows === [] ) {
			return 0;
		}

		// Build a deduplicated list of (ability, client_identifier) pairs. When
		// client mode is off every pair uses null so one aggregate row is written.
		$pairs = [];
		foreach ( $ability_rows as $ability_row ) {
			if ( ! is_array( $ability_row ) || ! isset( $ability_row['ability'] ) ) {
				continue;
			}

			$ability = (string) $ability_row['ability'];
			if ( $ability === '' ) {
				continue;
			}

			$client = $client_mode
				? ( isset( $ability_row['identifier'] ) ? (string) $ability_row['identifier'] : null )
				: null;

			$key = $ability . '|' . ( $client ?? '' );
			if ( ! isset( $pairs[ $key ] ) ) {
				$pairs[ $key ] = [
					'ability' => $ability,
					'client'  => $client,
				];
			}
		}

		$sample_rate = $this->normalize_sample_rate(
			$this->filter( 'saltus/framework/mcp/audit/sample_rate', 1.0 )
		);

		$count = 0;
		foreach ( $pairs as $pair ) {
			$rollup = $this->compute_rollup_for_ability( $date, $pair['ability'], $pair['client'], $sample_rate );
			if ( $rollup instanceof DailyRollup ) {
				$this->store_rollup( $rollup );
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Record that a complete rollup cycle finished successfully.
	 *
	 * Called by the scheduled cron handler after all target dates succeed. An
	 * explicit marker is used instead of inferring freshness from the newest
	 * rollup row, because a no-traffic day produces no rows but is still valid.
	 *
	 * @param string $completed_through Last date (Y-m-d) the cycle covered.
	 */
	public function record_completion( string $completed_through ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option(
				self::FRESHNESS_OPTION,
				[
					'at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'through' => $completed_through,
				],
				false
			);
		}
	}

	/**
	 * Return freshness metadata for the rollup schedule.
	 *
	 * @return array{
	 *   enabled: bool,
	 *   expected_through: string,
	 *   last_completed_through: string|null,
	 *   last_completed_at: string|null,
	 *   stale: bool,
	 *   status: string
	 * }
	 */
	public function get_freshness(): array {
		$enabled           = $this->enabled();
		$expected_through  = gmdate( 'Y-m-d', strtotime( 'yesterday' ) );
		$last_completed_at = null;
		$last_through      = null;

		if ( function_exists( 'get_option' ) ) {
			$stored = get_option( self::FRESHNESS_OPTION );
			if ( is_array( $stored ) ) {
				$last_completed_at = isset( $stored['at'] ) ? (string) $stored['at'] : null;
				$last_through      = isset( $stored['through'] ) ? (string) $stored['through'] : null;
			}
		}

		if ( ! $enabled ) {
			return [
				'enabled'                => false,
				'expected_through'       => $expected_through,
				'last_completed_through' => $last_through,
				'last_completed_at'      => $last_completed_at,
				'stale'                  => false,
				'status'                 => 'disabled',
			];
		}

		if ( $last_through === null ) {
			return [
				'enabled'                => true,
				'expected_through'       => $expected_through,
				'last_completed_through' => null,
				'last_completed_at'      => null,
				'stale'                  => false,
				'status'                 => 'unknown',
			];
		}

		$stale  = $last_through < $expected_through;
		$status = $stale ? 'stale' : 'fresh';

		return [
			'enabled'                => true,
			'expected_through'       => $expected_through,
			'last_completed_through' => $last_through,
			'last_completed_at'      => $last_completed_at,
			'stale'                  => $stale,
			'status'                 => $status,
		];
	}

	/**
	 * Compute rollup metrics for one ability on one date, optionally scoped to a
	 * specific persisted client identifier.
	 *
	 * @param string      $date        Date in Y-m-d format.
	 * @param string      $ability     Ability name.
	 * @param string|null $client      Persisted identifier value, or null for aggregate.
	 * @param float       $sample_rate Effective sampling rate to persist.
	 * @return DailyRollup|null Null if no data found.
	 */
	private function compute_rollup_for_ability(
		string $date,
		string $ability,
		?string $client,
		float $sample_rate = 1.0
	): ?DailyRollup {
		$wpdb = $this->wpdb();
		if ( $wpdb === null ) {
			return null;
		}

		$audit_table = $this->audit_table_name();
		$start       = $date . ' 00:00:00.000';
		$end         = $date . ' 23:59:59.999';

		// Get all duration values and status counts for this ability on this date,
		// optionally scoped by client identifier. The identifier column may be NULL
		// in legacy rows, so an unscoped aggregate query omits the identifier
		// predicate entirely rather than matching only null rows.
		if ( $client !== null ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is an internal constant; values are placeholders.
					"SELECT status, duration_ms FROM {$audit_table} WHERE ability = %s AND identifier = %s AND created_at >= %s AND created_at <= %s ORDER BY duration_ms ASC",
					$ability,
					$client,
					$start,
					$end
				),
				$this->array_output()
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is an internal constant; values are placeholders.
					"SELECT status, duration_ms FROM {$audit_table} WHERE ability = %s AND created_at >= %s AND created_at <= %s ORDER BY duration_ms ASC",
					$ability,
					$start,
					$end
				),
				$this->array_output()
			);
		}

		if ( ! is_array( $rows ) || $rows === [] ) {
			return null;
		}

		$tally      = $this->tally_rows( $rows );
		$call_count = $tally['call_count'];
		$durations  = $tally['durations'];

		if ( $call_count === 0 ) {
			return null;
		}

		// The query orders by duration, but percentiles must not depend on the
		// server honoring it — a NULL duration or a driver that returns rows
		// unordered would otherwise shift every percentile silently.
		sort( $durations );

		$avg = count( $durations ) > 0 ? array_sum( $durations ) / count( $durations ) : 0.0;
		$p50 = $this->percentile( $durations, 50 );
		$p95 = $this->percentile( $durations, 95 );
		$p99 = $this->percentile( $durations, 99 );
		$max = count( $durations ) > 0 ? max( $durations ) : 0.0;

		return new DailyRollup(
			[
				'date'                   => $date,
				'ability'                => $ability,
				'client_identifier'      => $client,
				'sample_rate'            => $sample_rate,
				'call_count'             => $call_count,
				'error_count'            => $tally['error_count'],
				'exception_count'        => $tally['exception_count'],
				'validation_error_count' => $tally['validation_error_count'],
				'rate_limited_count'     => $tally['rate_limited_count'],
				'avg_duration_ms'        => $avg,
				'p50_duration_ms'        => $p50,
				'p95_duration_ms'        => $p95,
				'p99_duration_ms'        => $p99,
				'max_duration_ms'        => $max,
			]
		);
	}

	/**
	 * Tally call counts, per-status counts, and durations from audit rows.
	 *
	 * `field_denied` is deliberately absent from the status branches: Phase 11
	 * made it its own audit status precisely so a per-field permission denial does
	 * not count toward the error rate. It still counts as a call.
	 *
	 * @param array<int, mixed> $rows  Rows of `status` and `duration_ms`.
	 * @return array{
	 *   call_count: int,
	 *   error_count: int,
	 *   exception_count: int,
	 *   validation_error_count: int,
	 *   rate_limited_count: int,
	 *   durations: list<float>
	 * }
	 */
	private function tally_rows( array $rows ): array {
		$counts = [
			'error'            => 0,
			'exception'        => 0,
			'validation_error' => 0,
			'rate_limited'     => 0,
		];

		$call_count = 0;
		$durations  = [];

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			++$call_count;

			$status = isset( $row['status'] ) ? (string) $row['status'] : '';
			if ( array_key_exists( $status, $counts ) ) {
				++$counts[ $status ];
			}

			// A null duration is a call without a recorded latency — a `started`
			// row. Skipped rather than coerced, because a zero would drag every
			// percentile toward it.
			if ( isset( $row['duration_ms'] ) && is_numeric( $row['duration_ms'] ) ) {
				$durations[] = (float) $row['duration_ms'];
			}
		}

		return [
			'call_count'             => $call_count,
			'error_count'            => $counts['error'],
			'exception_count'        => $counts['exception'],
			'validation_error_count' => $counts['validation_error'],
			'rate_limited_count'     => $counts['rate_limited'],
			'durations'              => $durations,
		];
	}

	/**
	 * Write a rollup, replacing any existing row for the same day.
	 *
	 * One statement rather than check-then-delete-then-insert. The retention
	 * pass recomputes the same date twice by design and two workers can overlap,
	 * so a three-step sequence lets a second run land between the check and the
	 * insert and leave two rows for one (date, ability, client). Every total read
	 * back from the table would then count that date more than once.
	 *
	 * `ON DUPLICATE KEY UPDATE` leans on the unique key over the three key
	 * columns, which is why an aggregate row stores the empty-string sentinel
	 * instead of NULL: see AGGREGATE_CLIENT.
	 *
	 * `VALUES(col)` is deprecated in MySQL 8.0.20 in favour of an aliased row,
	 * but the alias form is absent from MariaDB and from MySQL before 8.0.19,
	 * and `VALUES()` still works throughout 8.x, so it stays the portable form.
	 *
	 * @param DailyRollup $rollup The rollup to store.
	 */
	private function store_rollup( DailyRollup $rollup ): void {
		$wpdb = $this->wpdb();
		if ( $wpdb === null ) {
			return;
		}

		$values = [
			'rollup_date'            => $rollup->date(),
			'ability'                => $rollup->ability(),
			'client_identifier'      => $rollup->client_identifier() ?? self::AGGREGATE_CLIENT,
			'sample_rate'            => $rollup->sample_rate(),
			'call_count'             => $rollup->call_count(),
			'error_count'            => $rollup->error_count(),
			'exception_count'        => $rollup->exception_count(),
			'validation_error_count' => $rollup->validation_error_count(),
			'rate_limited_count'     => $rollup->rate_limited_count(),
			'avg_duration_ms'        => $rollup->avg_duration_ms(),
			'p50_duration_ms'        => $rollup->p50_duration_ms(),
			'p95_duration_ms'        => $rollup->p95_duration_ms(),
			'p99_duration_ms'        => $rollup->p99_duration_ms(),
			'max_duration_ms'        => $rollup->max_duration_ms(),
		];

		// Argument order is taken from the column map, not from the array above,
		// so reordering one cannot silently misalign the placeholders.
		$args    = [];
		$updates = [];
		foreach ( array_keys( self::ROLLUP_COLUMNS ) as $column ) {
			$args[] = $values[ $column ];

			if ( ! in_array( $column, self::ROLLUP_KEY_COLUMNS, true ) ) {
				$updates[] = "{$column} = VALUES({$column})";
			}
		}

		$table = $this->table_name();
		$sql   = 'INSERT INTO ' . $table
			. ' (' . implode( ', ', array_keys( self::ROLLUP_COLUMNS ) ) . ')'
			. ' VALUES (' . implode( ', ', self::ROLLUP_COLUMNS ) . ')'
			. ' ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Internal table and column names; every value is a placeholder.
		$wpdb->query( $wpdb->prepare( $sql, ...$args ) );
	}

	/**
	 * Retrieve rollups for a date range, optionally filtered by ability.
	 *
	 * By default returns only aggregate rows so sites that later enable
	 * client-dimension rollups cannot accidentally double-count totals. Pass a
	 * non-null $client_identifier to query a specific opaque client.
	 *
	 * @param string      $start_date        Start date in Y-m-d format.
	 * @param string      $end_date          End date in Y-m-d format.
	 * @param string|null $ability           Optional ability filter.
	 * @param string|null $client_identifier Optional client identifier filter (null = aggregate only).
	 * @return list<DailyRollup>
	 */
	public function get_rollups(
		string $start_date,
		string $end_date,
		?string $ability = null,
		?string $client_identifier = null
	): array {
		$this->ensure_table();

		$wpdb = $this->wpdb();
		if ( $wpdb === null ) {
			return [];
		}

		$table = $this->table_name();
		$where = $wpdb->prepare( 'WHERE rollup_date >= %s AND rollup_date <= %s', $start_date, $end_date );

		if ( $ability !== null && $ability !== '' ) {
			$where .= $wpdb->prepare( ' AND ability = %s', $ability );
		}

		// Restrict to aggregate-only rows by default to prevent double-counting
		// when both aggregate and per-client rows coexist after enabling client mode.
		if ( $client_identifier !== null ) {
			$where .= $wpdb->prepare( ' AND client_identifier = %s', $client_identifier );
		} else {
			// Aggregate rows carry the sentinel. NULL is matched too, so a table
			// whose normalization migration has not run yet — or was rejected —
			// keeps answering with the aggregate rows it already has instead of
			// going silently blank.
			$where .= " AND ( client_identifier = '' OR client_identifier IS NULL )";
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY rollup_date ASC, ability ASC", $this->array_output() );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$rollups = [];
		foreach ( $rows as $row ) {
			$rollup = $this->hydrate_rollup( $row );
			if ( $rollup instanceof DailyRollup ) {
				$rollups[] = $rollup;
			}
		}

		return $rollups;
	}

	/**
	 * Retrieve only client-scoped rollups for a date range.
	 *
	 * This is intentionally separate from get_rollups(): aggregate and client
	 * dimensions are alternative views and must never be combined implicitly.
	 *
	 * @param string      $start_date Start date in Y-m-d format.
	 * @param string      $end_date   End date in Y-m-d format.
	 * @param string|null $ability    Optional ability filter.
	 * @return list<DailyRollup>
	 */
	public function get_client_rollups( string $start_date, string $end_date, ?string $ability = null ): array {
		$this->ensure_table();

		$wpdb = $this->wpdb();
		if ( $wpdb === null ) {
			return [];
		}

		$table = $this->table_name();
		$where = $wpdb->prepare(
			"WHERE rollup_date >= %s AND rollup_date <= %s AND client_identifier IS NOT NULL AND client_identifier <> ''",
			$start_date,
			$end_date
		);
		if ( $ability !== null && $ability !== '' ) {
			$where .= $wpdb->prepare( ' AND ability = %s', $ability );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY rollup_date ASC, ability ASC, client_identifier ASC", $this->array_output() );
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$rollups = [];
		foreach ( $rows as $row ) {
			$rollup = $this->hydrate_rollup( $row );
			if ( $rollup instanceof DailyRollup && $rollup->client_identifier() !== null ) {
				$rollups[] = $rollup;
			}
		}

		return $rollups;
	}

	/**
	 * Hydrate one rollup returned by the database, ignoring malformed rows.
	 *
	 * Rows that pre-date the client and sample_rate columns, and aggregate rows
	 * carrying the empty-string sentinel, are both hydrated as aggregate
	 * (client_identifier = null) with sample_rate = 1.0 when absent, so their
	 * recorded counts are interpreted as exact rather than sampled.
	 *
	 * @param mixed $row  Database row in associative form.
	 */
	private function hydrate_rollup( $row ): ?DailyRollup {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$counts    = [ 'call_count', 'error_count', 'exception_count', 'validation_error_count', 'rate_limited_count' ];
		$durations = [ 'avg_duration_ms', 'p50_duration_ms', 'p95_duration_ms', 'p99_duration_ms', 'max_duration_ms' ];

		$data = [
			'date'              => (string) ( $row['rollup_date'] ?? '' ),
			'ability'           => (string) ( $row['ability'] ?? '' ),
			'client_identifier' => isset( $row['client_identifier'] ) && is_string( $row['client_identifier'] ) && $row['client_identifier'] !== ''
				? $row['client_identifier']
				: null,
			'sample_rate'       => isset( $row['sample_rate'] ) ? (float) $row['sample_rate'] : 1.0,
		];

		foreach ( $counts as $column ) {
			$data[ $column ] = isset( $row[ $column ] ) ? (int) $row[ $column ] : 0;
		}

		foreach ( $durations as $column ) {
			$data[ $column ] = isset( $row[ $column ] ) ? (float) $row[ $column ] : 0.0;
		}

		/** @phpstan-var array{date: string, ability: string, client_identifier: string|null, sample_rate: float, call_count: int, error_count: int, exception_count: int, validation_error_count: int, rate_limited_count: int, avg_duration_ms: float, p50_duration_ms: float, p95_duration_ms: float, p99_duration_ms: float, max_duration_ms: float} $data */
		return new DailyRollup( $data );
	}

	/**
	 * Calculate a nearest-rank percentile from sorted values.
	 *
	 * @param list<float> $values     Sorted numeric values.
	 * @param int         $percentile Percentile to calculate (0-100).
	 * @return float
	 */
	private function percentile( array $values, int $percentile ): float {
		if ( $values === [] ) {
			return 0.0;
		}

		$rank = (int) ceil( ( $percentile / 100.0 ) * count( $values ) );
		$rank = max( 1, min( $rank, count( $values ) ) );

		return $values[ $rank - 1 ];
	}

	/**
	 * Create the rollup table if it does not exist, and migrate to the current
	 * schema version when needed.
	 */
	private function ensure_table(): void {
		if ( $this->table_initialized ) {
			return;
		}

		$this->table_initialized = true;

		$wpdb = $this->wpdb();
		if ( $wpdb === null ) {
			return;
		}

		$table           = $this->table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// Create the table with the current schema. For new installations this is
		// sufficient. Existing tables are migrated separately below.
		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			rollup_date date NOT NULL,
			ability varchar(191) NOT NULL,
			client_identifier varchar(191) NOT NULL DEFAULT '',
			sample_rate double NOT NULL DEFAULT 1,
			call_count int unsigned NOT NULL DEFAULT 0,
			error_count int unsigned NOT NULL DEFAULT 0,
			exception_count int unsigned NOT NULL DEFAULT 0,
			validation_error_count int unsigned NOT NULL DEFAULT 0,
			rate_limited_count int unsigned NOT NULL DEFAULT 0,
			avg_duration_ms double NOT NULL DEFAULT 0,
			p50_duration_ms double NOT NULL DEFAULT 0,
			p95_duration_ms double NOT NULL DEFAULT 0,
			p99_duration_ms double NOT NULL DEFAULT 0,
			max_duration_ms double NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY rollup_date_ability_client (rollup_date, ability, client_identifier),
			KEY ability (ability),
			KEY rollup_date (rollup_date)
		) {$charset_collate}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $sql );

		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		$current_version = get_option( 'saltus_mcp_audit_rollups_db_version' );

		if ( $current_version === self::DB_VERSION ) {
			return;
		}

		// Schema upgrade for a table that already exists. A fresh install needs
		// none of it: the CREATE TABLE above is already at the current schema.
		//
		// The IF NOT EXISTS / IF EXISTS guards on the column and index statements
		// are MariaDB syntax that MySQL rejects. The statements carrying the 1.2.0
		// correctness fix are plain SQL for that reason, so they apply on both
		// engines; making the column additions portable is tracked separately.
		if ( $current_version !== false && $current_version !== '' ) {
			// 1.0.0 (pre-client) → the client and sample-rate columns.
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				"ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS client_identifier varchar(191) NOT NULL DEFAULT '' AFTER ability"
			);
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				"ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS sample_rate double NOT NULL DEFAULT 1 AFTER client_identifier"
			);

			// 1.1.0 → 1.2.0. Aggregate rows were written with a NULL
			// client_identifier, which the unique key cannot constrain, so every
			// repeated or overlapping rollup of one date added another aggregate
			// row and every total read back counted that date more than once.
			//
			// Normalize onto the sentinel so those rows collide, collapse the
			// duplicates already stored, then let the column refuse NULL outright.
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				"UPDATE {$table} SET client_identifier = '' WHERE client_identifier IS NULL"
			);
			// Keep the newest row of each triple. A self-join rather than a
			// subquery: MySQL cannot read the table a DELETE targets in a subquery.
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				"DELETE stale FROM {$table} stale INNER JOIN {$table} newer ON newer.rollup_date = stale.rollup_date AND newer.ability = stale.ability AND newer.client_identifier = stale.client_identifier AND newer.id > stale.id"
			);
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				"ALTER TABLE {$table} MODIFY client_identifier varchar(191) NOT NULL DEFAULT ''"
			);

			// Replace the old (rollup_date, ability) unique key with the
			// three-column key. Silenced if the old key is already gone.
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				"ALTER TABLE {$table} DROP INDEX IF EXISTS rollup_date_ability"
			);
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				"ALTER TABLE {$table} ADD UNIQUE KEY IF NOT EXISTS rollup_date_ability_client (rollup_date, ability, client_identifier)"
			);
		}

		update_option( 'saltus_mcp_audit_rollups_db_version', self::DB_VERSION );
	}

	/**
	 * Get the rollup table name.
	 */
	private function table_name(): string {
		$wpdb = $this->wpdb();
		if ( $wpdb === null ) {
			return '';
		}

		return $wpdb->prefix() . self::TABLE_SUFFIX;
	}

	/**
	 * Get the audit table name.
	 */
	private function audit_table_name(): string {
		$wpdb = $this->wpdb();
		if ( $wpdb === null ) {
			return '';
		}

		return $wpdb->prefix() . 'saltus_mcp_audit';
	}

	/**
	 * The associative row format, tolerating a non-WordPress context.
	 *
	 * @return mixed
	 */
	private function array_output() {
		return defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A';
	}

	/**
	 * Normalize the sampling rate to [0.0, 1.0]. Non-numeric and non-finite
	 * values fall back to 1.0 (keep everything) rather than silently suppressing
	 * entries or dividing by zero in downstream consumers.
	 *
	 * @param mixed $rate Raw filter value.
	 * @return float
	 */
	public static function normalize_sample_rate( $rate ): float {
		if ( ! is_numeric( $rate ) ) {
			return 1.0;
		}

		$rate = (float) $rate;

		if ( ! is_finite( $rate ) ) {
			return 1.0;
		}

		return max( 0.0, min( 1.0, $rate ) );
	}

	/**
	 * Get the database adapter, preferring an injected one.
	 *
	 * Resolved per call rather than cached in the constructor, so a caller that
	 * swaps the global after construction is still honored.
	 *
	 * @return AuditDatabase|null
	 */
	private function wpdb(): ?AuditDatabase {
		if ( $this->database instanceof AuditDatabase ) {
			return $this->database;
		}

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
	 * Check whether rollup computation is enabled.
	 */
	private function enabled(): bool {
		return (bool) $this->filter( 'saltus/framework/mcp/audit/rollup_enabled', true );
	}
}
