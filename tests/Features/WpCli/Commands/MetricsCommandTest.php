<?php

namespace Saltus\WP\Framework\Tests\Features\WpCli\Commands;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\WpCli\Commands\MetricsCommand;
use Saltus\WP\Framework\Features\WpCli\WpCli;
use Saltus\WP\Framework\MCP\Audit\RollupStore;
use Saltus\WP\Framework\Tests\Features\TestCliGateway;
use Saltus\WP\Framework\Tests\MCP\Audit\RollupTestDatabase;

require_once dirname( __DIR__, 3 ) . '/Rest/functions.php';
require_once dirname( __DIR__, 2 ) . '/WpCliFeatureTest.php';

/**
 * @covers \Saltus\WP\Framework\Features\WpCli\Commands\MetricsCommand
 */
class MetricsCommandTest extends TestCase {

	private TestCliGateway $cli;
	private RollupTestDatabase $db;

	protected function setUp(): void {
		parent::setUp();

		global $wp_filter_values, $wp_options;

		$wp_filter_values = [];
		$wp_options       = [];

		$this->cli = new TestCliGateway();
		$this->db  = new RollupTestDatabase();
	}

	protected function tearDown(): void {
		global $wp_filter_values, $wp_options;

		$wp_filter_values = [];
		$wp_options       = [];

		parent::tearDown();
	}

	private function command(): MetricsCommand {
		return new MetricsCommand( $this->cli, null, new RollupStore( $this->db ), $this->db );
	}

	/**
	 * Seed a rollup row directly.
	 *
	 * @param array<string, mixed> $overrides
	 */
	private function seedRollup( array $overrides = [] ): void {
		$this->db->rollup_rows[] = array_merge(
			[
				'id'                     => count( $this->db->rollup_rows ) + 1,
				'rollup_date'            => gmdate( 'Y-m-d' ),
				'ability'                => 'list_models',
				'call_count'             => 10,
				'error_count'            => 1,
				'exception_count'        => 1,
				'validation_error_count' => 0,
				'rate_limited_count'     => 0,
				'avg_duration_ms'        => 20.0,
				'p50_duration_ms'        => 15.0,
				'p95_duration_ms'        => 50.0,
				'p99_duration_ms'        => 60.0,
				'max_duration_ms'        => 70.0,
			],
			$overrides
		);
	}

	/**
	 * The command class existing is not the same as `wp saltus metrics` existing.
	 * This shipped unregistered once: the class, its subcommands, and its docs were
	 * all in place while the command was unreachable. A unit test on the class
	 * would have stayed green throughout, so the wiring needs its own assertion.
	 */
	public function test_the_metrics_command_is_registered(): void {
		$gateway = new TestCliGateway();

		( new WpCli( [], $gateway ) )->register_commands();

		$this->assertArrayHasKey( 'saltus metrics', $gateway->commands );
		$this->assertInstanceOf( MetricsCommand::class, $gateway->commands['saltus metrics'] );
	}

	/**
	 * WP_CLI's CommandFactory turns a class with __invoke() into a Subcommand,
	 * and a Subcommand's other public methods are unreachable. `summary`,
	 * `per_tool`, and `health` are only callable if the class has no __invoke.
	 */
	public function test_subcommands_are_reachable(): void {
		$reflection = new \ReflectionClass( MetricsCommand::class );

		$this->assertFalse( $reflection->hasMethod( '__invoke' ) );
		$this->assertTrue( $reflection->hasMethod( 'summary' ) );
		$this->assertTrue( $reflection->hasMethod( 'per_tool' ) );
		$this->assertTrue( $reflection->hasMethod( 'per_client' ) );
		$this->assertTrue( $reflection->hasMethod( 'health' ) );
	}

	public function test_summary_reports_totals(): void {
		$this->seedRollup(
			[
				'call_count'      => 10,
				'error_count'     => 1,
				'exception_count' => 1,
				'avg_duration_ms' => 20.0,
			]
		);

		$this->command()->summary( [], [] );

		$rows = [];
		foreach ( $this->cli->formats[0]['items'] as $row ) {
			$rows[ $row['metric'] ] = $row['value'];
		}

		$this->assertSame( '10', $rows['Total Calls'] );
		$this->assertSame( '2', $rows['Total Errors'] );
		$this->assertSame( '20.00%', $rows['Error Rate'] );
		$this->assertSame( '20.0 ms', $rows['Avg Latency'] );
	}

