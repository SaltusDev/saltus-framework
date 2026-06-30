<?php

namespace Saltus\WP\Framework\Tests\MCP\RateLimiter;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\RateLimiter\RateLimitResult;

class RateLimitResultTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $result = new RateLimitResult(true, 42, 1000.5, 5);

        $this->assertTrue($result->allowed);
        $this->assertSame(42, $result->remaining);
        $this->assertSame(1000.5, $result->reset_at);
        $this->assertSame(5, $result->retry_after);
    }

    public function testAllowedResult(): void
    {
        $result = new RateLimitResult(true, 59, 1234.0);

        $this->assertTrue($result->allowed);
        $this->assertSame(59, $result->remaining);
        $this->assertSame(1234.0, $result->reset_at);
        $this->assertNull($result->retry_after);
    }

    public function testBlockedResult(): void
    {
        $result = new RateLimitResult(false, 0, 999.0, 30);

        $this->assertFalse($result->allowed);
        $this->assertSame(0, $result->remaining);
        $this->assertSame(999.0, $result->reset_at);
        $this->assertSame(30, $result->retry_after);
    }
}
