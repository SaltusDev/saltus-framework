<?php

namespace Saltus\WP\Framework\Tests\Integration;

use Saltus\WP\Framework\Core;
use Saltus\WP\Framework\Features\MCP\MCP;
use Saltus\WP\Framework\Models\Config\ConfigValidationContributor;
use Saltus\WP\Framework\Tests\TestCase;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\Core
 */
class FrameworkBootTest extends TestCase {
	public function testCoreRegistersDefaultServices(): void {
		$core = new Core( __DIR__ );

		$core->register_services();

		$container = $core->get_container();

		$this->assertTrue( $container->has( 'mcp' ) );
		$this->assertInstanceOf( MCP::class, $container->get( 'mcp' ) );
		$this->assertGreaterThan( 1, count( $container ) );
	}

	/**
	 * A subclass's service list must be the one that registers.
	 *
	 * `get_service_classes()` was briefly `public static`, invoked via `self::`, so
	 * an override was either a fatal signature clash or silently skipped — a
	 * consumer's customised services vanished with no error. Reverting to `self::`
	 * or to a static method fails this.
	 */
	public function testASubclassServiceListReplacesTheDefaultOne(): void {
		$core = new CoreWithOneService( __DIR__ );

		$core->register_services();

		$container = $core->get_container();

		$this->assertTrue( $container->has( 'mcp' ), 'The subclass list must be the one registered.' );
		$this->assertFalse( $container->has( 'relationships' ), 'A default the subclass dropped must not register.' );
	}

	/**
	 * Config contributors must be collected during the unconditional first pass.
	 *
	 * Registration is what makes a contributed rule run at all. A feature that
	 * implements the contract but never reaches the registry validates nothing,
	 * and the config still reports valid — a silent hole rather than a failure.
	 * Deleting the `maybe_register_config_contributor()` call fails this.
	 */
	public function testCoreCollectsConfigValidationContributors(): void {
		$core = new Core( __DIR__ );

		$core->register_services();

		$contributors = $core->get_config_contributors();

		$this->assertNotSame( [], $contributors, 'At least one feature must contribute config rules.' );

		foreach ( $contributors as $contributor ) {
			$this->assertInstanceOf( ConfigValidationContributor::class, $contributor );
			$this->assertNotSame( '', $contributor->get_config_section(), 'A contributor must name its section.' );
		}
	}

	/** Each section may have exactly one owner, or one feature's rules stop running. */
	public function testNoTwoContributorsClaimTheSameSection(): void {
		$core = new Core( __DIR__ );

		$core->register_services();

		$sections = array_map(
			static fn( ConfigValidationContributor $c ): string => $c->get_config_section(),
			$core->get_config_contributors()
		);

		$this->assertSame( array_unique( $sections ), $sections, 'Two features claim the same config section.' );
	}

	public function testCoreRegistersLifecycleHooksAgainstPluginFile(): void {
		global $wp_activation_hooks, $wp_deactivation_hooks;
		$wp_activation_hooks   = [];
		$wp_deactivation_hooks = [];
		$plugin_file           = __FILE__;

		$core = new Core( __DIR__, $plugin_file );
		$core->register();

		$this->assertSame( $plugin_file, $wp_activation_hooks[0]['file'] );
		$this->assertSame( $plugin_file, $wp_deactivation_hooks[0]['file'] );
	}

	public function testCoreSkipsLifecycleHooksWhenPluginFileIsDirectory(): void {
		global $wp_activation_hooks, $wp_deactivation_hooks;
		$wp_activation_hooks   = [];
		$wp_deactivation_hooks = [];

		$core = new Core( __DIR__ );
		$core->register();

		$this->assertSame( [], $wp_activation_hooks );
		$this->assertSame( [], $wp_deactivation_hooks );
	}
}

/** A consumer subclass narrowing the framework's service list, as the docblock promises. */
class CoreWithOneService extends Core {

	/**
	 * @return array<string, class-string>
	 */
	protected function get_service_classes(): array {
		return [ 'mcp' => MCP::class ];
	}
}
