<?php

namespace Saltus\WP\Framework\Tests\Rest;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Audit\AuditLogger;
use Saltus\WP\Framework\Rest\HealthController;
use WP_Error;

require_once __DIR__ . '/functions.php';

class HealthControllerTest extends TestCase {
	private HealthController $controller;

	protected function setUp(): void {
		global $wp_current_user_can, $wp_filter_values, $wp_rest_routes_registered;

		$wp_current_user_can       = true;
		$wp_filter_values          = [];
		$wp_rest_routes_registered = [];

		$logger = $this->createMock( AuditLogger::class );
		$logger->method( 'get_recent_entries' )->willReturn(
			[
				[
					'status'      => 'success',
					'duration_ms' => 10.0,
				],
				[
					'status'      => 'cache_hit',
					'duration_ms' => 4.0,
				],
				[
					'status'      => 'error',
					'duration_ms' => 30.0,
				],
			]
		);

		$this->controller = new HealthController( '2.0.0', $logger );
	}

	public function testConstructorSetsNamespaceAndRestBase(): void {
		$this->assertSame( 'saltus-framework/v1', $this->getProtectedProperty( $this->controller, 'namespace' ) );
		$this->assertSame( 'health', $this->getProtectedProperty( $this->controller, 'rest_base' ) );
	}

	public function testRegisterRoutesRegistersHealthEndpoint(): void {
		global $wp_rest_routes_registered;

		$this->controller->register_routes();

		$this->assertCount( 1, $wp_rest_routes_registered );
		$this->assertSame( 'saltus-framework/v1', $wp_rest_routes_registered[0]['namespace'] );
		$this->assertSame( '/health', $wp_rest_routes_registered[0]['route'] );
	}

	public function testPermissionRequiresEditPosts(): void {
		global $wp_current_user_can;

		$wp_current_user_can = false;

		$result = $this->controller->get_item_permissions_check( null );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	public function testGetItemReturnsAuditMetrics(): void {
		$data = $this->controller->get_item( null )->get_data();

		$this->assertSame( 'degraded', $data['status'] );
		$this->assertSame( '2.0.0', $data['version'] );
		$this->assertTrue( $data['abilities']['native_api_available'] );
		$this->assertSame( 3, $data['audit']['sample_size'] );
		$this->assertSame( 1, $data['audit']['error_count'] );
		$this->assertSame( 1 / 3, $data['audit']['error_rate'] );
		$this->assertSame( 14.666666666666666, $data['audit']['latency_ms']['average'] );
		$this->assertSame( 30.0, $data['audit']['latency_ms']['p95'] );
		$this->assertSame(
			[
				'cache_hit' => 1,
				'error'     => 1,
				'success'   => 1,
			],
			$data['audit']['statuses']
		);
	}

	public function testGetItemReportsOkWithoutAuditEntries(): void {
		$logger = $this->createMock( AuditLogger::class );
		$logger->method( 'get_recent_entries' )->willReturn( [] );

		$controller = new HealthController( '2.0.0', $logger );
		$data       = $controller->get_item( null )->get_data();

		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 0, $data['audit']['sample_size'] );
		$this->assertSame( 0.0, $data['audit']['error_rate'] );
		$this->assertNull( $data['audit']['latency_ms']['average'] );
		$this->assertNull( $data['audit']['latency_ms']['p95'] );
	}

	private function getProtectedProperty( object $object, string $property ): mixed {
		$reflection = new \ReflectionClass( $object );
		$property   = $reflection->getProperty( $property );
		$property->setAccessible( true );

		return $property->getValue( $object );
	}
}
