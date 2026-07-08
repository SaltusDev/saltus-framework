<?php

namespace Saltus\WP\Framework\Tests\Integration;

use Saltus\WP\Framework\Core;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Models\ModelFactory;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Rest\RestRouteDefinition;
use Saltus\WP\Framework\Rest\DuplicateController;
use Saltus\WP\Framework\Rest\ExportController;
use Saltus\WP\Framework\Rest\HealthController;
use Saltus\WP\Framework\Rest\MetaController;
use Saltus\WP\Framework\Rest\ModelsController;
use Saltus\WP\Framework\Rest\ReorderController;
use Saltus\WP\Framework\Rest\SettingsController;
use Saltus\WP\Framework\Tests\TestCase;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\Core
 * @covers \Saltus\WP\Framework\Rest\ModelRestPolicy
 * @covers \Saltus\WP\Framework\Rest\RestRouteDefinition
 * @covers \Saltus\WP\Framework\Rest\HealthController
 */
class RestRegistrationTest extends TestCase {

	public function testCoreProducesRouteDefinitionsForAllCapabilities(): void {
		$plugin_file = dirname( __DIR__, 2 ) . '/vendor/saltus/framework/saltus-framework.php';

		$core   = new Core( __DIR__, $plugin_file );
		$core->register_services();

		$modeler = $this->createMock( Modeler::class );
		$modeler->method( 'get_rest_routes' )->willReturn( [] );
		$modeler_prop = new \ReflectionProperty( $core, 'modeler' );
		$modeler_prop->setAccessible( true );
		$modeler_prop->setValue( $core, $modeler );

		$policy      = new ModelRestPolicy( $modeler );
		$reflection  = new \ReflectionMethod( $core, 'get_rest_routes' );
		$reflection->setAccessible( true );
		$routes      = $reflection->invoke( $core, $policy );

		$this->assertContainsOnlyInstancesOf( RestRouteDefinition::class, $routes );
		$this->assertGreaterThanOrEqual( 1, count( $routes ) );

		$capabilities = array_map(
			static fn( RestRouteDefinition $route ): string => $route->get_capability(),
			$routes
		);
		$this->assertContains( ModelRestPolicy::CAPABILITY_HEALTH, $capabilities );
	}

	public function testModelerContributesModelsRestRoute(): void {
		$modeler = $this->createMock( Modeler::class );
		$modeler->method( 'get_rest_routes' )
			->willReturn( [
				new RestRouteDefinition(
					ModelRestPolicy::CAPABILITY_MODELS,
					new ModelsController( $modeler, new ModelRestPolicy( $modeler ) ),
				),
			] );
		$policy  = new ModelRestPolicy( $modeler );

		$routes = $modeler->get_rest_routes( $modeler, $policy );

		$this->assertCount( 1, $routes );
		$this->assertSame( ModelRestPolicy::CAPABILITY_MODELS, $routes[0]->get_capability() );
	}

	public function testRestRouteDefinitionRegistersControllerRoutes(): void {
		$controller = $this->createMock( HealthController::class );
		$controller->expects( $this->once() )->method( 'register_routes' );

		$definition = new RestRouteDefinition( 'health', $controller );
		$definition->register_routes();
	}

	public function testRestRouteDefinitionSkipsRegistrationWhenMethodMissing(): void {
		$controller = new \stdClass();
		$definition = new RestRouteDefinition( 'health', $controller );

		$this->assertNull( $definition->register_routes() );
	}

	public function testHealthRouteIsAlwaysIncluded(): void {
		$modeler = $this->createMock( Modeler::class );
		$policy  = new ModelRestPolicy( $modeler );

		$this->assertTrue( $policy->has_capability( ModelRestPolicy::CAPABILITY_HEALTH ) );
	}

	public function testIsEnabledHandlesMissingFeaturesConfigDefensively(): void {
		$modeler = $this->createMock( Modeler::class );
		$policy  = new ModelRestPolicy( $modeler );
		$model   = $this->createMock( Model::class );
		$model->method( 'get_options' )->willReturn( [ 'show_in_rest' => true ] );
		$model->method( 'get_config' )->willReturn( [] );

		$this->assertTrue( $policy->is_enabled( $model, ModelRestPolicy::CAPABILITY_EXPORT ) );
	}

	public function testIsEnabledHandlesNonArrayFeaturesConfigDefensively(): void {
		$modeler = $this->createMock( Modeler::class );
		$policy  = new ModelRestPolicy( $modeler );
		$model   = $this->createMock( Model::class );
		$model->method( 'get_options' )->willReturn( [ 'show_in_rest' => true ] );
		$model->method( 'get_config' )->willReturn( [ 'features' => null ] );

		$this->assertTrue( $policy->is_enabled( $model, ModelRestPolicy::CAPABILITY_EXPORT ) );
	}

	public function testIsEnabledHandlesExplicitDisable(): void {
		$modeler = $this->createMock( Modeler::class );
		$policy  = new ModelRestPolicy( $modeler );
		$model   = $this->createMock( Model::class );
		$model->method( 'get_options' )->willReturn( [ 'show_in_rest' => true ] );
		$model->method( 'get_config' )->willReturn( [ 'meta' => false ] );

		$this->assertFalse( $policy->is_enabled( $model, ModelRestPolicy::CAPABILITY_META ) );
	}

	public function testHealthControllerImplementsRegisterRoutes(): void {
		$controller = new HealthController( '1.0.0' );
		$this->assertTrue( method_exists( $controller, 'register_routes' ) );
	}
}
