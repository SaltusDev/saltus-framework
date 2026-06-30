<?php
namespace Saltus\WP\Framework\MCP\RateLimiter;

class RateLimiter {

	/** @var array<string, list<float>> */
	private array $requests = [];
	private int $max_requests;
	private int $window_seconds;

	public function __construct( int $max_requests = 60, int $window_seconds = 60 ) {
		$this->max_requests   = $max_requests;
		$this->window_seconds = $window_seconds;
	}

	public function check( string $identifier ): RateLimitResult {
		$now    = microtime( true );
		$cutoff = $now - $this->window_seconds;

		$timestamps = $this->requests[ $identifier ] ?? [];
		$timestamps = array_values( array_filter( $timestamps, fn( float $t ) => $t >= $cutoff ) );

		if ( count( $timestamps ) >= $this->max_requests ) {
			$oldest      = $timestamps[0];
			$reset_at    = $oldest + $this->window_seconds;
			$retry_after = (int) ceil( $reset_at - $now );

			return new RateLimitResult( false, 0, $reset_at, max( $retry_after, 1 ) );
		}

		$timestamps[]                  = $now;
		$this->requests[ $identifier ] = $timestamps;

		$remaining = $this->max_requests - count( $timestamps );
		$reset_at  = $now + $this->window_seconds;

		return new RateLimitResult( true, $remaining, $reset_at, null );
	}
}
