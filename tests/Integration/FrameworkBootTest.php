<?php

namespace Saltus\WP\Framework\Tests\Integration;

use Saltus\WP\Framework\Core;
use Saltus\WP\Framework\Features\MCP\MCP;
use Saltus\WP\Framework\Tests\TestCase;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

class FrameworkBootTest extends TestCase {
	public function testCoreRegistersDefaultServices(): void {
		$core = new Core( __DIR__ );

		$core->register_services();

		$container = $core->get_container();

		$this->assertTrue( $container->has( 'mcp' ) );
		$this->assertInstanceOf( MCP::class, $container->get( 'mcp' ) );
		$this->assertGreaterThan( 1, count( $container ) );
	}
}
