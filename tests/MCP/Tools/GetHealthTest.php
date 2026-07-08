<?php

namespace Saltus\WP\Framework\Tests\MCP\Tools;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Tools\GetHealth;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use WP_REST_Request;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\MCP\Tools\GetHealth
 * @covers \Saltus\WP\Framework\MCP\Tools\RestTool
 */
class GetHealthTest extends TestCase {

	private GetHealth $tool;

	protected function setUp(): void {
		$this->tool = new GetHealth();
	}

	public function testBasicGetters(): void {
		$this->assertSame( 'get_health', $this->tool->get_name() );
		$this->assertStringContainsString( 'Get Saltus Framework health', $this->tool->get_description() );
		$this->assertSame( [], $this->tool->get_parameters() );
		$this->assertTrue( $this->tool->is_cacheable() );
		$this->assertSame( 60, $this->tool->cache_ttl() );

		$capability = $this->tool->get_rest_capability();
		$this->assertNotNull( $capability );
		$this->assertSame( ModelRestPolicy::CAPABILITY_HEALTH, $capability->get_capability() );
	}

	public function testBuildRestRequest(): void {
		$request = $this->tool->build_rest_request( [] );

		$this->assertInstanceOf( WP_REST_Request::class, $request );
		$this->assertSame( 'GET', $request->get_method() );
		$this->assertSame( '/saltus-framework/v1/health', $request->get_route() );
	}

	public function testHasPermission(): void {
		global $wp_current_user_can;

		$wp_current_user_can = true;
		$this->assertTrue( $this->tool->has_permission( [] ) );

		$wp_current_user_can = false;
		$this->assertFalse( $this->tool->has_permission( [] ) );
	}
}
