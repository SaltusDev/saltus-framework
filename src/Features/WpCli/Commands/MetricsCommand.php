<?php
namespace Saltus\WP\Framework\Features\WpCli\Commands;

use Saltus\WP\Framework\Features\WpCli\CliGateway;
use Saltus\WP\Framework\Features\WpCli\WordPressCliGateway;
use Saltus\WP\Framework\MCP\Audit\AuditDatabase;
use Saltus\WP\Framework\MCP\Audit\RollupStore;
use Saltus\WP\Framework\MCP\Audit\WpdbAuditDatabase;

/**
 * WP-CLI commands for viewing MCP audit metrics.
 * @api
 */
class MetricsCommand {

	private CliGateway $cli;

	private RollupStore $rollup_store;

	/** @var AuditDatabase|null */
	private ?AuditDatabase $database;

	/**
	 * Output goes through CliGateway rather than static WP_CLI calls, matching
	 * every other command in this tree. The static form cannot be observed in a
	 * test, so the difference between "reported healthy" and "reported missing"
	 * would not be assertable.
	 *
	 * @param CliGateway|null $cli  Output gateway. Defaults to the WP-CLI one.
	 * @param callable|null $resolver  Unused; kept so the registration call shape
	 *                                 matches the rest of the command tree.
	 * @param RollupStore|null $rollup_store  Optional store, constructed when omitted.
	 * @param AuditDatabase|null $database  Optional adapter for health checks.
	 */
	public function __construct(
		?CliGateway $cli = null,
		?callable $resolver = null,
		?RollupStore $rollup_store = null,
		?AuditDatabase $database = null
	) {
		unset( $resolver );

		$this->cli          = $cli ?? new WordPressCliGateway();
		$this->rollup_store = $rollup_store ?? new RollupStore( $database );
		$this->database     = $database;
	}

