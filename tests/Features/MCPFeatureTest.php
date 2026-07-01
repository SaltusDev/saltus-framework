<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Core;
use Saltus\WP\Framework\Features\MCP\MCP;
use Saltus\WP\Framework\MCP\Abilities\AbilityRegistrar;
use Saltus\WP\Framework\MCP\Tools\RestTool;
use Saltus\WP\Framework\MCP\Tools\ToolContributor;
use Saltus\WP\Framework\MCP\Tools\ToolInterface;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\ModelFactory;
use Saltus\WP\Framework\Rest\ModelRestPolicy;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

class MCPFeatureTest extends TestCase {

	protected function setUp(): void {
		global $wp_actions_registered, $wp_abilities_registered;
		$wp_actions_registered   = [];
		$wp_abilities_registered = [];
	}

	public function testNativeTransportRegistersWordPressAbilityHooks(): void {
		global $wp_actions_registered;

		$feature = new MCP( [], new NativeAbilityRegistrar() );
		$feature->register();

		$this->assertSame( 'native', $feature->transport() );
		$this->assertCount( 8, $wp_actions_registered );
		$this->assertSame( 'wp_abilities_api_categories_init', $wp_actions_registered[0]['hook_name'] );
		$this->assertSame( 'wp_abilities_api_init', $wp_actions_registered[1]['hook_name'] );
		$this->assertSame( 'save_post', $wp_actions_registered[2]['hook_name'] );
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

	public function testNativeRegistrationUsesToolContributorsFromDependencies(): void {
		global $wp_actions_registered, $wp_abilities_registered;

		$modeler = new Modeler( $this->createStub( ModelFactory::class ) );
		$feature = new MCP(
			[
				'modeler_resolver' => function () use ( $modeler ): Modeler {
					return $modeler;
				},
				'services'         => new \ArrayObject( [ new ContributorFeature() ] ),
			]
		);

		$feature->register();
		$wp_actions_registered[1]['callback']();

		$this->assertArrayHasKey( 'saltus/contributed-tool', $wp_abilities_registered );
		$this->assertSame( 'contributed_tool', $wp_abilities_registered['saltus/contributed-tool']['meta']['mcp_tool'] );
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

class ContributorFeature implements ToolContributor {
	/**
	 * @return list<ToolInterface>
	 */
	public function get_mcp_tools( Modeler $modeler, ?ModelRestPolicy $policy = null ): array {
		return [ new ContributedTool() ];
	}
}

class ContributedTool extends RestTool {
	public function get_name(): string {
		return 'contributed_tool';
	}

	public function get_description(): string {
		return 'A tool contributed by its feature';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_parameters(): array {
		return [];
	}

	public function build_rest_request( array $args ): ?\WP_REST_Request {
		return $this->request( 'GET', '/saltus-framework/v1/contributed-tool' );
	}
}
