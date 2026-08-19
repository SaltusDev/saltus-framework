<?php

namespace Saltus\WP\Framework\Tests\MCP\Audit;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Audit\AuditLogger;

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
	 */
	public function test_rollups_run_even_when_retention_is_disabled(): void {
		global $wp_filter_values;

		$wp_filter_values['saltus/framework/mcp/audit/retention_days'] = 0;

		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		$this->db->addAuditRow( 'list_models', 'success', 10.0, $yesterday . ' 09:00:00.000' );

		( new AuditLogger() )->cleanup_expired_entries();

		$this->assertNotSame( [], $this->db->rollup_rows, 'rollups must not depend on retention being on' );
		$this->assertNull( $this->firstIndexMatching( 'DELETE FROM' ), 'retention_days = 0 must delete nothing' );
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
}
