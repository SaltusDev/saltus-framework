<?php
namespace Saltus\WP\Framework\MCP\RateLimiter;

class RateLimitResult {

	public bool $allowed;
	public int $remaining;
	public float $reset_at;
	public ?int $retry_after;

	public function __construct( bool $allowed, int $remaining, float $reset_at, ?int $retry_after = null ) {
		$this->allowed     = $allowed;
		$this->remaining   = $remaining;
		$this->reset_at    = $reset_at;
		$this->retry_after = $retry_after;
	}
}
