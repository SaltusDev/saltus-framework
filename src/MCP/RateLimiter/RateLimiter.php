<?php
namespace Saltus\WP\Framework\MCP\RateLimiter;

class RateLimitResult {

	public bool $allowed;
	public int $remaining;
	public float $resetAt;
	public ?int $retryAfter;

	public function __construct( bool $allowed, int $remaining, float $resetAt, ?int $retryAfter = null ) {
		$this->allowed    = $allowed;
		$this->remaining  = $remaining;
		$this->resetAt    = $resetAt;
		$this->retryAfter = $retryAfter;
	}
}

class RateLimiter {

	/** @var array<string, list<float>> */
	private array $requests = [];
	private int $maxRequests;
	private int $windowSeconds;

	public function __construct( int $maxRequests = 60, int $windowSeconds = 60 ) {
		$this->maxRequests  = $maxRequests;
		$this->windowSeconds = $windowSeconds;
	}

	public function check( string $identifier ): RateLimitResult {
		$now = microtime( true );
		$cutoff = $now - $this->windowSeconds;

		$timestamps = $this->requests[ $identifier ] ?? [];
		$timestamps = array_values( array_filter( $timestamps, fn( float $t ) => $t >= $cutoff ) );

		if ( count( $timestamps ) >= $this->maxRequests ) {
			$oldest    = $timestamps[0];
			$resetAt   = $oldest + $this->windowSeconds;
			$retryAfter = (int) ceil( $resetAt - $now );

			return new RateLimitResult( false, 0, $resetAt, max( $retryAfter, 1 ) );
		}

		$timestamps[] = $now;
		$this->requests[ $identifier ] = $timestamps;

		$remaining = $this->maxRequests - count( $timestamps );
		$resetAt   = $now + $this->windowSeconds;

		return new RateLimitResult( true, $remaining, $resetAt, null );
	}
}
