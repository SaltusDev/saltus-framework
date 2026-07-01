<?php

namespace Saltus\WP\Framework\Tests\Rest;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Rest\DuplicateController;
use Saltus\WP\Framework\Rest\ExportController;
use Saltus\WP\Framework\Rest\MetaController;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Rest\ModelsController;
use Saltus\WP\Framework\Rest\ReorderController;
use Saltus\WP\Framework\Rest\RestRouteDefinition;
use Saltus\WP\Framework\Rest\RestServer;
use Saltus\WP\Framework\Rest\SettingsController;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;

require_once __DIR__ . '/functions.php';

class RestServerTest extends TestCase {
	private Modeler $modeler;

	protected function setUp(): void {
		global $wp_rest_routes_registered;
		$wp_rest_routes_registered = [];

		$this->modeler = $this->createStub( Modeler::class );
	}

	public function testRegisterRoutesRegistersAllControllerRoutes(): void {
		global $wp_rest_routes_registered;

		$this->modeler->method( 'get_models' )->willReturn(
			[
				'book' => $this->createModelMock(
					'post_type',
					[
						'show_in_rest' => true,
						'saltus_rest'  => true,
					]
				),
			]
		);

		$this->createServer()->register_routes();

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

		$this->modeler->method( 'get_models' )->willReturn(
			[
				'book' => $this->createModelMock(
					'post_type',
					[
						'show_in_rest' => true,
						'saltus_rest'  => [
							'models'   => true,
							'settings' => true,
						],
					]
				),
			]
		);

		$this->createServer()->register_routes();

		$this->assertGreaterThan( 1, count( $wp_rest_routes_registered ) );
	}

	public function testRegisterRoutesRegistersNoRoutesWithoutOptIn(): void {
		global $wp_rest_routes_registered;

		$this->modeler->method( 'get_models' )->willReturn(
			[
				'book' => $this->createModelMock( 'post_type', [ 'show_in_rest' => true ] ),
			]
		);

		$this->createServer()->register_routes();

		$this->assertSame( [], $wp_rest_routes_registered );
	}

	public function testRegisterRoutesRespectsShowInRestFalse(): void {
		global $wp_rest_routes_registered;

		$this->modeler->method( 'get_models' )->willReturn(
			[
				'book' => $this->createModelMock(
					'post_type',
					[
						'show_in_rest' => false,
						'saltus_rest'  => true,
					]
				),
			]
		);

		$this->createServer()->register_routes();

		$this->assertSame( [], $wp_rest_routes_registered );
	}

	/**
	 * @return Model&object{options: array<string, mixed>}
	 */
	private function createModelMock( string $type, array $options ) {
		return new class( $type, $options ) implements Model {
			private string $type;
			/** @var array<string, mixed> */
			public array $options;

			/**
			 * @param array<string, mixed> $options
			 */
			public function __construct( string $type, array $options ) {
				$this->type    = $type;
				$this->options = $options;
			}

			public function setup(): void {}

			public function get_name(): string {
				return '';
			}

			public function get_type(): string {
				return $this->type;
			}
		};
	}

	private function createServer(): RestServer {
		$policy = new ModelRestPolicy( $this->modeler );

		return new RestServer(
			$policy,
			[
				new RestRouteDefinition(
					ModelRestPolicy::CAPABILITY_MODELS,
					new ModelsController( $this->modeler, $policy )
				),
				new RestRouteDefinition(
					ModelRestPolicy::CAPABILITY_DUPLICATE,
					new DuplicateController( $policy ),
					'post_type'
				),
				new RestRouteDefinition(
					ModelRestPolicy::CAPABILITY_EXPORT,
					new ExportController( $policy ),
					'post_type'
				),
				new RestRouteDefinition(
					ModelRestPolicy::CAPABILITY_SETTINGS,
					new SettingsController( $policy ),
					'post_type'
				),
				new RestRouteDefinition(
					ModelRestPolicy::CAPABILITY_META,
					new MetaController( $this->modeler, $policy ),
					'post_type'
				),
				new RestRouteDefinition(
					ModelRestPolicy::CAPABILITY_REORDER,
					new ReorderController( $policy ),
					'post_type'
				),
			]
		);
	}
}
