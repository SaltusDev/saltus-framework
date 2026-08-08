<?php

namespace Saltus\WP\Framework\Tests\Rest;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Audit\AuditLogger;
use Saltus\WP\Framework\Rest\HealthController;
use WP_Error;

require_once __DIR__ . '/functions.php';

/**
 * @covers \Saltus\WP\Framework\Rest\HealthController
 */
class HealthControllerTest extends TestCase {
	private HealthController $controller;

	protected function setUp(): void {
		global $wp_current_user_can, $wp_filter_values, $wp_rest_routes_registered;

		$wp_current_user_can       = true;
		$wp_filter_values          = [];
		$wp_rest_routes_registered = [];

		$logger = $this->createStub( AuditLogger::class );
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
				[
					'status'      => 'validation_error',
					'duration_ms' => 2.0,
				],
				[
					'status'      => 'rate_limited',
					'duration_ms' => 1.0,
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
		$this->assertIsBool( $data['ai']['client_available'] );
		$this->assertIsBool( $data['ai']['connectors_available'] );
		$this->assertSame( 5, $data['audit']['sample_size'] );
		$this->assertSame( 1, $data['audit']['error_count'] );
		$this->assertSame( 1 / 5, $data['audit']['error_rate'] );
		$this->assertSame( 9.4, $data['audit']['latency_ms']['average'] );
		$this->assertSame( 30.0, $data['audit']['latency_ms']['p95'] );
		$this->assertSame(
			[
				'cache_hit'        => 1,
				'error'            => 1,
				'rate_limited'     => 1,
				'success'          => 1,
				'validation_error' => 1,
			],
			$data['audit']['statuses']
		);
	}

	public function testClientFailuresDoNotDegradeHealth(): void {
		$logger = $this->createStub( AuditLogger::class );
		$logger->method( 'get_recent_entries' )->willReturn(
			[
				[ 'status' => 'success' ],
				[ 'status' => 'validation_error' ],
				[ 'status' => 'rate_limited' ],
			]
		);

		$controller = new HealthController( '2.0.0', $logger );
		$data       = $controller->get_item( null )->get_data();

		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 0, $data['audit']['error_count'] );
		$this->assertEqualsWithDelta( 0.0, $data['audit']['error_rate'], 0.0 );
		$this->assertSame(
			[
				'rate_limited'     => 1,
				'success'          => 1,
				'validation_error' => 1,
			],
			$data['audit']['statuses']
		);
	}

	public function testGetItemReportsOkWithoutAuditEntries(): void {
		$logger = $this->createStub( AuditLogger::class );
		$logger->method( 'get_recent_entries' )->willReturn( [] );

		$controller = new HealthController( '2.0.0', $logger );
		$data       = $controller->get_item( null )->get_data();

		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 0, $data['audit']['sample_size'] );
		$this->assertSame( 0.0, $data['audit']['error_rate'] );
		$this->assertNull( $data['audit']['latency_ms']['average'] );
		$this->assertNull( $data['audit']['latency_ms']['p95'] );
	}

	public function testWebMcpStateReportsUnavailableWithoutAPolicy(): void {
		$data = $this->controller->get_item( null )->get_data();

		$this->assertFalse( $data['webmcp']['available'] );
		$this->assertFalse( $data['webmcp']['frontend'] );
		$this->assertFalse( $data['webmcp']['admin'] );
		$this->assertSame( 0, $data['webmcp']['enabled_count'] );
	}

	public function testWebMcpStateReportsEnabledModelsPerSurface(): void {
		global $wp_post_type_objects;

		$logger = $this->createStub( AuditLogger::class );
		$logger->method( 'get_recent_entries' )->willReturn( [] );

		$public = $this->model( 'book', [ 'webmcp' => [ 'enabled' => true, 'frontend' => true, 'admin' => true ] ] );
		$hidden = $this->model( 'note', [ 'webmcp' => [ 'enabled' => true, 'admin' => true ] ] );

		$wp_post_type_objects['book'] = (object) [ 'name' => 'book', 'publicly_queryable' => true ];
		$wp_post_type_objects['note'] = (object) [ 'name' => 'note', 'publicly_queryable' => false ];

		$modeler = $this->createStub( \Saltus\WP\Framework\Modeler::class );
		$modeler->method( 'get_models' )->willReturn( [ 'book' => $public, 'note' => $hidden ] );

		$data = ( new HealthController( '2.0.0', $logger, new \Saltus\WP\Framework\Features\WebMcp\WebMcpPolicy( $modeler ) ) )
			->get_item( null )
			->get_data();

		// The two surfaces are reported separately: a private post type is a
		// legitimate admin target but must never appear on the frontend list.
		$this->assertTrue( $data['webmcp']['available'] );
		$this->assertSame( [ 'book' ], $data['webmcp']['models']['frontend'] );
		$this->assertSame( [ 'book', 'note' ], $data['webmcp']['models']['admin'] );
		$this->assertSame( 2, $data['webmcp']['enabled_count'] );
	}

	/**
	 * @param array<string, mixed> $config Model configuration.
	 */
	private function model( string $name, array $config ): \Saltus\WP\Framework\Models\Model {
		$model = $this->createStub( \Saltus\WP\Framework\Models\Model::class );
		$model->method( 'get_name' )->willReturn( $name );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_config' )->willReturn( $config );
		$model->method( 'get_args' )->willReturn( [ 'public' => true, 'publicly_queryable' => true ] );

		return $model;
	}

	private function getProtectedProperty( object $object, string $property ) {
		$reflection = new \ReflectionProperty( $object, $property );
		$reflection->setAccessible( true );
		return $reflection->getValue( $object );
	}
}
