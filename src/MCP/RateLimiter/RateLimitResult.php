<?php
namespace Saltus\WP\Framework\MCP\RateLimiter;

/**
 * Value object representing the outcome of a rate limit check.
 */
class RateLimitResult {

	public bool $allowed;
	public int $remaining;
	public float $reset_at;
	public ?int $retry_after;

	/**
	 * @param bool $allowed  Whether the request is allowed.
	 * @param int $remaining  Number of requests remaining in the window.
	 * @param float $reset_at  Unix timestamp when the window resets.
	 * @param int|null $retry_after  Seconds to wait before retrying, if denied.
	 */
	public function __construct( bool $allowed, int $remaining, float $reset_at, ?int $retry_after = null ) {
		$this->allowed     = $allowed;
		$this->remaining   = $remaining;
		$this->reset_at    = $reset_at;
		$this->retry_after = $retry_after;
	}
}
