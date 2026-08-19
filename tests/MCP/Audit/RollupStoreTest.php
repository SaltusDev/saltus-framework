<?php

namespace Saltus\WP\Framework\Tests\MCP\Audit;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Audit\RollupStore;

/**
 * @covers \Saltus\WP\Framework\MCP\Audit\RollupStore
 */
class RollupStoreTest extends TestCase {

	private RollupTestDatabase $db;

	protected function setUp(): void {
		parent::setUp();

		global $wp_filter_values, $wp_options;

		$wp_filter_values = [];
		$wp_options       = [];

		$this->db = new RollupTestDatabase();
	}

	protected function tearDown(): void {
		global $wp_filter_values, $wp_options;

		$wp_filter_values = [];
		$wp_options       = [];

		parent::tearDown();
	}

	private function store(): RollupStore {
		return new RollupStore( $this->db );
	}

	/**
	 * The rollup upsert statements issued, in order.
	 *
	 * @return list<string>
	 */
	private function upsertStatements(): array {
		return array_values(
			array_filter(
				$this->db->queries,
				static fn( string $query ): bool => strpos( $query, 'INSERT INTO' ) === 0
					&& strpos( $query, 'ON DUPLICATE KEY UPDATE' ) !== false
			)
		);
	}

	/**
	 * A rollup row as an older schema stored it: aggregate rows carried a NULL
	 * client identifier, which the unique key could not constrain.
	 *
	 * @return array<string, mixed>
	 */
	private function legacyRow( int $id, string $date, string $ability, ?string $client, int $calls ): array {
		return [
			'id'                     => $id,
			'rollup_date'            => $date,
			'ability'                => $ability,
			'client_identifier'      => $client,
			'sample_rate'            => 1.0,
			'call_count'             => $calls,
			'error_count'            => 0,
			'exception_count'        => 0,
			'validation_error_count' => 0,
			'rate_limited_count'     => 0,
			'avg_duration_ms'        => 10.0,
			'p50_duration_ms'        => 10.0,
			'p95_duration_ms'        => 10.0,
			'p99_duration_ms'        => 10.0,
			'max_duration_ms'        => 10.0,
		];
	}

	/**
	 * The DDL has to run before the first read, following AuditLogger: a site
	 * that has only ever read — the dashboard, the CLI — would otherwise query a
	 * table that was never created.
	 */
	public function test_creates_the_rollup_table_before_reading(): void {
		$this->store()->get_rollups( '2026-08-01', '2026-08-14' );

		$creates = array_filter(
			$this->db->queries,
			static fn( string $query ): bool => strpos( $query, 'CREATE TABLE IF NOT EXISTS' ) === 0
		);

		$this->assertNotSame( [], $creates );
	}

	public function test_creates_the_table_only_once_per_instance(): void {
		$store = $this->store();
		$store->get_rollups( '2026-08-01', '2026-08-14' );
		$store->get_rollups( '2026-08-01', '2026-08-14' );

		$creates = array_filter(
			$this->db->queries,
			static fn( string $query ): bool => strpos( $query, 'CREATE TABLE IF NOT EXISTS' ) === 0
		);

		$this->assertCount( 1, $creates );
	}

	public function test_computes_one_rollup_per_ability(): void {
		$this->db->addAuditRow( 'list_models', 'success', 10.0, '2026-08-13 09:00:00.000' );
		$this->db->addAuditRow( 'get_content', 'success', 20.0, '2026-08-13 10:00:00.000' );

		$count = $this->store()->compute_and_store_rollup( '2026-08-13' );

		$this->assertSame( 2, $count );
	}

	public function test_counts_each_status_into_its_own_column(): void {
		$this->db->addAuditRow( 'list_models', 'success', 5.0, '2026-08-13 09:00:00.000' );
		$this->db->addAuditRow( 'list_models', 'error', 5.0, '2026-08-13 09:00:01.000' );
		$this->db->addAuditRow( 'list_models', 'exception', 5.0, '2026-08-13 09:00:02.000' );
		$this->db->addAuditRow( 'list_models', 'validation_error', 5.0, '2026-08-13 09:00:03.000' );
		$this->db->addAuditRow( 'list_models', 'rate_limited', 5.0, '2026-08-13 09:00:04.000' );

		$store = $this->store();
		$store->compute_and_store_rollup( '2026-08-13' );

		$rollups = $store->get_rollups( '2026-08-13', '2026-08-13' );

		$this->assertCount( 1, $rollups );
		$this->assertSame( 5, $rollups[0]->call_count() );
		$this->assertSame( 1, $rollups[0]->error_count() );
		$this->assertSame( 1, $rollups[0]->exception_count() );
		$this->assertSame( 1, $rollups[0]->validation_error_count() );
		$this->assertSame( 1, $rollups[0]->rate_limited_count() );
	}

