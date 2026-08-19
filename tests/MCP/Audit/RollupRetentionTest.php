<?php

namespace Saltus\WP\Framework\Tests\MCP\Audit;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Audit\AuditLogger;
use Saltus\WP\Framework\MCP\Audit\RollupStore;

/**
 * The ordering contract between rollup computation and retention pruning.
 *
 * @covers \Saltus\WP\Framework\MCP\Audit\AuditLogger
 * @covers \Saltus\WP\Framework\MCP\Audit\RollupStore
 */
class RollupRetentionTest extends TestCase {

	/** @var mixed The shared global this class borrows, put back on teardown. */
	private $original_wpdb;

	private RollupTestDatabase $db;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb, $wp_transients, $wp_filter_values, $wp_options;

		$this->original_wpdb = $wpdb;

		$this->db         = new RollupTestDatabase();
		$wpdb             = $this->db;
		$wp_transients    = [];
		$wp_filter_values = [];
		$wp_options       = [];
	}

	protected function tearDown(): void {
		global $wpdb, $wp_transients, $wp_filter_values, $wp_options;

		$wpdb             = $this->original_wpdb;
		$wp_transients    = [];
		$wp_filter_values = [];
		$wp_options       = [];

		parent::tearDown();
	}

	/**
	 * Index of the first query matching a prefix, or null.
	 */
	private function firstIndexMatching( string $needle ): ?int {
		foreach ( $this->db->queries as $index => $query ) {
			if ( strpos( $query, $needle ) === 0 ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * A date N whole days before today, in UTC — the basis rollups are keyed by.
	 */
	private function daysAgo( int $days ): string {
		return gmdate( 'Y-m-d', strtotime( 'today 00:00:00 UTC' ) - ( $days * 86400 ) );
	}

	/**
	 * Seed the completion marker, standing in for an earlier successful pass.
	 *
	 * The `at` timestamp is a fixed sentinel so a failure that rewrites the
	 * marker with the same `through` is still visible.
	 */
	private function seedMarker( string $through ): void {
		global $wp_options;

		$wp_options[ RollupStore::FRESHNESS_OPTION ] = [
			'at'      => '2000-01-01T00:00:00Z',
			'through' => $through,
		];
	}

	/**
	 * The stored completion marker, or null when none was written.
	 *
	 * @return array<string, mixed>|null
	 */
	private function marker(): ?array {
		global $wp_options;

		$stored = $wp_options[ RollupStore::FRESHNESS_OPTION ] ?? null;

		return is_array( $stored ) ? $stored : null;
	}

	/**
	 * The dates that have rollup rows, deduplicated.
	 *
	 * @return list<string>
	 */
	private function rolledUpDates(): array {
		$dates = array_map(
			static fn( array $row ): string => (string) ( $row['rollup_date'] ?? '' ),
			$this->db->rollup_rows
		);

		return array_values( array_unique( $dates ) );
	}

	/**
	 * The whole point of the feature. Retention deletes the raw rows; if the
	 * rollup ran after the delete it would summarize an empty table and the
	 * history would be silently lost — with the aggregates reading zero rather
	 * than reporting that the source was gone.
	 */
	public function test_rollups_are_computed_before_the_retention_delete(): void {
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		$this->db->addAuditRow( 'list_models', 'success', 10.0, $yesterday . ' 09:00:00.000' );

		( new AuditLogger() )->cleanup_expired_entries();

		$this->assertNotSame( [], $this->db->rollup_writes, 'the retention pass must write a rollup' );

		$delete_index = $this->firstIndexMatching( 'DELETE FROM' );

		$this->assertNotNull( $delete_index, 'the retention pass must issue a delete' );

		// The rollup read has to precede the delete. Both are recorded in one
		// ordered list, so compare the rollup's source SELECT against the DELETE.
		$select_index = $this->firstIndexMatching( 'SELECT DISTINCT ability' );

		$this->assertNotNull( $select_index, 'the rollup must read the audit table' );
		$this->assertLessThan(
			$delete_index,
			$select_index,
			'the rollup must read the audit rows before retention deletes them'
		);
	}

	/**
	 * The ordering has to hold for the whole catch-up, not just its first date.
	 * A backfill that started before the delete but finished after it would lose
	 * exactly the oldest days in the gap — the ones closest to being pruned.
	 */
	public function test_the_whole_backfill_precedes_the_retention_delete(): void {
		$this->seedMarker( $this->daysAgo( 5 ) );

		foreach ( [ 4, 3, 2, 1 ] as $days ) {
			$this->db->addAuditRow( 'list_models', 'success', 10.0, $this->daysAgo( $days ) . ' 09:00:00.000' );
		}

		( new AuditLogger() )->cleanup_expired_entries();

		$delete_index = $this->firstIndexMatching( 'DELETE FROM wp_saltus_mcp_audit WHERE' );

		$this->assertNotNull( $delete_index, 'the retention pass must issue a delete' );

		$reads = [];
		foreach ( $this->db->queries as $index => $query ) {
			if ( strpos( $query, 'SELECT DISTINCT ability' ) === 0 ) {
				$reads[] = $index;
			}
		}

		$this->assertCount( 5, $reads, 'every date in the gap must read the audit table' );
		$this->assertLessThan(
			$delete_index,
			max( $reads ),
			'the last date of the catch-up must still read before the delete'
		);
	}

	public function test_the_retention_pass_rolls_up_yesterday(): void {
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		$this->db->addAuditRow( 'list_models', 'success', 10.0, $yesterday . ' 09:00:00.000' );

		( new AuditLogger() )->cleanup_expired_entries();

		$dates = array_map(
			static fn( array $row ): string => (string) ( $row['rollup_date'] ?? '' ),
			$this->db->rollup_rows
		);

		$this->assertContains( $yesterday, $dates );
	}

	/**
	 * The day before yesterday is recomputed too. An entry that arrived after
	 * yesterday's pass ran would otherwise never make it into a rollup, and the
	 * raw row backing it is on its way to being deleted.
	 */
	public function test_the_retention_pass_also_rolls_up_the_day_before(): void {
		$day_before = gmdate( 'Y-m-d', strtotime( '-2 days' ) );
		$this->db->addAuditRow( 'get_content', 'success', 10.0, $day_before . ' 09:00:00.000' );

		( new AuditLogger() )->cleanup_expired_entries();

		$dates = array_map(
			static fn( array $row ): string => (string) ( $row['rollup_date'] ?? '' ),
			$this->db->rollup_rows
		);

		$this->assertContains( $day_before, $dates );
	}

	/**
	 * Retention disabled still has to roll up. `retention_days => 0` turns off
	 * deletion, and an early return before the rollup would mean a site that
	 * keeps everything gets no aggregates at all.
	 *
	 * It turns off deletion of the audit rows only. Slow calls carry their own
	 * retention setting and are pruned on it regardless, so the assertion names
	 * the audit table rather than counting deletes.
	 */
	public function test_rollups_run_even_when_retention_is_disabled(): void {
		global $wp_filter_values;

		$wp_filter_values['saltus/framework/mcp/audit/retention_days'] = 0;

		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		$this->db->addAuditRow( 'list_models', 'success', 10.0, $yesterday . ' 09:00:00.000' );

		( new AuditLogger() )->cleanup_expired_entries();

		$this->assertNotSame( [], $this->db->rollup_rows, 'rollups must not depend on retention being on' );
		$this->assertNull(
			$this->firstIndexMatching( 'DELETE FROM wp_saltus_mcp_audit WHERE' ),
			'retention_days = 0 must delete no audit rows'
		);
	}

	public function test_the_rollup_disable_filter_leaves_retention_working(): void {
		global $wp_filter_values;

		$wp_filter_values['saltus/framework/mcp/audit/rollup_enabled'] = false;

		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		$this->db->addAuditRow( 'list_models', 'success', 10.0, $yesterday . ' 09:00:00.000' );

		( new AuditLogger() )->cleanup_expired_entries();

		$this->assertSame( [], $this->db->rollup_rows, 'the filter must stop rollup writes' );
		$this->assertNotNull( $this->firstIndexMatching( 'DELETE FROM' ), 'retention must still prune' );
	}

	/**
	 * The rollup has to go through the same seam the audit writes do.
	 *
	 * The store originally required the global to be a `\wpdb`, so wherever it was
	 * an `AuditDatabase` instead the rollup silently no-opped while the delete went
	 * ahead — the raw rows were pruned and nothing summarized them. Reverting the
	 * store to that resolver fails this test and most of the rollup suite.
	 *
	 * Note this pins the seam, not the constructor injection in
	 * `compute_recent_rollups()`: the logger and the store both resolve the same
	 * global, so passing the adapter through cannot currently diverge from letting
	 * the store find it. That argument is defensive — it keeps a future logger that
	 * takes an injected adapter from silently splitting the two.
	 */
	public function test_the_rollup_writes_through_the_audit_database_seam(): void {
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		$this->db->addAuditRow( 'list_models', 'success', 10.0, $yesterday . ' 09:00:00.000' );

		( new AuditLogger() )->cleanup_expired_entries();

		$this->assertNotSame(
			[],
			$this->db->rollup_rows,
			'the rollup must write through the adapter the logger is using'
		);
	}

	/**
	 * A pass whose every rollup write failed must not touch the marker.
	 *
	 * The marker is read two ways: health reporting calls the rollups fresh
	 * through it, and the next pass takes it as the cursor. Advancing it after a
	 * failed write therefore both hides the failure and steps the catch-up past
	 * days whose source rows the prune in the same call is deleting.
	 */
	public function test_a_total_rollup_failure_leaves_the_marker_untouched(): void {
		$marker = $this->daysAgo( 2 );
		$this->seedMarker( $marker );

		$this->db->addAuditRow( 'list_models', 'success', 10.0, $this->daysAgo( 2 ) . ' 09:00:00.000' );
		$this->db->addAuditRow( 'get_content', 'success', 20.0, $this->daysAgo( 1 ) . ' 09:00:00.000' );
		$this->db->failing_rollup_dates = [ $this->daysAgo( 2 ), $this->daysAgo( 1 ) ];

		( new AuditLogger() )->cleanup_expired_entries();

		$this->assertSame( [], $this->db->rollup_rows, 'no rollup row can have been stored' );
		$this->assertSame(
			[
				'at'      => '2000-01-01T00:00:00Z',
				'through' => $marker,
			],
			$this->marker(),
			'a failed pass must leave the marker exactly as it was'
		);
	}

	/**
	 * One date succeeding does not license the marker. Recording the run would
	 * name a date whose rollup is missing, and nothing would ever recompute it.
	 */
	public function test_a_partial_rollup_failure_leaves_the_marker_untouched(): void {
		$marker = $this->daysAgo( 3 );
		$this->seedMarker( $marker );

		$this->db->addAuditRow( 'list_models', 'success', 10.0, $this->daysAgo( 2 ) . ' 09:00:00.000' );
		$this->db->addAuditRow( 'get_content', 'success', 20.0, $this->daysAgo( 1 ) . ' 09:00:00.000' );
		$this->db->failing_rollup_dates = [ $this->daysAgo( 1 ) ];

		( new AuditLogger() )->cleanup_expired_entries();

		$this->assertSame(
			[ $this->daysAgo( 2 ) ],
			$this->rolledUpDates(),
			'the date that succeeded must still be stored'
		);
		$this->assertSame(
			[
				'at'      => '2000-01-01T00:00:00Z',
				'through' => $marker,
			],
			$this->marker(),
			'one failed date must hold the marker back for the whole run'
		);
	}

	/**
	 * A gap in the schedule — a disabled cron, a site offline for a week — is
	 * caught up from the marker. Without this the skipped days are lost for good:
	 * the prune in this same call deletes the audit rows behind them.
	 */
	public function test_a_multi_day_gap_is_backfilled_from_the_marker(): void {
		$this->seedMarker( $this->daysAgo( 6 ) );

		foreach ( [ 5, 4, 3, 2, 1 ] as $days ) {
			$this->db->addAuditRow( 'list_models', 'success', 10.0, $this->daysAgo( $days ) . ' 09:00:00.000' );
		}

		( new AuditLogger() )->cleanup_expired_entries();

		$this->assertSame(
			[
				$this->daysAgo( 5 ),
				$this->daysAgo( 4 ),
				$this->daysAgo( 3 ),
				$this->daysAgo( 2 ),
				$this->daysAgo( 1 ),
			],
			$this->rolledUpDates(),
			'every day past the marker must be rolled up'
		);
		$this->assertSame( $this->daysAgo( 1 ), $this->marker()['through'] ?? null );
	}

	/**
	 * The catch-up is bounded, so a long gap cannot turn one cron pass into a
	 * scan of every missed day at once. The marker carries the progress and the
	 * next pass resumes from it.
	 */
	public function test_a_long_gap_is_caught_up_across_successive_runs(): void {
		global $wp_filter_values;

		$wp_filter_values['saltus/framework/mcp/audit/rollup_catch_up_days'] = 2;

		$this->seedMarker( $this->daysAgo( 6 ) );

		foreach ( [ 5, 4, 3, 2, 1 ] as $days ) {
			$this->db->addAuditRow( 'list_models', 'success', 10.0, $this->daysAgo( $days ) . ' 09:00:00.000' );
		}

		( new AuditLogger() )->cleanup_expired_entries();

		$this->assertSame(
			$this->daysAgo( 4 ),
			$this->marker()['through'] ?? null,
			'one pass must stop at its bound rather than run the whole gap'
		);
		$this->assertSame(
			[ $this->daysAgo( 5 ), $this->daysAgo( 4 ) ],
			$this->rolledUpDates(),
			'only the bounded slice can have been rolled up'
		);

		// Successive passes, as cron would run them.
		( new AuditLogger() )->cleanup_expired_entries();
		$this->assertSame( $this->daysAgo( 2 ), $this->marker()['through'] ?? null );

		( new AuditLogger() )->cleanup_expired_entries();

		$this->assertSame(
			$this->daysAgo( 1 ),
			$this->marker()['through'] ?? null,
			'the catch-up must reach yesterday and stop there'
		);
		$this->assertSame(
			[
				$this->daysAgo( 5 ),
				$this->daysAgo( 4 ),
				$this->daysAgo( 3 ),
				$this->daysAgo( 2 ),
				$this->daysAgo( 1 ),
			],
			$this->rolledUpDates(),
			'no day in the gap may be skipped by the bound'
		);
	}

	/**
	 * A day with no traffic produces no rollup rows and is still covered. This is
	 * why the marker is explicit rather than inferred from the newest row: a pass
	 * that only recorded itself when it wrote something would report a quiet site
	 * as permanently stale and never advance its cursor.
	 */
	public function test_a_day_with_no_traffic_still_advances_the_marker(): void {
		( new AuditLogger() )->cleanup_expired_entries();

		$this->assertSame( [], $this->db->rollup_rows, 'no traffic means no rollup rows' );
		$this->assertSame( $this->daysAgo( 1 ), $this->marker()['through'] ?? null );

		$freshness = ( new RollupStore( $this->db ) )->get_freshness();

		$this->assertSame( 'fresh', $freshness['status'] );
		$this->assertFalse( $freshness['stale'] );
	}
}
