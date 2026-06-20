<?php

namespace Saltus\WP\Framework\Tests\Rest;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Rest\RestServer;
use Saltus\WP\Framework\Modeler;

require_once __DIR__ . '/functions.php';

class RestServerTest extends TestCase {
	private RestServer $server;
	private Modeler $modeler;

	protected function setUp(): void {
		global $wp_rest_routes_registered;
		$wp_rest_routes_registered = [];

		$this->modeler = $this->createMock( Modeler::class );
		$this->server  = new RestServer( $this->modeler );
	}

	public function testRegisterRoutesRegistersAllControllerRoutes(): void {
		global $wp_rest_routes_registered;

		$this->server->register_routes();

		$routes = array_map(
			fn( $r ) => $r['route'],
			$wp_rest_routes_registered
		);

		$expectedPatterns = [ 'models', 'duplicate', 'export', 'settings', 'meta', 'reorder' ];

		foreach ( $expectedPatterns as $pattern ) {
			$found = false;
			foreach ( $routes as $route ) {
				if ( str_contains( $route, $pattern ) ) {
					$found = true;
					break;
				}
			}
			$this->assertTrue( $found, "Route containing '{$pattern}' was not registered." );
		}
	}

	public function testRegisterRoutesRegistersMoreThanOneRoute(): void {
		global $wp_rest_routes_registered;

		$this->server->register_routes();

		$this->assertGreaterThan( 1, count( $wp_rest_routes_registered ) );
	}
}