	/**
	 * A `field_denied` row is a call that happened, so it counts toward
	 * call_count, but Phase 11 deliberately keeps it out of the error rate. If
	 * it were counted as an error, tightening field permissions would look like
	 * a reliability regression.
	 */
	public function test_field_denied_counts_as_a_call_but_not_an_error(): void {
		$this->db->addAuditRow( 'update_content', 'field_denied', 5.0, '2026-08-13 09:00:00.000' );

		$store = $this->store();
		$store->compute_and_store_rollup( '2026-08-13' );

		$rollups = $store->get_rollups( '2026-08-13', '2026-08-13' );

		$this->assertSame( 1, $rollups[0]->call_count() );
		$this->assertSame( 0, $rollups[0]->error_count() );
		$this->assertSame( 0, $rollups[0]->exception_count() );
		$this->assertSame( 0.0, $rollups[0]->error_rate() );
	}

	public function test_computes_average_and_max_duration(): void {
		foreach ( [ 10.0, 20.0, 60.0 ] as $index => $duration ) {
			$this->db->addAuditRow( 'list_models', 'success', $duration, '2026-08-13 09:00:0' . $index . '.000' );
		}

		$store = $this->store();
		$store->compute_and_store_rollup( '2026-08-13' );

		$rollups = $store->get_rollups( '2026-08-13', '2026-08-13' );

		$this->assertSame( 30.0, $rollups[0]->avg_duration_ms() );
		$this->assertSame( 60.0, $rollups[0]->max_duration_ms() );
	}

	/**
	 * Nearest-rank percentile over 1..100: rank = ceil(p/100 * n), so p50 is the
	 * 50th value, p95 the 95th, p99 the 99th.
	 */
	public function test_computes_nearest_rank_percentiles(): void {
		for ( $i = 1; $i <= 100; $i++ ) {
			$this->db->addAuditRow(
				'list_models',
				'success',
				(float) $i,
				sprintf( '2026-08-13 09:%02d:%02d.000', intdiv( $i, 60 ), $i % 60 )
			);
		}

		$store = $this->store();
		$store->compute_and_store_rollup( '2026-08-13' );

		$rollups = $store->get_rollups( '2026-08-13', '2026-08-13' );

		$this->assertSame( 50.0, $rollups[0]->p50_duration_ms() );
		$this->assertSame( 95.0, $rollups[0]->p95_duration_ms() );
		$this->assertSame( 99.0, $rollups[0]->p99_duration_ms() );
		$this->assertSame( 100.0, $rollups[0]->max_duration_ms() );
	}

	public function test_percentiles_of_a_single_call_are_that_call(): void {
		$this->db->addAuditRow( 'list_models', 'success', 42.0, '2026-08-13 09:00:00.000' );

		$store = $this->store();
		$store->compute_and_store_rollup( '2026-08-13' );

		$rollups = $store->get_rollups( '2026-08-13', '2026-08-13' );

		$this->assertSame( 42.0, $rollups[0]->p50_duration_ms() );
		$this->assertSame( 42.0, $rollups[0]->p95_duration_ms() );
		$this->assertSame( 42.0, $rollups[0]->p99_duration_ms() );
	}

	/**
	 * duration_ms is nullable — a `started` row has no duration yet. Those rows
	 * are still calls, but must not enter the duration set as zeros, which would
	 * drag every percentile down.
	 */
	public function test_null_durations_count_as_calls_but_not_as_zero_latency(): void {
		$this->db->addAuditRow( 'list_models', 'success', 100.0, '2026-08-13 09:00:00.000' );
		$this->db->addAuditRow( 'list_models', 'started', null, '2026-08-13 09:00:01.000' );

		$store = $this->store();
		$store->compute_and_store_rollup( '2026-08-13' );

		$rollups = $store->get_rollups( '2026-08-13', '2026-08-13' );

		$this->assertSame( 2, $rollups[0]->call_count() );
		$this->assertSame( 100.0, $rollups[0]->avg_duration_ms() );
		$this->assertSame( 100.0, $rollups[0]->p50_duration_ms() );
	}

