<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Core;
use Saltus\WP\Framework\Features\MCP\MCP;
use Saltus\WP\Framework\MCP\Abilities\AbilityRegistrar;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

class MCPFeatureTest extends TestCase {

	protected function setUp(): void {
		global $wp_actions_registered;
		$wp_actions_registered = [];
	}

	public function testNativeTransportRegistersWordPressAbilityHooks(): void {
		global $wp_actions_registered;

		$feature = new MCP( [], new NativeAbilityRegistrar() );
		$feature->register();

		$this->assertSame( 'native', $feature->transport() );
		$this->assertCount( 2, $wp_actions_registered );
		$this->assertSame( 'wp_abilities_api_categories_init', $wp_actions_registered[0]['hook_name'] );
		$this->assertSame( 'wp_abilities_api_init', $wp_actions_registered[1]['hook_name'] );
	}

	public function testLegacyTransportDoesNotRegisterNativeAbilityHooks(): void {
		global $wp_actions_registered;

		$feature = new MCP( [], new LegacyAbilityRegistrar() );
		$feature->register();

		$this->assertSame( 'legacy', $feature->transport() );
		$this->assertSame( [], $wp_actions_registered );
	}

	public function testMcpFeatureIsEnabledByDefault(): void {
		$core = new CoreWithPublicServices( __DIR__ );

		$this->assertArrayHasKey( 'mcp', $core->serviceClasses() );
		$this->assertSame( MCP::class, $core->serviceClasses()['mcp'] );
	}
}

class NativeAbilityRegistrar extends AbilityRegistrar {
	public function has_native_api(): bool {
		return true;
	}
}

class LegacyAbilityRegistrar extends AbilityRegistrar {
	public function has_native_api(): bool {
		return false;
	}
}

class CoreWithPublicServices extends Core {
	public function serviceClasses(): array {
		return $this->get_service_classes();
	}
}
