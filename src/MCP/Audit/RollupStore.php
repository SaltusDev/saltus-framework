<?php
namespace Saltus\WP\Framework\MCP\Audit;

/**
 * Stores pre-aggregated daily metrics to avoid full audit table scans.
 * @api
 */
class RollupStore {
	use \Saltus\WP\Framework\Infrastructure\Services\FilterAwareTrait;

	private const TABLE_SUFFIX    = 'saltus_mcp_audit_rollups';
	public const FRESHNESS_OPTION = 'saltus_mcp_rollup_last_completed_at';

	/**
	 * Schema version the table converges on, and the option recording it.
	 *
	 * 1.2.1 rather than 1.2.0 because the 1.2.0 migration guarded its column and
	 * index statements with `IF NOT EXISTS` / `IF EXISTS`, which MariaDB accepts
	 * and MySQL rejects outright. A MySQL site therefore recorded 1.2.0 while the
	 * index half of the repair never applied, and a version-equality fast path
	 * would never look at that table again. Bumping the version brings every
	 * table back through the introspection below exactly once.
	 */
	private const DB_VERSION        = '1.2.1';
	private const DB_VERSION_OPTION = 'saltus_mcp_audit_rollups_db_version';

	/** Unique key holding one rollup row per day, ability, and client. */
	private const ROLLUP_UNIQUE_KEY = 'rollup_date_ability_client';

	/** The two-column unique key 1.2.0 replaced, dropped when still present. */
	private const LEGACY_UNIQUE_KEY = 'rollup_date_ability';

	/**
	 * Advisory lock keeping one runner on the repair, and how long it may hold it.
	 *
	 * The repair runs from `ensure_table()`, which every metrics read reaches,
	 * and this class has no upgrade-routine hook to move it to. The transient is
	 * an advisory lock, not a mutex: two workers can both read it as free in the
	 * window before either writes. That is tolerable because every statement the
	 * repair issues is decided from the table's own state and is idempotent, so
	 * the cost of a lost race is a repeated pass rather than a wrong table.
	 *
	 * The TTL only covers a runner that dies mid-pass. A pass that finishes
	 * releases the lock itself, so the next request continues any remainder
	 * instead of waiting the TTL out.
	 */
	private const MIGRATION_LOCK     = 'saltus_mcp_rollup_migration_lock';
	private const MIGRATION_LOCK_TTL = 300;

	/**
	 * Duplicate rows one pass deletes before handing the rest to a later pass.
	 *
	 * The collapse runs inside whatever request first reaches the repair. A table
	 * that accumulated a duplicate aggregate row per rollup per day carries an
	 * unbounded number of them, and deleting the lot in one statement holds row
	 * locks for as long as that takes. Bounding the pass keeps any single request
	 * finite; the version is not recorded until a pass finds nothing left.
	 */
	private const DUPLICATE_BATCH = 500;

	/**
	 * Days past the completion marker one retention pass will catch up.
	 *
	 * A bound, not a window: a site offline for a month has a month of unrolled
	 * days, and rolling all of them up in one cron pass means a scan per day plus
	 * a statement per ability inside a single request. The marker advances by
	 * what each pass covers, so successive passes close the gap.
	 */
	private const CATCH_UP_DAYS = 14;

	/**
	 * Stored value standing in for "every client" on an aggregate row.
	 *
	 * Deliberately not NULL. The unique key that keeps one row per day and
	 * ability cannot constrain NULLs — a unique index permits unlimited ones —
	 * so aggregate rows, which every mode writes, would have no uniqueness at
	 * all and repeated rollups would stack duplicates.
	 *
	 * The empty string is also the one value a client identifier could take that
	 * would collide with this slot, so `compute_and_store_rollup()` never emits
	 * it as a client: see the pair list there.
	 */
	private const AGGREGATE_CLIENT = '';