	/**
	 * Percentiles must not depend on the server honoring ORDER BY. Nearest-rank
	 * indexes into the list positionally, so an unsorted list silently returns
	 * whatever happened to land at that index — a wrong p95 that looks plausible.
	 */
	public function test_percentiles_do_not_depend_on_the_query_ordering(): void {
		$this->db->ignore_order_by = true;

		foreach ( [ 90.0, 10.0, 50.0, 100.0, 20.0 ] as $index => $duration ) {
			$this->db->addAuditRow( 'list_models', 'success', $duration, '2026-08-13 09:00:0' . $index . '.000' );
		}

		$store = $this->store();
		$store->compute_and_store_rollup( '2026-08-13' );

		$rollups = $store->get_rollups( '2026-08-13', '2026-08-13' );

		// Sorted: 10, 20, 50, 90, 100. p50 = ceil(.5*5) = 3rd = 50.
		$this->assertSame( 50.0, $rollups[0]->p50_duration_ms() );
		$this->assertSame( 100.0, $rollups[0]->p95_duration_ms() );
		$this->assertSame( 100.0, $rollups[0]->max_duration_ms() );
	}

	public function test_returns_zero_when_no_audit_rows_exist_for_the_date(): void {
		$this->db->addAuditRow( 'list_models', 'success', 10.0, '2026-08-13 09:00:00.000' );

		$this->assertSame( 0, $this->store()->compute_and_store_rollup( '2026-08-12' ) );
	}

	/**
	 * The date bounds are inclusive to the millisecond at both ends. A call at
	 * 00:00:00.000 or 23:59:59.999 belongs to that day and must not fall in the
	 * gap between two days' rollups.
	 */
	public function test_includes_rows_at_both_midnight_boundaries(): void {
		$this->db->addAuditRow( 'list_models', 'success', 10.0, '2026-08-13 00:00:00.000' );
		$this->db->addAuditRow( 'list_models', 'success', 10.0, '2026-08-13 23:59:59.999' );
		$this->db->addAuditRow( 'list_models', 'success', 10.0, '2026-08-14 00:00:00.000' );

		$store = $this->store();
		$store->compute_and_store_rollup( '2026-08-13' );

		$rollups = $store->get_rollups( '2026-08-13', '2026-08-13' );

		$this->assertSame( 2, $rollups[0]->call_count() );
	}

	/**
	 * Recompute must overwrite. Two rows for one (date, ability) would double
	 * every total the dashboard and CLI sum, and the retention pass deliberately
	 * recomputes the same day twice to catch late-arriving entries.
	 */
	public function test_recompute_overwrites_rather_than_duplicating(): void {
		$this->db->addAuditRow( 'list_models', 'success', 10.0, '2026-08-13 09:00:00.000' );

		$store = $this->store();
		$store->compute_and_store_rollup( '2026-08-13' );

		// A late-arriving entry for the same day.
		$this->db->addAuditRow( 'list_models', 'error', 30.0, '2026-08-13 23:00:00.000' );
		$store->compute_and_store_rollup( '2026-08-13' );

		$rollups = $store->get_rollups( '2026-08-13', '2026-08-13' );

		$this->assertCount( 1, $rollups, 'a recompute must replace the row, not add one' );
		$this->assertSame( 2, $rollups[0]->call_count() );
		$this->assertSame( 1, $rollups[0]->error_count() );
	}

	public function test_get_rollups_filters_by_date_range(): void {
		foreach ( [ '2026-08-11', '2026-08-12', '2026-08-13' ] as $date ) {
			$this->db->addAuditRow( 'list_models', 'success', 10.0, $date . ' 09:00:00.000' );
		}

		$store = $this->store();
		foreach ( [ '2026-08-11', '2026-08-12', '2026-08-13' ] as $date ) {
			$store->compute_and_store_rollup( $date );
		}

		$rollups = $store->get_rollups( '2026-08-12', '2026-08-13' );

		$this->assertCount( 2, $rollups );
		$this->assertSame( '2026-08-12', $rollups[0]->date() );
		$this->assertSame( '2026-08-13', $rollups[1]->date() );
	}

