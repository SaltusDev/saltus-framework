<?php

namespace Saltus\WP\Framework\Tests\MCP\Audit;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Audit\DailyRollup;

/**
 * @covers \Saltus\WP\Framework\MCP\Audit\DailyRollup
 */
class DailyRollupTest extends TestCase {

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function data( array $overrides = [] ): array {
		return array_merge(
			[
				'date'                   => '2026-08-13',
				'ability'                => 'list_models',
				'call_count'             => 10,
				'error_count'            => 1,
				'exception_count'        => 1,
				'validation_error_count' => 2,
				'rate_limited_count'     => 3,
				'avg_duration_ms'        => 12.5,
				'p50_duration_ms'        => 10.0,
				'p95_duration_ms'        => 40.0,
				'p99_duration_ms'        => 80.0,
				'max_duration_ms'        => 90.0,
			],
			$overrides
		);
	}

	public function test_accessors_return_constructor_values(): void {
		$rollup = new DailyRollup( $this->data() );

		$this->assertSame( '2026-08-13', $rollup->date() );
		$this->assertSame( 'list_models', $rollup->ability() );
		$this->assertSame( 10, $rollup->call_count() );
		$this->assertSame( 1, $rollup->error_count() );
		$this->assertSame( 1, $rollup->exception_count() );
		$this->assertSame( 2, $rollup->validation_error_count() );
		$this->assertSame( 3, $rollup->rate_limited_count() );
		$this->assertSame( 12.5, $rollup->avg_duration_ms() );
		$this->assertSame( 10.0, $rollup->p50_duration_ms() );
		$this->assertSame( 40.0, $rollup->p95_duration_ms() );
		$this->assertSame( 80.0, $rollup->p99_duration_ms() );
		$this->assertSame( 90.0, $rollup->max_duration_ms() );
	}

	/**
	 * Errors and exceptions both count against the rate; validation failures and
	 * rate limiting do not. A validation error is a caller sending bad input and
	 * a rate limit is the server working as configured — folding either into the
	 * error rate would make a healthy site look broken.
	 */
	public function test_error_rate_counts_errors_and_exceptions_only(): void {
		$rollup = new DailyRollup(
			$this->data(
				[
					'call_count'             => 10,
					'error_count'            => 2,
					'exception_count'        => 3,
					'validation_error_count' => 4,
					'rate_limited_count'     => 1,
				]
			)
		);

		$this->assertSame( 0.5, $rollup->error_rate() );
	}

	public function test_error_rate_is_zero_when_no_calls(): void {
		$rollup = new DailyRollup(
			$this->data(
				[
					'call_count'      => 0,
					'error_count'     => 0,
					'exception_count' => 0,
				]
			)
		);

		$this->assertSame( 0.0, $rollup->error_rate() );
	}

	/**
	 * A zero call count with a non-zero error count should not divide. This can
	 * only arrive from a hand-built or corrupted row, and returning 0.0 beats a
	 * DivisionByZeroError on a dashboard render.
	 */
	public function test_error_rate_does_not_divide_by_zero_with_errors_present(): void {
		$rollup = new DailyRollup(
			$this->data(
				[
					'call_count'      => 0,
					'error_count'     => 5,
					'exception_count' => 2,
				]
			)
		);

		$this->assertSame( 0.0, $rollup->error_rate() );
	}

	public function test_to_array_round_trips_through_the_constructor(): void {
		$original = new DailyRollup( $this->data() );
		$rebuilt  = new DailyRollup( $original->to_array() );

		$this->assertSame( $original->to_array(), $rebuilt->to_array() );
	}

	public function test_to_array_includes_the_derived_error_rate(): void {
		$rollup = new DailyRollup(
			$this->data(
				[
					'call_count'      => 4,
					'error_count'     => 1,
					'exception_count' => 0,
				]
			)
		);

		$array = $rollup->to_array();

		$this->assertArrayHasKey( 'error_rate', $array );
		$this->assertSame( 0.25, $array['error_rate'] );
	}

	public function test_zero_durations_are_preserved_as_floats(): void {
		$rollup = new DailyRollup(
			$this->data(
				[
					'avg_duration_ms' => 0.0,
					'p50_duration_ms' => 0.0,
					'p95_duration_ms' => 0.0,
					'p99_duration_ms' => 0.0,
					'max_duration_ms' => 0.0,
				]
			)
		);

		$this->assertSame( 0.0, $rollup->avg_duration_ms() );
		$this->assertSame( 0.0, $rollup->max_duration_ms() );
	}
}