	/**
	 * Rows one rollup read returns before the caller has to page for more.
	 *
	 * A ceiling rather than a default a caller may raise. Both reads run on
	 * every metrics request over windows of up to a year, and the client-scoped
	 * one grows by distinct client as well as by date and ability, so an
	 * unbounded read loads a set sized by traffic into memory per request.
	 *
	 * Generous enough that an ordinary aggregate year — a row per date and
	 * ability — fits in one page, so paging is the exception rather than
	 * something every caller has to implement.
	 */
	private const MAX_READ_ROWS = 10000;

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
	 * Reads from the audit table and stores one aggregate row per ability. With
	 * client mode on it stores the per-client rows in addition, not instead:
	 * aggregate and client-scoped rollups are two coexisting series, and totals
	 * are read from the aggregate one. Must be called before retention cleanup
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

		// Build a deduplicated list of (ability, client_identifier) pairs. The NUL
		// separator cannot occur in an ability name or an identifier, so no pair
		// can be spelled two ways.
		$pairs = [];
		foreach ( $ability_rows as $ability_row ) {
			if ( ! is_array( $ability_row ) || ! isset( $ability_row['ability'] ) ) {
				continue;
			}

			$ability = (string) $ability_row['ability'];
			if ( $ability === '' ) {
				continue;
			}

			// Every mode writes the aggregate row. Client mode used to replace it
			// with the per-client rows, which left the series totals are read from
			// empty for as long as the filter was on, so every dashboard and CLI
			// total became mode-dependent.
			$pairs[ $ability . "\x00" ] = [
				'ability' => $ability,
				'client'  => null,
			];

			if ( ! $client_mode ) {
				continue;
			}

			$client = isset( $ability_row['identifier'] ) ? (string) $ability_row['identifier'] : '';

			// An empty identifier names no client, and AGGREGATE_CLIENT *is* the
			// empty string: a per-client row keyed on it would collide with the
			// aggregate on the unique key, and the upsert would replace the day's
			// total with that one caller's subtotal — then hydrate back as the
			// aggregate, so the corrupted total would read as exact. Those calls
			// are already counted in the aggregate, which scopes to no identifier.
			if ( $client === '' ) {
				continue;
			}

			$pairs[ $ability . "\x00" . $client ] = [
				'ability' => $ability,
				'client'  => $client,
			];
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
	 * Called by the retention pass once every date it covered has been written.
	 * An explicit marker is used instead of inferring freshness from the newest
	 * rollup row, because a no-traffic day produces no rows but is still valid.
	 *
	 * The same marker is the catch-up cursor `pending_rollup_dates()` reads, so
	 * recording a date the pass did not actually persist does more than misreport
	 * freshness: it steps the cursor past that date for good.
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
	 * Dates the retention pass still has to roll up, oldest first.
	 *
	 * The completion marker is the cursor. Every date past it is unrolled while
	 * the audit rows behind it are on their way out with retention, so a gap in
	 * the schedule — a disabled cron, a site offline for a week — loses those
	 * days permanently unless the pass catches them up.
	 *
	 * The marker's own date leads the list. Recomputing it absorbs entries that
	 * arrived after the pass that covered it, which is the reason the window was
	 * two days wide before there was a cursor.
	 *
	 * @return list<string> Dates in Y-m-d format, ascending; empty when rollups
	 *                      are disabled.
	 */
	public function pending_rollup_dates(): array {
		if ( ! $this->enabled() ) {
			return [];
		}

		$target = $this->expected_through();
		$marker = $this->completed_through();

		// Without a usable marker the pass covers yesterday and the day before,
		// the window it had before a cursor existed. Reaching further back on a
		// first run would scan days the site has no audit rows for.
		//
		// A marker that is not a date it can step forward from is treated as
		// absent rather than carried: stepping it would return it unchanged, so
		// the pass would re-record the same value and the cursor would never move
		// again — worse than the stale reading a bad marker gives on its own.
		$oldest = $marker !== null && $this->is_date( $marker )
			? $marker
			: $this->shift_date( $target, -1 );

		// A marker ahead of the target — clock skew, a restored database — must
		// not send the pass at days that have not finished yet.
		if ( $oldest > $target ) {
			$oldest = $target;
		}

		$dates = [ $oldest ];

		// Bound the catch-up so one cron pass cannot stall on a long gap. The
		// marker advances by what this run covers and the next run resumes there.
		$budget = max( 1, (int) $this->filter( 'saltus/framework/mcp/audit/rollup_catch_up_days', self::CATCH_UP_DAYS ) );

		$date = $oldest;
		for ( $day = 0; $day < $budget; $day++ ) {
			$date = $this->shift_date( $date, 1 );
			if ( $date > $target ) {
				break;
			}

			$dates[] = $date;
		}

		return $dates;
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
		$expected_through  = $this->expected_through();
		$stored            = $this->stored_completion();
		$last_completed_at = isset( $stored['at'] ) ? (string) $stored['at'] : null;
		$last_through      = $this->completed_through();

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
	 * The last date a completed rollup cycle is expected to cover.
	 *
	 * Yesterday in UTC, taken off the current UTC date rather than
	 * `strtotime( 'yesterday' )`, which resolves to local midnight and therefore
	 * names the day before that wherever PHP's timezone is ahead of UTC. The
	 * cursor and the staleness test read this same value, so a divergence would
	 * have the pass report itself stale the moment it succeeded.
	 */
	private function expected_through(): string {
		return $this->shift_date( gmdate( 'Y-m-d' ), -1 );
	}

	/**
	 * Shift a Y-m-d date by whole days, in UTC.
	 *
	 * Arithmetic on the UTC timestamp rather than a relative `strtotime` string:
	 * the relative form resolves against the local timezone, which lands a day
	 * off the UTC dates rollups are keyed by. UTC has no DST, so a day here is
	 * exactly 86400 seconds.
	 *
	 * @param string $date Date in Y-m-d format.
	 * @param int    $days Days to add; negative subtracts.
	 */
	private function shift_date( string $date, int $days ): string {
		$midnight = strtotime( $date . 'T00:00:00Z' );
		if ( $midnight === false ) {
			return $date;
		}

		return gmdate( 'Y-m-d', $midnight + ( $days * 86400 ) );
	}

	/**
	 * Whether a stored value is a Y-m-d date this class can step forward from.
	 *
	 * The format is checked as well as the parse, because `strtotime()` accepts
	 * plenty of strings that are not dates on a rollup's terms.
	 */
	private function is_date( string $value ): bool {
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) !== 1 ) {
			return false;
		}

		return strtotime( $value . 'T00:00:00Z' ) !== false;
	}