	public function test_get_rollups_filters_by_ability(): void {
		$this->db->addAuditRow( 'list_models', 'success', 10.0, '2026-08-13 09:00:00.000' );
		$this->db->addAuditRow( 'get_content', 'success', 20.0, '2026-08-13 09:00:01.000' );

		$store = $this->store();
		$store->compute_and_store_rollup( '2026-08-13' );

		$rollups = $store->get_rollups( '2026-08-13', '2026-08-13', 'get_content' );

		$this->assertCount( 1, $rollups );
		$this->assertSame( 'get_content', $rollups[0]->ability() );
	}

	public function test_get_rollups_treats_an_empty_ability_as_no_filter(): void {
		$this->db->addAuditRow( 'list_models', 'success', 10.0, '2026-08-13 09:00:00.000' );
		$this->db->addAuditRow( 'get_content', 'success', 20.0, '2026-08-13 09:00:01.000' );

		$store = $this->store();
		$store->compute_and_store_rollup( '2026-08-13' );

		$this->assertCount( 2, $store->get_rollups( '2026-08-13', '2026-08-13', '' ) );
	}

	public function test_get_rollups_orders_by_date_then_ability(): void {
		$this->db->addAuditRow( 'zeta', 'success', 10.0, '2026-08-12 09:00:00.000' );
		$this->db->addAuditRow( 'alpha', 'success', 10.0, '2026-08-12 09:00:01.000' );
		$this->db->addAuditRow( 'alpha', 'success', 10.0, '2026-08-13 09:00:00.000' );

		$store = $this->store();
		$store->compute_and_store_rollup( '2026-08-12' );
		$store->compute_and_store_rollup( '2026-08-13' );

		$rollups = $store->get_rollups( '2026-08-12', '2026-08-13' );

		$actual = array_map(
			static fn( $rollup ): string => $rollup->date() . '/' . $rollup->ability(),
			$rollups
		);

		$this->assertSame( [ '2026-08-12/alpha', '2026-08-12/zeta', '2026-08-13/alpha' ], $actual );
	}

	public function test_get_rollups_returns_empty_when_nothing_stored(): void {
		$this->assertSame( [], $this->store()->get_rollups( '2026-08-01', '2026-08-14' ) );
	}

	/**
	 * The disable filter has to stop the work, not just the storage. A site that
	 * turns rollups off should pay nothing for them.
	 */
	public function test_disable_filter_prevents_computation(): void {
		global $wp_filter_values;

		$wp_filter_values['saltus/framework/mcp/audit/rollup_enabled'] = false;

		$this->db->addAuditRow( 'list_models', 'success', 10.0, '2026-08-13 09:00:00.000' );

		$this->assertSame( 0, $this->store()->compute_and_store_rollup( '2026-08-13' ) );
		$this->assertSame( [], $this->db->rollup_writes );
	}

	public function test_disable_filter_does_not_run_the_ddl(): void {
		global $wp_filter_values;

		$wp_filter_values['saltus/framework/mcp/audit/rollup_enabled'] = false;

		$this->store()->compute_and_store_rollup( '2026-08-13' );

		$creates = array_filter(
			$this->db->queries,
			static fn( string $query ): bool => strpos( $query, 'CREATE TABLE IF NOT EXISTS' ) === 0
		);

		$this->assertSame( [], $creates );
	}

	public function test_writes_the_rollup_to_the_rollup_table(): void {
		$this->db->addAuditRow( 'list_models', 'success', 10.0, '2026-08-13 09:00:00.000' );

		$this->store()->compute_and_store_rollup( '2026-08-13' );

		$statements = $this->upsertStatements();

		$this->assertCount( 1, $statements );
		$this->assertStringContainsString( 'wp_saltus_mcp_audit_rollups', $statements[0] );
		$this->assertSame( '2026-08-13', $this->db->rollup_writes[0]['rollup_date'] );
		$this->assertSame( 'list_models', $this->db->rollup_writes[0]['ability'] );
	}

	public function test_records_the_schema_version_option(): void {
		global $wp_options;

		$this->store()->get_rollups( '2026-08-01', '2026-08-14' );

		$this->assertSame( '1.2.0', $wp_options['saltus_mcp_audit_rollups_db_version'] ?? null );
	}