	/**
	 * Display aggregate metrics summary.
	 *
	 * ## OPTIONS
	 *
	 * [--since=<date>]
	 * : Start date in Y-m-d format (default: 7 days ago)
	 *
	 * [--ability=<name>]
	 * : Filter by specific ability name
	 *
	 * [--format=<format>]
	 * : Output format (table, json, yaml, csv)
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp saltus metrics summary
	 *     wp saltus metrics summary --since=2026-08-01
	 *     wp saltus metrics summary --ability=list_models --format=json
	 *
	 * @param array<int, string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function summary( array $args, array $assoc_args ): void {
		$since   = isset( $assoc_args['since'] ) ? (string) $assoc_args['since'] : gmdate( 'Y-m-d', strtotime( '-7 days' ) );
		$ability = isset( $assoc_args['ability'] ) ? (string) $assoc_args['ability'] : null;
		$format  = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		$end_date = gmdate( 'Y-m-d' );
		$rollups  = $this->rollup_store->get_rollups( $since, $end_date, $ability );

		if ( $rollups === [] ) {
			$this->cli->warning( 'No metrics found for the specified range.' );
			return;
		}

		$total_calls    = 0;
		$total_errors   = 0;
		$total_duration = 0;

		foreach ( $rollups as $rollup ) {
			$total_calls    += $rollup->call_count();
			$total_errors   += $rollup->error_count() + $rollup->exception_count();
			$total_duration += $rollup->avg_duration_ms() * $rollup->call_count();
		}

		$avg_latency = $total_calls > 0 ? $total_duration / $total_calls : 0.0;
		$error_rate  = $total_calls > 0 ? $total_errors / $total_calls : 0.0;

		$summary = [
			[
				'metric' => 'Total Calls',
				'value'  => (string) $total_calls,
			],
			[
				'metric' => 'Total Errors',
				'value'  => (string) $total_errors,
			],
			[
				'metric' => 'Error Rate',
				'value'  => sprintf( '%.2f%%', $error_rate * 100 ),
			],
			[
				'metric' => 'Avg Latency',
				'value'  => sprintf( '%.1f ms', $avg_latency ),
			],
		];

		$this->cli->format_items( $format, $summary, [ 'metric', 'value' ] );
	}

	/**
	 * Display per-ability metrics breakdown.
	 *
	 * ## OPTIONS
	 *
	 * [--since=<date>]
	 * : Start date in Y-m-d format (default: 7 days ago)
	 *
	 * [--format=<format>]
	 * : Output format (table, json, yaml, csv)
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp saltus metrics per-tool
	 *     wp saltus metrics per-tool --since=2026-08-01 --format=csv
	 *
	 * @param array<int, string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function per_tool( array $args, array $assoc_args ): void {
		$since  = isset( $assoc_args['since'] ) ? (string) $assoc_args['since'] : gmdate( 'Y-m-d', strtotime( '-7 days' ) );
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		$end_date = gmdate( 'Y-m-d' );
		$rollups  = $this->rollup_store->get_rollups( $since, $end_date );

		if ( $rollups === [] ) {
			$this->cli->warning( 'No metrics found for the specified range.' );
			return;
		}

		$per_ability = [];

		foreach ( $rollups as $rollup ) {
			$ability = $rollup->ability();

			if ( ! isset( $per_ability[ $ability ] ) ) {
				$per_ability[ $ability ] = [
					'ability'        => $ability,
					'calls'          => 0,
					'errors'         => 0,
					'total_duration' => 0.0,
					'max_p95'        => 0.0,
				];
			}

			$per_ability[ $ability ]['calls']          += $rollup->call_count();
			$per_ability[ $ability ]['errors']         += $rollup->error_count() + $rollup->exception_count();
			$per_ability[ $ability ]['total_duration'] += $rollup->avg_duration_ms() * $rollup->call_count();
			$per_ability[ $ability ]['max_p95']         = max( $per_ability[ $ability ]['max_p95'], $rollup->p95_duration_ms() );
		}

		$output = [];
		foreach ( $per_ability as $data ) {
			$avg_latency = $data['calls'] > 0 ? $data['total_duration'] / $data['calls'] : 0.0;

			$output[] = [
				'ability'     => $data['ability'],
				'calls'       => $data['calls'],
				'errors'      => $data['errors'],
				'avg_latency' => sprintf( '%.1f', $avg_latency ),
				'p95_latency' => sprintf( '%.1f', $data['max_p95'] ),
			];
		}

		usort(
			$output,
			static function ( $a, $b ) {
				return $b['calls'] <=> $a['calls'];
			}
		);

		$this->cli->format_items(
			$format,
			$output,
			[ 'ability', 'calls', 'errors', 'avg_latency', 'p95_latency' ]
		);
	}

	/**
	 * Display per-client metrics breakdown.
	 *
	 * ## OPTIONS
	 *
	 * [--since=<date>]
	 * : Start date in Y-m-d format (default: 7 days ago)
	 *
	 * [--ability=<name>]
	 * : Filter by specific ability name
	 *
	 * [--format=<format>]
	 * : Output format (table, json, yaml, csv)
	 *
	 * @param array<int, string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function per_client( array $args, array $assoc_args ): void {
		$since   = isset( $assoc_args['since'] ) ? (string) $assoc_args['since'] : gmdate( 'Y-m-d', strtotime( '-7 days' ) );
		$ability = isset( $assoc_args['ability'] ) ? (string) $assoc_args['ability'] : null;
		$format  = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$rollups = $this->rollup_store->get_client_rollups( $since, gmdate( 'Y-m-d' ), $ability );

		if ( $rollups === [] ) {
			$this->cli->warning( 'No client metrics found for the specified range.' );
			return;
		}

		$grouped = [];
		foreach ( $rollups as $rollup ) {
			$client = $rollup->client_identifier();
			if ( $client === null ) {
				continue;
			}
			if ( ! isset( $grouped[ $client ] ) ) {
				$grouped[ $client ] = [ 'client' => $client, 'calls' => 0, 'errors' => 0, 'total_duration' => 0.0, 'max_p95' => 0.0 ];
			}
			$grouped[ $client ]['calls']          += $rollup->call_count();
			$grouped[ $client ]['errors']         += $rollup->error_count() + $rollup->exception_count();
			$grouped[ $client ]['total_duration'] += $rollup->avg_duration_ms() * $rollup->call_count();
			$grouped[ $client ]['max_p95']         = max( $grouped[ $client ]['max_p95'], $rollup->p95_duration_ms() );
		}

		$output = [];
		foreach ( $grouped as $data ) {
			$output[] = [
				'client'       => $data['client'],
				'calls'        => $data['calls'],
				'errors'       => $data['errors'],
				'avg_latency'  => sprintf( '%.1f', $data['calls'] > 0 ? $data['total_duration'] / $data['calls'] : 0.0 ),
				'p95_latency'  => sprintf( '%.1f', $data['max_p95'] ),
			];
		}
		usort( $output, static fn( array $a, array $b ): int => $b['calls'] <=> $a['calls'] );
		$this->cli->format_items( $format, $output, [ 'client', 'calls', 'errors', 'avg_latency', 'p95_latency' ] );
	}

	/**
	 * Check audit table health status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp saltus metrics health
	 */
	public function health(): void {
		$wpdb = $this->database();

		if ( $wpdb === null ) {
			$this->cli->error( 'Database not available.' );
			return;
		}

		$table  = $wpdb->prefix() . 'saltus_mcp_audit';
		$output = defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A';

		// Check table exists
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_results( "SHOW TABLES LIKE '{$table}'", $output );

		if ( ! is_array( $exists ) || $exists === [] ) {
			$this->cli->error( 'Audit table does not exist. Status: missing' );
			return;
		}

		// Check last entry timestamp
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT created_at FROM {$table} ORDER BY created_at DESC LIMIT 1", $output );

		$last_entry = null;
		if ( is_array( $rows ) && isset( $rows[0] ) && is_array( $rows[0] ) && isset( $rows[0]['created_at'] ) ) {
			$last_entry = (string) $rows[0]['created_at'];
		}

		if ( $last_entry === null || $last_entry === '' ) {
			$this->cli->warning( 'Audit table exists but has no entries. Status: empty' );
			return;
		}

		$last_time       = strtotime( $last_entry );
		$current_time    = time();
		$staleness_hours = $last_time !== false ? ( $current_time - $last_time ) / 3600 : 0;

		$freshness    = $this->rollup_store->get_freshness();
		$rollup_stale = $freshness['enabled'] && $freshness['stale'];

		$status = 'available';
		if ( $staleness_hours > 1 ) {
			$status = 'stale';
		}

		$this->cli->line( sprintf( 'Audit table status: %s', $status ) );
		$this->cli->line( sprintf( 'Last entry: %s (%.1f hours ago)', $last_entry, $staleness_hours ) );
		$this->cli->line( '' );
		$this->cli->line( sprintf( 'Rollup status: %s', $freshness['status'] ) );
		if ( $freshness['enabled'] ) {
			$this->cli->line( sprintf( 'Expected through: %s', $freshness['expected_through'] ) );
			if ( $freshness['last_completed_through'] !== null ) {
				$this->cli->line( sprintf( 'Last completed: %s at %s', $freshness['last_completed_through'], $freshness['last_completed_at'] ?? 'unknown' ) );
			}
		}

		if ( $staleness_hours > 1 || $rollup_stale ) {
			$this->cli->warning( 'Health check detected issues.' );
		} else {
			$this->cli->success( 'Audit and rollup systems are healthy.' );
		}
	}

	/**
	 * Resolve the database adapter, preferring an injected one.
	 *
	 * @return \Saltus\WP\Framework\MCP\Audit\AuditDatabase|null
	 */
	private function database(): ?\Saltus\WP\Framework\MCP\Audit\AuditDatabase {
		if ( $this->database instanceof \Saltus\WP\Framework\MCP\Audit\AuditDatabase ) {
			return $this->database;
		}

		global $wpdb;

		if ( $wpdb instanceof \Saltus\WP\Framework\MCP\Audit\AuditDatabase ) {
			return $wpdb;
		}

		if ( $wpdb instanceof \wpdb ) {
			return new \Saltus\WP\Framework\MCP\Audit\WpdbAuditDatabase( $wpdb );
		}

		return null;
	}
}