	/**
	 * The date the last recorded cycle covered, or null when none is recorded.
	 */
	private function completed_through(): ?string {
		$stored = $this->stored_completion();

		return isset( $stored['through'] ) ? (string) $stored['through'] : null;
	}

	/**
	 * The stored completion marker, or an empty array when none is readable.
	 *
	 * @return array<string, mixed>
	 */
	private function stored_completion(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return [];
		}

		$stored = get_option( self::FRESHNESS_OPTION );

		return is_array( $stored ) ? $stored : [];
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
	 * instead of NULL: see AGGREGATE_CLIENT. `compute_and_store_rollup()` is what
	 * keeps a real client off that sentinel, so the fallback below can only ever
	 * be reached by a genuine aggregate rollup.
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
	 * @param int|null    $limit             Rows to return, capped at MAX_READ_ROWS. Null takes the cap.
	 * @param int         $offset            Rows to skip, to page past the cap.
	 * @return list<DailyRollup>
	 */
	public function get_rollups(
		string $start_date,
		string $end_date,
		?string $ability = null,
		?string $client_identifier = null,
		?int $limit = null,
		int $offset = 0
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
		//
		// The sentinel is excluded from the client filter rather than matched: it
		// is the aggregate slot, not an identifier, so asking for it asks for the
		// aggregate series — which is the branch below, NULL tolerance included.
		if ( $client_identifier !== null && $client_identifier !== self::AGGREGATE_CLIENT ) {
			$where .= $wpdb->prepare( ' AND client_identifier = %s', $client_identifier );
		} else {
			// Aggregate rows carry the sentinel. NULL is matched too, so a table
			// whose normalization migration has not run yet — or was rejected —
			// keeps answering with the aggregate rows it already has instead of
			// going silently blank.
			$where .= " AND ( client_identifier = '' OR client_identifier IS NULL )";
		}

		$page = $this->page_clause( $wpdb, $limit, $offset );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY rollup_date ASC, ability ASC, id ASC {$page}", $this->array_output() );

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
	 * @param int|null    $limit      Rows to return, capped at MAX_READ_ROWS. Null takes the cap.
	 * @param int         $offset     Rows to skip, to page past the cap.
	 * @return list<DailyRollup>
	 */
	public function get_client_rollups(
		string $start_date,
		string $end_date,
		?string $ability = null,
		?int $limit = null,
		int $offset = 0
	): array {
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

		$page = $this->page_clause( $wpdb, $limit, $offset );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY rollup_date ASC, ability ASC, client_identifier ASC, id ASC {$page}", $this->array_output() );
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
	 * Build the prepared `LIMIT … OFFSET …` tail shared by both rollup reads.
	 *
	 * MAX_READ_ROWS is a ceiling a caller cannot raise, not a default it can
	 * override: an unbounded read is the failure being prevented, so a larger
	 * $limit clamps down to the cap and the caller pages with $offset instead.
	 * Paging is only sound because both reads order by the primary key last,
	 * which makes the sort total and so keeps a page from repeating or skipping
	 * a row that ties on every other column.
	 *
	 * @param AuditDatabase $wpdb   Adapter used to prepare the clause.
	 * @param int|null      $limit  Requested page size, or null for the cap.
	 * @param int           $offset Requested offset.
	 */
	private function page_clause( AuditDatabase $wpdb, ?int $limit, int $offset ): string {
		$size = $limit === null ? self::MAX_READ_ROWS : max( 1, min( $limit, self::MAX_READ_ROWS ) );

		return $wpdb->prepare( 'LIMIT %d OFFSET %d', $size, max( 0, $offset ) );
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
	 * Create the rollup table if it does not exist, and bring an existing one up
	 * to the current schema.
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

		// Carries the current schema, so a fresh install is finished by this one
		// statement and the migration below finds nothing to do.
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

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL over the internal rollup table name.
		$wpdb->query( $sql );

		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		$this->migrate_table( $wpdb, $table );
	}

	/**
	 * Converge an existing table on the current schema, then record the version.
	 *
	 * What the table needs is read out of `information_schema` rather than
	 * inferred from the recorded version. The version option can disagree with
	 * the table — a partial restore, a deleted option, a table created outside
	 * the options API, or the 1.2.0 migration recording success on MySQL while
	 * half its statements were rejected — and every one of those cases used to
	 * stamp the current version onto a table that was never repaired, after
	 * which nothing looked again.
	 *
	 * So the version is written only once a read of the table confirms the final
	 * schema, which also makes a pass that ran out of its duplicate budget
	 * simply leave the remainder to the next one.
	 *
	 * @param AuditDatabase $wpdb  Database adapter.
	 * @param string        $table Rollup table name.
	 */
	private function migrate_table( AuditDatabase $wpdb, string $table ): void {
		if ( ! $this->claim_migration_lock() ) {
			return;
		}

		try {
			$columns = $this->table_columns( $wpdb, $table );

			// No column at all means the CREATE did not leave a table behind —
			// rejected DDL, or an adapter that cannot answer introspection. There
			// is nothing to migrate and nothing worth recording.
			if ( $columns === [] ) {
				return;
			}

			$indexes = $this->table_indexes( $wpdb, $table );

			if ( $this->schema_is_current( $columns, $indexes )
				|| $this->repair_schema( $wpdb, $table, $columns, $indexes ) ) {
				$this->record_schema_version( $wpdb, $table );
			}
		} finally {
			delete_transient( self::MIGRATION_LOCK );
		}
	}

	/**
	 * Issue the statements the observed table state calls for.
	 *
	 * Ordered by dependency: the column has to exist before its values can be
	 * normalized, duplicates have to be gone before the unique key can be added,
	 * and the NULLs have to be gone before the column can refuse them.
	 *
	 * @param AuditDatabase       $wpdb    Database adapter.
	 * @param string              $table   Rollup table name.
	 * @param array<string, bool> $columns Column name => whether it accepts NULL.
	 * @param list<string>        $indexes Index names present on the table.
	 * @return bool True when this pass left nothing for a later one.
	 */
	private function repair_schema( AuditDatabase $wpdb, string $table, array $columns, array $indexes ): bool {
		// 1.0.0 predates client attribution entirely.
		if ( ! isset( $columns['client_identifier'] ) ) {
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- DDL over the internal rollup table name.
				"ALTER TABLE {$table} ADD COLUMN client_identifier varchar(191) NOT NULL DEFAULT '' AFTER ability"
			);
		}

		if ( ! isset( $columns['sample_rate'] ) ) {
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- DDL over the internal rollup table name.
				"ALTER TABLE {$table} ADD COLUMN sample_rate double NOT NULL DEFAULT 1 AFTER client_identifier"
			);
		}

		$nullable  = $columns['client_identifier'] ?? false;
		$needs_key = ! in_array( self::ROLLUP_UNIQUE_KEY, $indexes, true );

		// 1.1.0 wrote aggregate rows with a NULL client_identifier, which a unique
		// index cannot constrain — it permits unlimited NULLs — so every repeated
		// rollup of a date stacked another aggregate row and every total read back
		// counted that date more than once.
		//
		// The collapse also guards the key itself: adding a unique index over rows
		// that already violate it fails outright, so it runs whenever the key is
		// missing, not only when NULLs are in play.
		if ( $nullable || $needs_key ) {
			if ( ! $this->collapse_duplicate_rows( $wpdb, $table ) ) {
				return false;
			}
		}

		if ( $nullable ) {
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Internal rollup table name; the sentinel is a literal.
				"UPDATE {$table} SET client_identifier = '' WHERE client_identifier IS NULL"
			);
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- DDL over the internal rollup table name.
				"ALTER TABLE {$table} MODIFY client_identifier varchar(191) NOT NULL DEFAULT ''"
			);
		}

		// The two-column key cannot coexist with client rows: it holds one row per
		// day and ability, so the second client of a day collides with the first.
		if ( in_array( self::LEGACY_UNIQUE_KEY, $indexes, true ) ) {
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- DDL over the internal rollup table name.
				"ALTER TABLE {$table} DROP INDEX rollup_date_ability"
			);
		}

		if ( $needs_key ) {
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- DDL over the internal rollup table name.
				"ALTER TABLE {$table} ADD UNIQUE KEY rollup_date_ability_client (rollup_date, ability, client_identifier)"
			);
		}

		return true;
	}

	/**
	 * Delete up to one batch of rows sharing a unique key, keeping the newest.
	 *
	 * Two statements rather than the self-join `DELETE`, because a multi-table
	 * delete accepts no `LIMIT` and this has to stay bounded: the row count is a
	 * function of how long the site ran on the broken schema, and it is being
	 * deleted inside somebody's request.
	 *
	 * The join treats a NULL identifier as the sentinel, so a legacy aggregate
	 * row and a normalized one count as the same key. `=` would not: NULL never
	 * equals anything, which is the property that let the duplicates accumulate.
	 *
	 * @param AuditDatabase $wpdb  Database adapter.
	 * @param string        $table Rollup table name.
	 * @return bool True when no duplicate rows are left.
	 */
	private function collapse_duplicate_rows( AuditDatabase $wpdb, string $table ): bool {
		$stale = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name; the bound is a placeholder.
				"SELECT DISTINCT stale.id FROM {$table} stale INNER JOIN {$table} newer"
					. ' ON newer.rollup_date = stale.rollup_date AND newer.ability = stale.ability'
					. " AND COALESCE( newer.client_identifier, '' ) = COALESCE( stale.client_identifier, '' )"
					. ' AND newer.id > stale.id ORDER BY stale.id ASC LIMIT %d',
				self::DUPLICATE_BATCH
			),
			$this->array_output()
		);

		if ( ! is_array( $stale ) || $stale === [] ) {
			return true;
		}

		$ids = [];
		foreach ( $stale as $row ) {
			if ( ! isset( $row['id'] ) ) {
				continue;
			}

			$ids[] = (int) $row['id'];
		}

		if ( $ids === [] ) {
			return true;
		}

		$id_list = implode( ',', $ids );

		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Internal table name; the list is integers cast from the read above.
			"DELETE FROM {$table} WHERE id IN ({$id_list})"
		);

		// A full batch means the read was truncated, so more may remain. The next
		// pass finds out; at worst it reads once and finds nothing.
		return count( $ids ) < self::DUPLICATE_BATCH;
	}

	/**
	 * Record the schema version, but only if the table now reads as current.
	 *
	 * A second read rather than trust in the statements having run: a rejected
	 * `ALTER` returns without raising, and recording the version over one is what
	 * made the previous migration unrepeatable on MySQL.
	 *
	 * @param AuditDatabase $wpdb  Database adapter.
	 * @param string        $table Rollup table name.
	 */
	private function record_schema_version( AuditDatabase $wpdb, string $table ): void {
		$columns = $this->table_columns( $wpdb, $table );

		if ( ! $this->schema_is_current( $columns, $this->table_indexes( $wpdb, $table ) ) ) {
			return;
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * The table's columns, mapped to whether each one accepts NULL.
	 *
	 * `information_schema` rather than `SHOW COLUMNS`, because it is queryable
	 * with placeholders and returns the same shape on MySQL and MariaDB. The
	 * aliases are lower-cased explicitly: the catalog's own column names are
	 * upper case, and the case a driver hands back is not worth depending on.
	 *
	 * @param AuditDatabase $wpdb  Database adapter.
	 * @param string        $table Rollup table name.
	 * @return array<string, bool>
	 */
	private function table_columns( AuditDatabase $wpdb, string $table ): array {
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT COLUMN_NAME AS column_name, IS_NULLABLE AS is_nullable FROM information_schema.COLUMNS'
					. ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$table
			),
			$this->array_output()
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$columns = [];
		foreach ( $rows as $row ) {
			if ( ! isset( $row['column_name'] ) ) {
				continue;
			}

			$columns[ (string) $row['column_name'] ] = strtoupper( (string) ( $row['is_nullable'] ?? '' ) ) === 'YES';
		}

		return $columns;
	}

	/**
	 * The names of the indexes present on the table.
	 *
	 * Names only. They are ours, so the presence of one is enough to say whether
	 * its columns are covered.
	 *
	 * @param AuditDatabase $wpdb  Database adapter.
	 * @param string        $table Rollup table name.
	 * @return list<string>
	 */
	private function table_indexes( AuditDatabase $wpdb, string $table ): array {
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DISTINCT INDEX_NAME AS index_name FROM information_schema.STATISTICS'
					. ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$table
			),
			$this->array_output()
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$indexes = [];
		foreach ( $rows as $row ) {
			if ( ! isset( $row['index_name'] ) ) {
				continue;
			}

			$indexes[] = (string) $row['index_name'];
		}

		return $indexes;
	}

	/**
	 * Whether the observed table already matches the current schema.
	 *
	 * @param array<string, bool> $columns Column name => whether it accepts NULL.
	 * @param list<string>        $indexes Index names present on the table.
	 */
	private function schema_is_current( array $columns, array $indexes ): bool {
		if ( ! isset( $columns['client_identifier'], $columns['sample_rate'] ) ) {
			return false;
		}

		// A nullable column is not cosmetic: it is what leaves aggregate rows out
		// of the unique key, so the schema is not current until it refuses NULL.
		if ( $columns['client_identifier'] ) {
			return false;
		}

		return in_array( self::ROLLUP_UNIQUE_KEY, $indexes, true )
			&& ! in_array( self::LEGACY_UNIQUE_KEY, $indexes, true );
	}

	/**
	 * Take the advisory lock, or report that another runner holds it.
	 */
	private function claim_migration_lock(): bool {
		if ( get_transient( self::MIGRATION_LOCK ) !== false ) {
			return false;
		}

		set_transient( self::MIGRATION_LOCK, self::DB_VERSION, self::MIGRATION_LOCK_TTL );

		return true;
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
	 * A rate between 0.0 and the draw's precision floor is raised to that floor
	 * rather than left as configured. The draw can only express multiples of
	 * 1/SAMPLE_PRECISION, so a smaller rate retains nothing at all while the rate
	 * persisted with each rollup still claims that proportion was kept, and the
	 * extrapolation downstream then scales up an empty sample. Raising it keeps
	 * the persisted rate equal to the rate actually applied. An exact 0.0 is left
	 * alone: it is the explicit "record only failures" setting, not a rate too
	 * small to express.
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

		$rate = max( 0.0, min( 1.0, $rate ) );

		$floor = 1.0 / (float) AuditLogger::SAMPLE_PRECISION;

		return ( $rate > 0.0 && $rate < $floor ) ? $floor : $rate;
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