	/**
	 * With no database at all, every entry point has to answer rather than fatal.
	 * The dashboard and the retention cron both reach this path on a site where
	 * the global is not set up yet.
	 */
	public function test_absent_database_is_survivable(): void {
		global $wpdb;

		$original = $wpdb;
		$wpdb     = null;

		try {
			$store = new RollupStore();

			$this->assertSame( 0, $store->compute_and_store_rollup( '2026-08-13' ) );
			$this->assertSame( [], $store->get_rollups( '2026-08-01', '2026-08-14' ) );
		} finally {
			$wpdb = $original;
		}
	}

	// -------------------------------------------------------------------------
	// Phase 14: client identifier, sample rate, freshness
	// -------------------------------------------------------------------------

	/**
	 * Aggregate rows are stored with the empty-string sentinel, not NULL, so the
	 * unique key constrains them. Callers keep seeing null: the sentinel is a
	 * storage detail that hydration undoes.
	 */
	public function test_aggregate_mode_stores_the_sentinel_and_reads_back_null(): void {
		$this->db->addAuditRow( 'list_models', 'success', 10.0, '2026-08-13 09:00:00.000' );

		$store = $this->store();
		$store->compute_and_store_rollup( '2026-08-13' );

		$this->assertSame( '', $this->db->rollup_writes[0]['client_identifier'] );
		$this->assertNull( $store->get_rollups( '2026-08-13', '2026-08-13' )[0]->client_identifier() );
	}

	public function test_aggregate_mode_stores_default_sample_rate(): void {
		$this->db->addAuditRow( 'list_models', 'success', 10.0, '2026-08-13 09:00:00.000' );

		$this->store()->compute_and_store_rollup( '2026-08-13' );

		$this->assertSame( 1.0, $this->db->rollup_writes[0]['sample_rate'] );
	}

	public function test_sample_rate_filter_is_stored_in_rollup(): void {
		global $wp_filter_values;
		$wp_filter_values['saltus/framework/mcp/audit/sample_rate'] = 0.25;

		$this->db->addAuditRow( 'list_models', 'success', 10.0, '2026-08-13 09:00:00.000' );

		$this->store()->compute_and_store_rollup( '2026-08-13' );

		$this->assertSame( 0.25, $this->db->rollup_writes[0]['sample_rate'] );
	}

	public function test_client_mode_stores_per_client_rollup(): void {
		global $wp_filter_values;
		$wp_filter_values['saltus/framework/mcp/audit/rollup_by_client'] = true;

		$this->db->addAuditRow( 'list_models', 'success', 10.0, '2026-08-13 09:00:00.000', 'webmcp:user:1' );
		$this->db->addAuditRow( 'list_models', 'success', 20.0, '2026-08-13 09:00:01.000', 'webmcp:user:2' );

		$count = $this->store()->compute_and_store_rollup( '2026-08-13' );

		$this->assertSame( 2, $count );
		$clients = array_column( $this->db->rollup_writes, 'client_identifier' );
		sort( $clients );
		$this->assertSame( [ 'webmcp:user:1', 'webmcp:user:2' ], $clients );
	}

	public function test_client_mode_scopes_counts_to_each_client(): void {
		global $wp_filter_values;
		$wp_filter_values['saltus/framework/mcp/audit/rollup_by_client'] = true;

		$this->db->addAuditRow( 'list_models', 'success', 10.0, '2026-08-13 09:00:00.000', 'webmcp:user:1' );
		$this->db->addAuditRow( 'list_models', 'error', 20.0, '2026-08-13 09:00:01.000', 'webmcp:user:1' );
		$this->db->addAuditRow( 'list_models', 'success', 30.0, '2026-08-13 09:00:02.000', 'webmcp:user:2' );

		$store = $this->store();
		$store->compute_and_store_rollup( '2026-08-13' );

		$rollups = $store->get_rollups( '2026-08-13', '2026-08-13', null, 'webmcp:user:1' );

		$this->assertCount( 1, $rollups );
		$this->assertSame( 2, $rollups[0]->call_count() );
		$this->assertSame( 1, $rollups[0]->error_count() );
	}

	public function test_get_rollups_returns_only_aggregate_rows_by_default(): void {
		global $wp_filter_values;
		$wp_filter_values['saltus/framework/mcp/audit/rollup_by_client'] = true;

		$this->db->addAuditRow( 'list_models', 'success', 10.0, '2026-08-13 09:00:00.000', 'webmcp:user:1' );

		$store = $this->store();
		$store->compute_and_store_rollup( '2026-08-13' );

		// Default call (no client filter) should return only aggregate rows (null client).
		// Since client mode was on, we stored per-client rows, so aggregate query returns empty.
		$aggregate = $store->get_rollups( '2026-08-13', '2026-08-13' );

		$this->assertSame( [], $aggregate, 'default get_rollups must not return client-scoped rows' );
	}

