<?php

namespace Saltus\WP\Framework\Tests\MCP\Cache;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Cache\InMemoryCache;

class InMemoryCacheTest extends TestCase
{
    private InMemoryCache $cache;

    protected function setUp(): void
    {
        $this->cache = new InMemoryCache();
    }

    public function testGetReturnsNullForMissingKey(): void
    {
        $this->assertNull($this->cache->get('nonexistent'));
    }

    public function testSetAndGet(): void
    {
        $this->cache->set('models', ['data' => 'test'], 60);
        $this->assertSame(['data' => 'test'], $this->cache->get('models'));
    }

    public function testHas(): void
    {
        $this->assertFalse($this->cache->has('foo'));
        $this->cache->set('foo', ['bar' => 1], 60);
        $this->assertTrue($this->cache->has('foo'));
    }

    public function testExpiredEntryReturnsNull(): void
    {
        $this->cache->set('ephemeral', ['x' => 1], 0);
        usleep(1000);
        $this->assertNull($this->cache->get('ephemeral'));
    }

    public function testExpiredHasReturnsFalse(): void
    {
        $this->cache->set('gone', ['x' => 1], 0);
        usleep(1000);
        $this->assertFalse($this->cache->has('gone'));
    }

    public function testDelete(): void
    {
        $this->cache->set('key', ['value' => 1], 60);
        $this->cache->delete('key');
        $this->assertNull($this->cache->get('key'));
    }

    public function testClear(): void
    {
        $this->cache->set('a', [1], 60);
        $this->cache->set('b', [2], 60);
        $this->cache->clear();
        $this->assertNull($this->cache->get('a'));
        $this->assertNull($this->cache->get('b'));
    }

    public function testOverwriteExistingKey(): void
    {
        $this->cache->set('key', ['first' => 1], 60);
        $this->cache->set('key', ['second' => 2], 60);
        $this->assertSame(['second' => 2], $this->cache->get('key'));
    }

    public function testMultipleKeysIndependently(): void
    {
        $this->cache->set('key1', ['a' => 1], 60);
        $this->cache->set('key2', ['b' => 2], 60);
        $this->assertSame(['a' => 1], $this->cache->get('key1'));
        $this->assertSame(['b' => 2], $this->cache->get('key2'));
    }

    public function testTtlOverride(): void
    {
        $this->cache->set('short', ['x' => 1], 0);
        $this->cache->set('long', ['y' => 2], 60);
        usleep(1000);
        $this->assertNull($this->cache->get('short'));
        $this->assertSame(['y' => 2], $this->cache->get('long'));
    }
}
