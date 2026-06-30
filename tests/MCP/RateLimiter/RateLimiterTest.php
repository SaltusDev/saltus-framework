<?php

namespace Saltus\WP\Framework\Tests\MCP\RateLimiter;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\RateLimiter\RateLimiter;
use Saltus\WP\Framework\MCP\RateLimiter\RateLimitResult;

class RateLimiterTest extends TestCase
{
    public function testAllowsRequestsUnderLimit(): void
    {
        $limiter = new RateLimiter(5, 60);

        for ($i = 0; $i < 5; $i++) {
            $result = $limiter->check('client1');
            $this->assertTrue($result->allowed);
        }
    }

    public function testBlocksRequestsOverLimit(): void
    {
        $limiter = new RateLimiter(3, 60);

        for ($i = 0; $i < 3; $i++) {
            $limiter->check('client2');
        }

        $result = $limiter->check('client2');
        $this->assertFalse($result->allowed);
    }

    public function testAllowsAfterWindowExpires(): void
    {
        $limiter = new RateLimiter(1, 0);

        $limiter->check('bob');
        usleep(1000);
        $result = $limiter->check('bob');
        $this->assertTrue($result->allowed);
    }

    public function testDifferentIdentifiersIndependent(): void
    {
        $limiter = new RateLimiter(2, 60);

        $this->assertTrue($limiter->check('alice')->allowed);
        $this->assertTrue($limiter->check('alice')->allowed);
        $this->assertFalse($limiter->check('alice')->allowed);

        $this->assertTrue($limiter->check('bob')->allowed);
    }

    public function testReturnsRemainingCount(): void
    {
        $limiter = new RateLimiter(5, 60);

        $result = $limiter->check('user');
        $this->assertSame(4, $result->remaining);
    }

    public function testReturnedZeroRemainingWhenBlocked(): void
    {
        $limiter = new RateLimiter(1, 60);

        $limiter->check('limited');
        $result = $limiter->check('limited');

        $this->assertSame(0, $result->remaining);
    }

    public function testRetryAfterOnBlocked(): void
    {
        $limiter = new RateLimiter(1, 10);

        $limiter->check('slow');
        $result = $limiter->check('slow');

        $this->assertNotNull($result->retry_after);
        $this->assertGreaterThanOrEqual(1, $result->retry_after);
    }

    public function testResetAtIsFutureTimestamp(): void
    {
        $limiter = new RateLimiter(1, 60);

        $result = $limiter->check('future');
        $this->assertGreaterThan(microtime(true), $result->reset_at);
    }
}
