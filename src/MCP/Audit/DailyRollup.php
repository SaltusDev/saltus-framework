<?php
namespace Saltus\WP\Framework\MCP\Audit;

/**
 * Value object representing aggregated metrics for one ability on one day.
 * @api
 */
final class DailyRollup {

	private string $date;
	private string $ability;
	private ?string $client_identifier;
	private float $sample_rate;
	private int $call_count;
	private int $error_count;
	private int $exception_count;
	private int $validation_error_count;
	private int $rate_limited_count;
	private float $avg_duration_ms;
	private float $p50_duration_ms;
	private float $p95_duration_ms;
	private float $p99_duration_ms;
	private float $max_duration_ms;

	/**
	 * @param array{
	 *   date: string,
	 *   ability: string,
	 *   client_identifier?: string|null,
	 *   sample_rate?: float,
	 *   call_count: int,
	 *   error_count: int,
	 *   exception_count: int,
	 *   validation_error_count: int,
	 *   rate_limited_count: int,
	 *   avg_duration_ms: float,
	 *   p50_duration_ms: float,
	 *   p95_duration_ms: float,
	 *   p99_duration_ms: float,
	 *   max_duration_ms: float
	 * } $data
	 */
	public function __construct( array $data ) {
		$this->date                   = $data['date'];
		$this->ability                = $data['ability'];
		$this->client_identifier      = isset( $data['client_identifier'] ) && is_string( $data['client_identifier'] ) ? $data['client_identifier'] : null;
		$this->sample_rate            = isset( $data['sample_rate'] ) ? max( 0.0, min( 1.0, (float) $data['sample_rate'] ) ) : 1.0;
		$this->call_count             = $data['call_count'];
		$this->error_count            = $data['error_count'];
		$this->exception_count        = $data['exception_count'];
		$this->validation_error_count = $data['validation_error_count'];
		$this->rate_limited_count     = $data['rate_limited_count'];
		$this->avg_duration_ms        = $data['avg_duration_ms'];
		$this->p50_duration_ms        = $data['p50_duration_ms'];
		$this->p95_duration_ms        = $data['p95_duration_ms'];
		$this->p99_duration_ms        = $data['p99_duration_ms'];
		$this->max_duration_ms        = $data['max_duration_ms'];
	}

	public function date(): string {
		return $this->date;
	}

	public function ability(): string {
		return $this->ability;
	}

	public function client_identifier(): ?string {
		return $this->client_identifier;
	}

	public function sample_rate(): float {
		return $this->sample_rate;
	}

	public function call_count(): int {
		return $this->call_count;
	}

	public function error_count(): int {
		return $this->error_count;
	}

	public function exception_count(): int {
		return $this->exception_count;
	}

	public function validation_error_count(): int {
		return $this->validation_error_count;
	}

	public function rate_limited_count(): int {
		return $this->rate_limited_count;
	}

	public function avg_duration_ms(): float {
		return $this->avg_duration_ms;
	}

	public function p50_duration_ms(): float {
		return $this->p50_duration_ms;
	}

	public function p95_duration_ms(): float {
		return $this->p95_duration_ms;
	}

	public function p99_duration_ms(): float {
		return $this->p99_duration_ms;
	}

	public function max_duration_ms(): float {
		return $this->max_duration_ms;
	}

	public function error_rate(): float {
		if ( $this->call_count === 0 ) {
			return 0.0;
		}
		return ( $this->error_count + $this->exception_count ) / $this->call_count;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'date'                   => $this->date,
			'ability'                => $this->ability,
			'client_identifier'      => $this->client_identifier,
			'sample_rate'            => $this->sample_rate,
			'call_count'             => $this->call_count,
			'error_count'            => $this->error_count,
			'exception_count'        => $this->exception_count,
			'validation_error_count' => $this->validation_error_count,
			'rate_limited_count'     => $this->rate_limited_count,
			'avg_duration_ms'        => $this->avg_duration_ms,
			'p50_duration_ms'        => $this->p50_duration_ms,
			'p95_duration_ms'        => $this->p95_duration_ms,
			'p99_duration_ms'        => $this->p99_duration_ms,
			'max_duration_ms'        => $this->max_duration_ms,
			'error_rate'             => $this->error_rate(),
		];
	}
}