	public function test_summary_warns_when_no_metrics_exist(): void {
		$this->command()->summary( [], [] );

		$this->assertSame( [], $this->cli->formats );
		$this->assertStringContainsString( 'No metrics found', $this->cli->output() );
	}

	public function test_summary_honors_the_requested_format(): void {
		$this->seedRollup();

		$this->command()->summary( [], [ 'format' => 'json' ] );

		$this->assertSame( 'json', $this->cli->last_format() );
	}

	public function test_summary_filters_by_ability(): void {
		$today = gmdate( 'Y-m-d' );

		$this->seedRollup(
			[
				'rollup_date'     => $today,
				'ability'         => 'list_models',
				'call_count'      => 4,
				'error_count'     => 0,
				'exception_count' => 0,
			]
		);
		$this->seedRollup(
			[
				'rollup_date'     => $today,
				'ability'         => 'get_content',
				'call_count'      => 6,
				'error_count'     => 0,
				'exception_count' => 0,
			]
		);

		$this->command()->summary( [], [ 'ability' => 'get_content' ] );

		$rows = [];
		foreach ( $this->cli->formats[0]['items'] as $row ) {
			$rows[ $row['metric'] ] = $row['value'];
		}

		$this->assertSame( '6', $rows['Total Calls'] );
	}

	public function test_summary_honors_the_since_bound(): void {
		$this->seedRollup(
			[
				'rollup_date' => gmdate( 'Y-m-d', strtotime( '-40 days' ) ),
				'call_count'  => 9,
			]
		);

		$this->command()->summary( [], [] );

		$this->assertStringContainsString( 'No metrics found', $this->cli->output(), 'the default window is 7 days' );

		$this->cli = new TestCliGateway();
		$this->command()->summary( [], [ 'since' => gmdate( 'Y-m-d', strtotime( '-60 days' ) ) ] );

		$this->assertNotSame( [], $this->cli->formats, 'an explicit --since must widen the window' );
	}

	public function test_per_tool_lists_one_row_per_ability(): void {
		$today = gmdate( 'Y-m-d' );

		$this->seedRollup(
			[
				'rollup_date' => $today,
				'ability'     => 'list_models',
				'call_count'  => 3,
			]
		);
		$this->seedRollup(
			[
				'rollup_date' => $today,
				'ability'     => 'get_content',
				'call_count'  => 8,
			]
		);

		$this->command()->per_tool( [], [] );

		$this->assertCount( 2, $this->cli->formats[0]['items'] );
	}

	/**
	 * Busiest first. An operator scanning this table is looking for the tool
	 * carrying the traffic, so insertion order would bury it.
	 */
	public function test_per_tool_sorts_by_call_count_descending(): void {
		$today = gmdate( 'Y-m-d' );

		$this->seedRollup(
			[
				'rollup_date' => $today,
				'ability'     => 'quiet',
				'call_count'  => 2,
			]
		);
		$this->seedRollup(
			[
				'rollup_date' => $today,
				'ability'     => 'busy',
				'call_count'  => 99,
			]
		);
		$this->seedRollup(
			[
				'rollup_date' => $today,
				'ability'     => 'middling',
				'call_count'  => 40,
			]
		);

		$this->command()->per_tool( [], [] );

		$order = array_column( $this->cli->formats[0]['items'], 'ability' );

		$this->assertSame( [ 'busy', 'middling', 'quiet' ], $order );
	}