	public function test_get_rollups_returns_client_rows_when_specified(): void {
		global $wp_filter_values;
		$wp_filter_values['saltus/framework/mcp/audit/rollup_by_client'] = true;

		$this->db->addAuditRow( 'list_models', 'success', 10.0, '2026-08-13 09:00:00.000', 'webmcp:user:1' );

		$store = $this->store();
		$store->compute_and_store_rollup( '2026-08-13' );

		$client_rows = $store->get_rollups( '2026-08-13', '2026-08-13', null, 'webmcp:user:1' );

		$this->assertCount( 1, $client_rows );
		$this->assertSame( 'webmcp:user:1', $client_rows[0]->client_identifier() );
	}

	public function test_record_completion_stores_freshness_option(): void {
		global $wp_options;

		$this->store()->record_completion( '2026-08-14' );

		$stored = $wp_options[ RollupStore::FRESHNESS_OPTION ] ?? null;
		$this->assertIsArray( $stored );
		$this->assertSame( '2026-08-14', $stored['through'] );
		$this->assertNotEmpty( $stored['at'] );
	}

	public function test_get_freshness_returns_unknown_before_first_completion(): void {
		$freshness = $this->store()->get_freshness();

		$this->assertTrue( $freshness['enabled'] );
		$this->assertSame( 'unknown', $freshness['status'] );
		$this->assertFalse( $freshness['stale'] );
		$this->assertNull( $freshness['last_completed_through'] );
	}

