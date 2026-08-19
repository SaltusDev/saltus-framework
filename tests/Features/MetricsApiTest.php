<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Observability\MetricsApi;
use Saltus\WP\Framework\MCP\Audit\RollupStore;
use Saltus\WP\Framework\Tests\MCP\Audit\RollupTestDatabase;

/**
 * @covers \Saltus\WP\Framework\Features\Observability\MetricsApi
 */
class MetricsApiTest extends TestCase {

	private RollupTestDatabase $db;

	protected function setUp(): void {
		parent::setUp();

		global $wp_current_user_can, $wp_nonce_valid, $wp_actions_registered, $wp_ajax_referer_checks, $wp_filter_values, $wp_options;

		$wp_current_user_can    = true;
		$wp_nonce_valid         = true;
		$wp_actions_registered  = [];
		$wp_ajax_referer_checks = [];
		$wp_filter_values       = [];
		$wp_options             = [];

		$_GET = [];

		$this->db = new RollupTestDatabase();
	}

	protected function tearDown(): void {
		global $wp_current_user_can, $wp_nonce_valid, $wp_actions_registered, $wp_ajax_referer_checks, $wp_filter_values, $wp_options;

		$wp_current_user_can    = true;
		$wp_nonce_valid         = true;
		$wp_actions_registered  = [];
		$wp_ajax_referer_checks = [];
		$wp_filter_values       = [];
		$wp_options             = [];

		$_GET = [];

		parent::tearDown();
	}

	private function api(): MetricsApi {
		return new MetricsApi( new RollupStore( $this->db ) );
	}

	/**
	 * Seed a rollup row directly, bypassing computation.
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
				'exception_count'        => 0,
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
	 * @return array{success: bool, data: mixed}
	 */
	private function capture( MetricsApi $api ): array {
		try {
			$api->handle_get_metrics();
		} catch ( \SaltusJsonResponse $response ) {
			return [
				'success' => $response->success,
				'data'    => $response->data,
			];
		}

		$this->fail( 'handle_get_metrics did not send a JSON response' );
	}

	public function test_register_hooks_the_ajax_action(): void {
		global $wp_actions_registered;

		$this->api()->register();

		$hooks = array_column( $wp_actions_registered, 'hook_name' );

		$this->assertContains( 'wp_ajax_saltus_get_metrics', $hooks );
	}

	/**
	 * The nonce is checked before the capability and before any query. An AJAX
	 * endpoint that reads first and validates second is a CSRF hole regardless of
	 * what it returns.
	 */
	public function test_rejects_a_request_with_an_invalid_nonce(): void {
		global $wp_nonce_valid;

		$wp_nonce_valid = false;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'invalid_nonce' );

