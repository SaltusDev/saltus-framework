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

	public function testCoreRegistersLifecycleHooksAgainstPluginFile(): void {
		global $wp_activation_hooks, $wp_deactivation_hooks;
		$wp_activation_hooks   = [];
		$wp_deactivation_hooks = [];
		$plugin_file           = __DIR__ . '/saltus-plugin.php';

		$core = new Core( __DIR__, $plugin_file );
		$core->register();

		$this->assertSame( $plugin_file, $wp_activation_hooks[0]['file'] );
		$this->assertSame( $plugin_file, $wp_deactivation_hooks[0]['file'] );
	}
}