	public function test_get_freshness_returns_fresh_when_completed_yesterday(): void {
		global $wp_options;

		$yesterday = gmdate( 'Y-m-d', strtotime( 'yesterday' ) );
		$wp_options[ RollupStore::FRESHNESS_OPTION ] = [ 'at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'through' => $yesterday ];

		$freshness = $this->store()->get_freshness();

		$this->assertSame( 'fresh', $freshness['status'] );
		$this->assertFalse( $freshness['stale'] );
		$this->assertSame( $yesterday, $freshness['last_completed_through'] );
	}

	public function test_get_freshness_returns_stale_when_completion_is_behind(): void {
		global $wp_options;

		$two_days_ago = gmdate( 'Y-m-d', strtotime( '-2 days' ) );
		$wp_options[ RollupStore::FRESHNESS_OPTION ] = [ 'at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'through' => $two_days_ago ];

		$freshness = $this->store()->get_freshness();

		$this->assertSame( 'stale', $freshness['status'] );
		$this->assertTrue( $freshness['stale'] );
	}

	public function test_get_freshness_returns_disabled_when_rollups_off(): void {
		global $wp_filter_values;
		$wp_filter_values['saltus/framework/mcp/audit/rollup_enabled'] = false;

		$freshness = $this->store()->get_freshness();

		$this->assertFalse( $freshness['enabled'] );
		$this->assertSame( 'disabled', $freshness['status'] );
		$this->assertFalse( $freshness['stale'] );
	}

	/**
	 * The write is one statement. A check-then-delete-then-insert sequence lets a
	 * second, overlapping rollup of the same date land between the steps and
	 * leave two rows for one (date, ability, client), and every total read back
	 * from the table then counts that date more than once.
	 */
	public function test_the_rollup_write_is_a_single_statement(): void {
		$this->db->addAuditRow( 'list_models', 'success', 10.0, '2026-08-13 09:00:00.000' );

		$this->store()->compute_and_store_rollup( '2026-08-13' );

		$this->assertCount( 1, $this->upsertStatements() );

		$prechecks = array_filter(
			$this->db->queries,
			static fn( string $query ): bool => strpos( $query, 'SELECT id FROM' ) === 0
				|| strpos( $query, 'DELETE FROM wp_saltus_mcp_audit_rollups' ) === 0
		);

		$this->assertSame( [], $prechecks, 'no read-then-delete may precede the write' );
	}

	/**
	 * The unique key is only worth having if the column it covers cannot be NULL:
	 * a unique index permits unlimited NULLs, so a nullable client_identifier
	 * leaves aggregate rows — the only rows the default mode writes — entirely
	 * unconstrained.
	 */
	public function test_the_table_constrains_one_row_per_day_ability_and_client(): void {
		$this->store()->get_rollups( '2026-08-01', '2026-08-14' );

		$creates = array_values(
			array_filter(
				$this->db->queries,
				static fn( string $query ): bool => strpos( $query, 'CREATE TABLE IF NOT EXISTS' ) === 0
			)
		);

		$this->assertNotSame( [], $creates );
		$this->assertStringContainsString( "client_identifier varchar(191) NOT NULL DEFAULT ''", $creates[0] );
		$this->assertStringContainsString(
			'UNIQUE KEY rollup_date_ability_client (rollup_date, ability, client_identifier)',
			$creates[0]
		);
	}

	/**
	 * The default mode writes aggregate rows, so this is the case the unique key
	 * has to cover — and the case a NULL client identifier left uncovered, since
	 * a unique index never treats two NULLs as equal.
	 */
	public function test_repeated_aggregate_rollups_of_one_date_keep_one_row(): void {
		$this->db->addAuditRow( 'list_models', 'success', 10.0, '2026-08-13 09:00:00.000' );

		$store = $this->store();
		$store->compute_and_store_rollup( '2026-08-13' );
		$store->compute_and_store_rollup( '2026-08-13' );

		$this->assertCount( 1, $this->db->rollup_rows, 'the second run must replace, not duplicate' );
		$this->assertCount( 2, $this->db->rollup_writes, 'both runs must have attempted a write' );
	}

	public function test_repeated_client_rollups_of_one_date_keep_one_row_each(): void {
		global $wp_filter_values;
		$wp_filter_values['saltus/framework/mcp/audit/rollup_by_client'] = true;

		$this->db->addAuditRow( 'list_models', 'success', 10.0, '2026-08-13 09:00:00.000', 'webmcp:user:1' );
		$this->db->addAuditRow( 'list_models', 'success', 20.0, '2026-08-13 09:00:01.000', 'webmcp:user:2' );

		$store = $this->store();
		$store->compute_and_store_rollup( '2026-08-13' );
		$store->compute_and_store_rollup( '2026-08-13' );

		$this->assertCount( 2, $this->db->rollup_rows, 'one row per client, not one per run' );
	}

	public function test_upgrade_normalizes_legacy_null_aggregate_rows(): void {
		global $wp_options;

		$wp_options['saltus_mcp_audit_rollups_db_version'] = '1.1.0';
		$this->db->rollup_rows                             = [
			$this->legacyRow( 1, '2026-08-13', 'list_models', null, 5 ),
		];

		$this->store()->get_rollups( '2026-08-13', '2026-08-13' );

		$this->assertSame( '', $this->db->rollup_rows[0]['client_identifier'] );
	}

	/**
	 * Duplicates the unconstrained NULL key already allowed have to be collapsed
	 * before the key can apply, and the newest row is the one worth keeping — it
	 * is the most recent recompute of that date.
	 */
	public function test_upgrade_collapses_duplicate_aggregate_rows(): void {
		global $wp_options;

		$wp_options['saltus_mcp_audit_rollups_db_version'] = '1.1.0';
		$this->db->rollup_rows                             = [
			$this->legacyRow( 1, '2026-08-13', 'list_models', null, 5 ),
			$this->legacyRow( 2, '2026-08-13', 'list_models', null, 9 ),
		];

		$rollups = $this->store()->get_rollups( '2026-08-13', '2026-08-13' );

		$this->assertCount( 1, $this->db->rollup_rows, 'the upgrade must collapse the duplicate' );
		$this->assertCount( 1, $rollups );
		$this->assertSame( 9, $rollups[0]->call_count(), 'the newest row is the one kept' );
	}

	/**
	 * A table whose normalization has not run — or was rejected — must still
	 * answer. The aggregate predicate matches NULL as well as the sentinel, so
	 * the dashboard keeps reading the rows it has instead of going blank.
	 */
	public function test_aggregate_read_tolerates_rows_the_upgrade_has_not_normalized(): void {
		$this->db->rollup_rows = [
			$this->legacyRow( 1, '2026-08-13', 'list_models', null, 5 ),
		];

		$rollups = $this->store()->get_rollups( '2026-08-13', '2026-08-13' );

		$this->assertCount( 1, $rollups );
		$this->assertSame( 5, $rollups[0]->call_count() );
	}
}

