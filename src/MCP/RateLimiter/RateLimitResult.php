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
