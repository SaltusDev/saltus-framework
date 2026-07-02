<?php

namespace Saltus\WP\Framework\Tests\MCP\Cache;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Cache\TransientCache;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

class TransientCacheTest extends TestCase {

	protected function setUp(): void {
		global $wp_transients, $wp_options;
		$wp_transients = [];
		$wp_options    = [];
	}

	public function testGetReturnsNullForMissingKey(): void {
		$cache = new TransientCache();
		$this->assertNull( $cache->get( 'nonexistent' ) );
	}

	public function testSetAndGet(): void {
		$cache = new TransientCache();
		$cache->set( 'test_key', [ 'data' => 'value' ], 60 );
		$this->assertSame( [ 'data' => 'value' ], $cache->get( 'test_key' ) );
	}

	public function testHas(): void {
		$cache = new TransientCache();
		$cache->set( 'present', [ 'x' => 1 ], 60 );
		$this->assertTrue( $cache->has( 'present' ) );
		$this->assertFalse( $cache->has( 'absent' ) );
	}

	public function testDelete(): void {
		$cache = new TransientCache();
		$cache->set( 'tmp', [ 'x' => 1 ], 60 );
		$cache->delete( 'tmp' );
		$this->assertNull( $cache->get( 'tmp' ) );
	}

	public function testClearRemovesAllKeys(): void {
		$cache = new TransientCache();
		$cache->set( 'a', [ 1 ], 60 );
		$cache->set( 'b', [ 2 ], 60 );
		$cache->clear();

		$this->assertNull( $cache->get( 'a' ) );
		$this->assertNull( $cache->get( 'b' ) );
	}

	public function testClearRemovesIndexOption(): void {
		global $wp_options;

		$cache = new TransientCache();
		$cache->set( 'x', [ 1 ], 60 );
		$this->assertNotEmpty( $wp_options['saltus_mcp_cache_keys'] ?? [] );

		$cache->clear();
		$this->assertArrayNotHasKey( 'saltus_mcp_cache_keys', $wp_options );
	}
}