	public function test_per_tool_aggregates_a_single_ability_across_days(): void {
		$this->seedRollup(
			[
				'rollup_date'     => gmdate( 'Y-m-d' ),
				'call_count'      => 5,
				'error_count'     => 1,
				'exception_count' => 0,
				'p95_duration_ms' => 30.0,
			]
		);
		$this->seedRollup(
			[
				'rollup_date'     => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
				'call_count'      => 5,
				'error_count'     => 2,
				'exception_count' => 0,
				'p95_duration_ms' => 80.0,
			]
		);

		$this->command()->per_tool( [], [] );

		$row = $this->cli->formats[0]['items'][0];

		$this->assertCount( 1, $this->cli->formats[0]['items'] );
		$this->assertSame( 10, $row['calls'] );
		$this->assertSame( 3, $row['errors'] );
		$this->assertSame( '80.0', $row['p95_latency'], 'p95 is the worst day, not an average' );
	}

	public function test_per_tool_warns_when_no_metrics_exist(): void {
		$this->command()->per_tool( [], [] );

		$this->assertSame( [], $this->cli->formats );
		$this->assertStringContainsString( 'No metrics found', $this->cli->output() );
	}

	/**
	 * A missing audit table must be reported as missing, never as healthy or as
	 * zero errors. This is the invariant Phase 14 exists to hold, and the failure
	 * mode both v1.8.3 and v1.8.4 maintenance passes were instances of.
	 */
	public function test_per_client_uses_only_client_rollups_and_groups_them(): void {
		$this->seedRollup( [ 'call_count' => 10, 'client_identifier' => null ] );
		$this->seedRollup( [ 'call_count' => 4, 'client_identifier' => 'webmcp:user:1', 'avg_duration_ms' => 10.0, 'p95_duration_ms' => 30.0, 'error_count' => 0, 'exception_count' => 0 ] );
		$this->seedRollup( [ 'call_count' => 6, 'client_identifier' => 'webmcp:user:1', 'avg_duration_ms' => 20.0, 'p95_duration_ms' => 80.0, 'error_count' => 2 ] );

		$this->command()->per_client( [], [] );

		$this->assertCount( 1, $this->cli->formats[0]['items'] );
		$row = $this->cli->formats[0]['items'][0];
		$this->assertSame( 'webmcp:user:1', $row['client'] );
		$this->assertSame( 10, $row['calls'] );
		$this->assertSame( 3, $row['errors'] );
		$this->assertSame( '16.0', $row['avg_latency'] );
		$this->assertSame( '80.0', $row['p95_latency'] );
	}

	/**
	 * A missing audit table must be reported as missing, never as healthy or as
	 * zero errors. This is the invariant Phase 14 exists to hold, and the failure
	 * mode both v1.8.3 and v1.8.4 maintenance passes were instances of.
	 */
	public function test_health_reports_a_missing_table_as_missing(): void {
		$this->db->audit_table_exists = false;

		try {
			$this->command()->health();
			$this->fail( 'a missing audit table must halt the command' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'missing', $error->getMessage() );
		}
	}

	public function test_health_reports_an_empty_table_as_empty(): void {
		$this->command()->health();

		$this->assertStringContainsString( 'has no entries', $this->cli->output() );
	}

	public function test_health_reports_a_recent_entry_as_available(): void {
		$this->db->addAuditRow( 'list_models', 'success', 10.0, gmdate( 'Y-m-d H:i:s.000' ) );

		$this->command()->health();

		$this->assertStringContainsString( 'Audit table status: available', $this->cli->output() );
		$this->assertStringContainsString( 'healthy', $this->cli->output() );
	}

	/**
	 * An audit table whose newest row is hours old means logging has stopped —
	 * distinct from both healthy and missing, because the table and the data are
	 * both fine and the writer is not.
	 */
	public function test_health_reports_a_stale_table_as_stale(): void {
		$this->db->addAuditRow( 'list_models', 'success', 10.0, gmdate( 'Y-m-d H:i:s.000', time() - 7200 ) );

		$this->command()->health();

		$this->assertStringContainsString( 'Audit table status: stale', $this->cli->output() );
		$this->assertStringContainsString( 'detected issues', $this->cli->output() );
	}

	public function test_health_errors_without_a_database(): void {
		global $wpdb;

		$original = $wpdb;
		$wpdb     = null;

		try {
			$command = new MetricsCommand( $this->cli, null, new RollupStore( $this->db ) );
			$command->health();
			$this->fail( 'an absent database must halt the command' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'Database not available', $error->getMessage() );
		} finally {
			$wpdb = $original;
		}
	}
}