		$this->api()->handle_get_metrics();
	}

	public function test_checks_the_nonce_against_the_metrics_action(): void {
		global $wp_ajax_referer_checks;

		$this->capture( $this->api() );

		$this->assertSame( 'saltus_metrics', $wp_ajax_referer_checks[0]['action'] );
		$this->assertSame( 'nonce', $wp_ajax_referer_checks[0]['query_arg'] );
	}

	public function test_rejects_a_caller_without_manage_options(): void {
		global $wp_current_user_can;

		$wp_current_user_can = false;

		$result = $this->capture( $this->api() );

		$this->assertFalse( $result['success'] );
	}

	/**
	 * A denied caller must not reach the database.
	 *
	 * The double is put in returning mode here on purpose. Throwing would model
	 * WordPress ending the request, and then this test would pass with or without
	 * the explicit `return` after the rejection — it would be asserting on the
	 * double's behavior rather than the source's. Returning is the only way the
	 * missing-`return` case is observable.
	 */
	public function test_a_denied_caller_never_queries_the_rollup_table(): void {
		global $wp_current_user_can, $wp_json_response_returns, $wp_json_responses;

		$wp_current_user_can      = false;
		$wp_json_response_returns = true;
		$wp_json_responses        = [];

		try {
			$this->api()->handle_get_metrics();
		} finally {
			$wp_json_response_returns = false;
		}

		$selects = array_filter(
			$this->db->queries,
			static fn( string $query ): bool => strpos( $query, 'SELECT * FROM' ) === 0
		);

		$this->assertSame( [], $selects, 'a denied caller must not query the rollup table' );
		$this->assertCount( 1, $wp_json_responses, 'exactly one response, not a rejection followed by a success' );
		$this->assertFalse( $wp_json_responses[0]['success'] );
	}

	public function test_returns_aggregate_totals(): void {
		$this->seedRollup(
			[
				'call_count'      => 10,
				'error_count'     => 1,
				'exception_count' => 1,
				'avg_duration_ms' => 20.0,
			]
		);

		$result = $this->capture( $this->api() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 10, $result['data']['metrics']['total_calls'] );
		$this->assertSame( 0.2, $result['data']['metrics']['error_rate'] );
		$this->assertSame( 20.0, $result['data']['metrics']['avg_latency_ms'] );
	}

	/**
	 * Latency across rollups is weighted by call count, not a mean of means. Two
	 * days at 10ms/1 call and 100ms/99 calls average to ~99ms, not 55ms.
	 */
	public function test_average_latency_is_weighted_by_call_count(): void {
		$this->seedRollup(
			[
				'rollup_date'     => gmdate( 'Y-m-d' ),
				'ability'         => 'a',
				'call_count'      => 1,
				'error_count'     => 0,
				'avg_duration_ms' => 10.0,
			]
		);
		$this->seedRollup(
			[
				'rollup_date'     => gmdate( 'Y-m-d' ),
				'ability'         => 'b',
				'call_count'      => 99,
				'error_count'     => 0,
				'avg_duration_ms' => 100.0,
			]
		);

		$result = $this->capture( $this->api() );

		$this->assertSame( 100, $result['data']['metrics']['total_calls'] );
		$this->assertEqualsWithDelta( 99.1, $result['data']['metrics']['avg_latency_ms'], 0.01 );
	}

	public function test_client_metrics_are_separate_and_grouped_without_changing_totals(): void {
		$this->seedRollup( [ 'call_count' => 10, 'client_identifier' => null ] );
		$this->seedRollup( [ 'call_count' => 4, 'client_identifier' => 'webmcp:user:1', 'avg_duration_ms' => 10.0, 'p95_duration_ms' => 30.0, 'error_count' => 0 ] );
		$this->seedRollup( [ 'call_count' => 6, 'client_identifier' => 'webmcp:user:1', 'avg_duration_ms' => 20.0, 'p95_duration_ms' => 80.0, 'error_count' => 2 ] );
		$this->seedRollup( [ 'call_count' => 3, 'client_identifier' => 'webmcp:user:2', 'avg_duration_ms' => 5.0 ] );

		$result = $this->capture( $this->api() );

		$this->assertSame( 10, $result['data']['metrics']['total_calls'] );
		$this->assertCount( 2, $result['data']['metrics']['per_client'] );
		$this->assertSame( 'webmcp:user:1', $result['data']['metrics']['per_client'][0]['client_identifier'] );
		$this->assertSame( 10, $result['data']['metrics']['per_client'][0]['call_count'] );
		$this->assertSame( 2, $result['data']['metrics']['per_client'][0]['error_count'] );
		$this->assertSame( 16.0, $result['data']['metrics']['per_client'][0]['avg_duration_ms'] );
		$this->assertSame( 80.0, $result['data']['metrics']['per_client'][0]['p95_duration_ms'] );
	}

	public function test_returns_zero_totals_with_no_data(): void {
		$result = $this->capture( $this->api() );

		$this->assertSame( 0, $result['data']['metrics']['total_calls'] );
		$this->assertSame( 0.0, $result['data']['metrics']['error_rate'] );
		$this->assertSame( 0.0, $result['data']['metrics']['avg_latency_ms'] );
		$this->assertSame( [], $result['data']['metrics']['per_ability'] );
	}

	public function test_groups_per_ability_across_days(): void {
		$today     = gmdate( 'Y-m-d' );
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		$this->seedRollup(
			[
				'rollup_date'     => $today,
				'ability'         => 'list_models',
				'call_count'      => 5,
				'error_count'     => 1,
				'p95_duration_ms' => 40.0,
			]
		);
		$this->seedRollup(
			[
				'rollup_date'     => $yesterday,
				'ability'         => 'list_models',
				'call_count'      => 5,
				'error_count'     => 0,
				'p95_duration_ms' => 90.0,
			]
		);

		$result = $this->capture( $this->api() );

		$per_ability = $result['data']['metrics']['per_ability'];

		$this->assertCount( 1, $per_ability );
		$this->assertSame( 'list_models', $per_ability[0]['ability'] );
		$this->assertSame( 10, $per_ability[0]['call_count'] );
		$this->assertSame( 1, $per_ability[0]['error_count'] );
	}

	/**
	 * p95 cannot be averaged across days — that understates the tail. The worst
	 * day's p95 is reported instead.
	 */
	public function test_per_ability_p95_is_the_worst_day_not_an_average(): void {
		$today     = gmdate( 'Y-m-d' );
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		$this->seedRollup(
			[
				'rollup_date'     => $today,
				'call_count'      => 5,
				'p95_duration_ms' => 40.0,
			]
		);
		$this->seedRollup(
			[
				'rollup_date'     => $yesterday,
				'call_count'      => 5,
				'p95_duration_ms' => 90.0,
			]
		);

		$result = $this->capture( $this->api() );

		$this->assertSame( 90.0, $result['data']['metrics']['per_ability'][0]['p95_duration_ms'] );
	}

	public function test_daily_calls_are_keyed_by_date(): void {
		$today = gmdate( 'Y-m-d' );

		$this->seedRollup(
			[
				'rollup_date' => $today,
				'ability'     => 'a',
				'call_count'  => 3,
			]
		);
		$this->seedRollup(
			[
				'rollup_date' => $today,
				'ability'     => 'b',
				'call_count'  => 4,
			]
		);

		$result = $this->capture( $this->api() );

		$this->assertSame( 7, $result['data']['metrics']['daily_calls'][ $today ] );
	}

	public function test_returns_sorted_distinct_abilities(): void {
		$today = gmdate( 'Y-m-d' );

		$this->seedRollup(
			[
				'rollup_date' => $today,
				'ability'     => 'zeta',
			]
		);
		$this->seedRollup(
			[
				'rollup_date' => $today,
				'ability'     => 'alpha',
			]
		);

		$result = $this->capture( $this->api() );

		$this->assertSame( [ 'alpha', 'zeta' ], $result['data']['abilities'] );
	}

	public function test_filters_by_the_requested_ability(): void {
		$today = gmdate( 'Y-m-d' );

		$this->seedRollup(
			[
				'rollup_date' => $today,
				'ability'     => 'list_models',
				'call_count'  => 5,
			]
		);
		$this->seedRollup(
			[
				'rollup_date' => $today,
				'ability'     => 'get_content',
				'call_count'  => 7,
			]
		);

		$_GET['ability'] = 'get_content';

		$result = $this->capture( $this->api() );

		$this->assertSame( 7, $result['data']['metrics']['total_calls'] );
		$this->assertSame( [ 'get_content' ], $result['data']['abilities'] );
	}

	/**
	 * WordPress slashes request superglobals, so a namespaced ability name arrives
	 * with its separators doubled. Sanitizing without unslashing first compares the
	 * doubled form against the stored single-backslash name and filters to nothing.
	 */
	public function test_unslashes_the_ability_filter_before_comparing(): void {
		$today = gmdate( 'Y-m-d' );

		$this->seedRollup(
			[
				'rollup_date' => $today,
				'ability'     => 'Saltus\Models\Post',
				'call_count'  => 5,
			]
		);

		$_GET['ability'] = 'Saltus\\\\Models\\\\Post';

		$result = $this->capture( $this->api() );

		$this->assertSame( 5, $result['data']['metrics']['total_calls'] );
		$this->assertSame( [ 'Saltus\Models\Post' ], $result['data']['abilities'] );
	}

	/**
	 * A window spanning a sampling configuration change used to collapse to
	 * `is_sampled: false` with a null rate, presenting thinned counts as exact in
	 * the one case where the notice matters most.
	 */
	public function test_a_window_mixing_sampled_and_unsampled_days_discloses_sampling(): void {
		$today     = gmdate( 'Y-m-d' );
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		$this->seedRollup(
			[
				'rollup_date'  => $yesterday,
				'sample_rate'  => 0.1,
				'call_count'   => 20,
				'error_count'  => 0,
			]
		);
		$this->seedRollup(
			[
				'rollup_date'  => $today,
				'sample_rate'  => 1.0,
				'call_count'   => 30,
				'error_count'  => 0,
			]
		);

		$sampling = $this->capture( $this->api() )['data']['metrics']['sampling'];

		$this->assertTrue( $sampling['is_sampled'], 'one sampled day in the window is still a sampled window' );
		$this->assertNull( $sampling['sample_rate'], 'no single rate describes a mixed window' );
		$this->assertSame(
			[
				[ 'rate' => 0.1, 'call_count' => 20 ],
				[ 'rate' => 1.0, 'call_count' => 30 ],
			],
			$sampling['sample_rates']
		);
		// 200 estimated from the sampled day, 30 recorded exactly.
		$this->assertSame( 230.0, $sampling['estimated_total_calls'] );
	}

	public function test_a_single_rate_window_still_reports_that_one_rate(): void {
		$this->seedRollup(
			[
				'sample_rate' => 0.5,
				'call_count'  => 10,
				'error_count' => 0,
			]
		);

		$sampling = $this->capture( $this->api() )['data']['metrics']['sampling'];

		$this->assertTrue( $sampling['is_sampled'] );
		$this->assertSame( 0.5, $sampling['sample_rate'] );
		$this->assertSame( [ [ 'rate' => 0.5, 'call_count' => 10 ] ], $sampling['sample_rates'] );
	}

	/**
	 * The estimate ships on every window, not only sampled ones, so the dashboard
	 * has one field to read. With nothing sampled it equals the recorded count.
	 */
	public function test_an_unsampled_window_reports_the_recorded_count_as_the_estimate(): void {
		$this->seedRollup( [ 'call_count' => 12, 'error_count' => 0 ] );

		$sampling = $this->capture( $this->api() )['data']['metrics']['sampling'];

		$this->assertFalse( $sampling['is_sampled'] );
		$this->assertSame( 1.0, $sampling['sample_rate'] );
		$this->assertSame( 12.0, $sampling['estimated_total_calls'] );
	}

	/**
	 * The window error rate is weighted by each day's own recorded rate. Summing
	 * failures over recorded calls would read 2/50 here; the sampled day's 2
	 * failures were kept whole and its 18 successes stand for 180, so the window
	 * covers 212 calls and the true rate is 2/212.
	 */
	public function test_window_error_rate_is_weighted_by_each_days_sample_rate(): void {
		$today     = gmdate( 'Y-m-d' );
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		$this->seedRollup(
			[
				'rollup_date'     => $yesterday,
				'sample_rate'     => 0.1,
				'call_count'      => 20,
				'error_count'     => 1,
				'exception_count' => 1,
			]
		);
		$this->seedRollup(
			[
				'rollup_date'     => $today,
				'sample_rate'     => 1.0,
				'call_count'      => 30,
				'error_count'     => 0,
				'exception_count' => 0,
			]
		);

		$metrics = $this->capture( $this->api() )['data']['metrics'];

		$this->assertSame( 50, $metrics['total_calls'], 'reported counts stay the recorded rows' );
		$this->assertEqualsWithDelta( 2 / 212, $metrics['error_rate'], 1e-12 );
	}

	/**
	 * An out-of-band range falls back to the 7-day default rather than being
	 * clamped to the bound: a negative or absurd range is a malformed request,
	 * and answering with the default is what the dashboard expects.
	 *
	 * @dataProvider provideInvalidRanges
	 */
	public function test_invalid_range_falls_back_to_seven_days( string $range ): void {
		$_GET['range'] = $range;

		$today   = gmdate( 'Y-m-d' );
		$in_range = gmdate( 'Y-m-d', strtotime( '-3 days' ) );
		$too_old  = gmdate( 'Y-m-d', strtotime( '-40 days' ) );

		$this->seedRollup(
			[
				'rollup_date' => $in_range,
				'call_count'  => 5,
			]
		);
		$this->seedRollup(
			[
				'rollup_date' => $too_old,
				'call_count'  => 9,
			]
		);

		$result = $this->capture( $this->api() );

		$this->assertSame( 5, $result['data']['metrics']['total_calls'], 'a 7-day window must exclude the 40-day-old row' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provideInvalidRanges(): array {
		return [
			'zero'        => [ '0' ],
			'negative'    => [ '-5' ],
			'over a year' => [ '400' ],
			'non-numeric' => [ 'abc' ],
		];
	}

	public function test_honors_a_valid_range(): void {
		$_GET['range'] = '30';

		$this->seedRollup(
			[
				'rollup_date' => gmdate( 'Y-m-d', strtotime( '-20 days' ) ),
				'call_count'  => 11,
			]
		);

		$result = $this->capture( $this->api() );

		$this->assertSame( 11, $result['data']['metrics']['total_calls'] );
	}

	/**
	 * Roll up one day of audit traffic and return the metrics payload.
	 *
	 * @return array<string, mixed>
	 */
	private function metricsForRolledUpTraffic( bool $client_mode ): array {
		global $wp_filter_values;

		$wp_filter_values['saltus/framework/mcp/audit/rollup_by_client'] = $client_mode;

		$this->db = new RollupTestDatabase();

		$date = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		$this->db->addAuditRow( 'list_models', 'success', 10.0, $date . ' 09:00:00.000', 'webmcp:user:1' );
		$this->db->addAuditRow( 'list_models', 'error', 30.0, $date . ' 09:00:01.000', 'webmcp:user:2' );
		$this->db->addAuditRow( 'get_content', 'success', 20.0, $date . ' 09:00:02.000', 'webmcp:user:1' );

		$store = new RollupStore( $this->db );
		$store->compute_and_store_rollup( $date );

		$payload = $this->capture( new MetricsApi( $store ) )['data'];

		return is_array( $payload ) && isset( $payload['metrics'] ) && is_array( $payload['metrics'] )
			? $payload['metrics']
			: [];
	}

	/**
	 * The client filter chooses which series exist, never what a total means.
	 * Client mode used to write only per-client rows, so the aggregate series the
	 * totals are read from went empty and every number on the dashboard depended
	 * on whether the filter happened to be on.
	 */
	public function test_totals_are_identical_with_client_mode_on_and_off(): void {
		$without = $this->metricsForRolledUpTraffic( false );
		$with    = $this->metricsForRolledUpTraffic( true );

		$this->assertSame( 3, $without['total_calls'] );
		$this->assertSame( $without['total_calls'], $with['total_calls'] );
		$this->assertSame( $without['error_rate'], $with['error_rate'] );
		$this->assertSame( $without['avg_latency_ms'], $with['avg_latency_ms'] );
		$this->assertSame( $without['daily_calls'], $with['daily_calls'] );
		$this->assertSame( $without['per_ability'], $with['per_ability'] );
	}

	/**
	 * The per-client view is the added series, and it must stay out of the total:
	 * summing client rows into `total_calls` would count the same traffic twice.
	 */
	public function test_client_mode_adds_the_per_client_series_without_inflating_the_total(): void {
		$with = $this->metricsForRolledUpTraffic( true );

		$this->assertSame( 3, $with['total_calls'] );
		$this->assertCount( 2, $with['per_client'] );

		$client_calls = array_sum( array_column( $with['per_client'], 'call_count' ) );

		$this->assertSame( 3, $client_calls, 'the client series covers the same traffic, not extra traffic' );
		$this->assertSame( [], $this->metricsForRolledUpTraffic( false )['per_client'] );
	}
}
