<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Relationships\RelationshipManager;
use Saltus\WP\Framework\Features\Relationships\RelationshipRegistry;
use Saltus\WP\Framework\Features\Relationships\RelationshipStore;
use Saltus\WP\Framework\Features\Relationships\Relationships;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Rest\RelationshipsController;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\Features\Relationships\Relationships
 */
class RelationshipsServiceTest extends TestCase {

	protected function setUp(): void {
		global $wp_posts, $wp_actions_registered;
		$wp_posts              = [];
		$wp_actions_registered = [];
	}

	/**
	 * Model list for a movie declaring a cascading and a plain relationship.
	 *
	 * @return array<string, Model>
	 */
	private function models(): array {
		return [
			'movie'  => $this->model(
				'movie',
				[
					'actors'  => [
						'type'  => 'has_many',
						'model' => 'person',
					],
					'reviews' => [
						'type'           => 'has_many',
						'model'          => 'review',
						'cascade_delete' => true,
					],
				]
			),
			'person' => $this->model( 'person', [] ),
			'review' => $this->model( 'review', [] ),
		];
	}

	/** @param array<string, mixed> $relationships */
	private function model( string $name, array $relationships ): Model {
		$model = $this->createStub( Model::class );
		$model->method( 'get_name' )->willReturn( $name );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_options' )->willReturn( [ 'show_in_rest' => true ] );
		$model->method( 'get_args' )->willReturn( [] );
		$model->method( 'get_config' )->willReturn( [ 'relationships' => $relationships ] );

		return $model;
	}

	private function seed_post( int $post_id, string $post_type ): void {
		global $wp_posts;
		$post                 = new \WP_Post( [ 'post_type' => $post_type ] );
		$post->ID             = $post_id;
		$wp_posts[ $post_id ] = $post;
	}

	public function testRegisterHooksPostDeletionBeforeThePostRowIsGone(): void {
		global $wp_actions_registered;

		( new Relationships() )->register();

		$hooks = array_column( $wp_actions_registered, 'hook_name' );
		$this->assertContains(
			'before_delete_post',
			$hooks,
			'Cleanup must run while the post type is still resolvable.'
		);
	}

	public function testDeletingAPostClearsRowsAndCascadesThroughTheService(): void {
		$modeler = new ServiceRelationshipModeler( $this->models() );
		$store   = new RelationshipStore( null );
		$manager = new RelationshipManager( new RelationshipRegistry( $modeler ), $store );
		$service = new Relationships( [], $store, $manager );

		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'person' );
		$this->seed_post( 20, 'review' );
		$manager->attach( 1, 'movie', 'actors', 2 );
		$manager->attach( 1, 'movie', 'reviews', 20 );

		$service->clean_up_post( 1 );

		$this->assertSame( [], $store->get_all_for_post( 1 ), 'The deleted post keeps no relationship rows.' );
		$this->assertArrayNotHasKey( 20, $GLOBALS['wp_posts'], 'A cascading target is deleted with its owner.' );
		$this->assertArrayHasKey( 2, $GLOBALS['wp_posts'] );
	}

	public function testCleanUpIgnoresInvalidPostIds(): void {
		$modeler = new ServiceRelationshipModeler( $this->models() );
		$store   = new RelationshipStore( null );
		$service = new Relationships( [], $store, new RelationshipManager( new RelationshipRegistry( $modeler ), $store ) );

		$service->clean_up_post( 0 );
		$service->clean_up_post( -5 );

		$this->addToAssertionCount( 1 );
	}

	public function testCleanUpIsANoOpBeforeModelsAreLoaded(): void {
		$service = new Relationships();

		$this->assertNull( $service->manager(), 'No manager can exist before the model registry resolves.' );

		$service->clean_up_post( 1 );

		$this->addToAssertionCount( 1 );
	}

	public function testManagerIsBuiltOnceTheModelResolverReturnsAModeler(): void {
		$modeler = new ServiceRelationshipModeler( $this->models() );
		$service = new Relationships(
			[
				'modeler_resolver' => static function () use ( $modeler ): Modeler {
					return $modeler;
				},
			],
			new RelationshipStore( null )
		);

		$manager = $service->manager();

		$this->assertInstanceOf( RelationshipManager::class, $manager );
		$this->assertSame( $manager, $service->manager(), 'The manager is shared, not rebuilt per call.' );
		$this->assertSame( [ 'actors', 'reviews' ], array_column( $manager->describe( 'movie' ), 'name' ) );
	}

	public function testServiceContributesTheRelationshipsRestRoute(): void {
		$modeler = new ServiceRelationshipModeler( $this->models() );
		$service = new Relationships( [], new RelationshipStore( null ) );

		$routes = $service->get_rest_routes( $modeler, new ModelRestPolicy( $modeler ) );

		$this->assertCount( 1, $routes );
		$this->assertSame( ModelRestPolicy::CAPABILITY_RELATIONSHIPS, $routes[0]->get_capability() );
		$this->assertSame( 'post_type', $routes[0]->get_model_type() );

		$controller = new \ReflectionProperty( $routes[0], 'controller' );
		$controller->setAccessible( true );
		$this->assertInstanceOf( RelationshipsController::class, $controller->getValue( $routes[0] ) );
	}

	public function testServiceContributesEveryRelationshipMcpTool(): void {
		$modeler = new ServiceRelationshipModeler( $this->models() );
		$service = new Relationships( [], new RelationshipStore( null ) );

		$names = [];
		foreach ( $service->get_mcp_tools( $modeler, new ModelRestPolicy( $modeler ) ) as $tool ) {
			$names[] = $tool->get_name();
		}

		$this->assertSame(
			[ 'list_relationships', 'get_related', 'attach_related', 'detach_related', 'sync_related' ],
			$names
		);
	}

	public function testRestRoutesAndToolsShareOneManagerInstance(): void {
		$modeler = new ServiceRelationshipModeler( $this->models() );
		$service = new Relationships( [], new RelationshipStore( null ) );

		$service->get_rest_routes( $modeler, new ModelRestPolicy( $modeler ) );

		$this->assertInstanceOf(
			RelationshipManager::class,
			$service->manager(),
			'Building REST routes must leave the shared manager available to hooks.'
		);
	}

	public function testCoreRegistersTheRelationshipsService(): void {
		$reflection = new \ReflectionMethod( \Saltus\WP\Framework\Core::class, 'get_service_classes' );
		$reflection->setAccessible( true );
		$services = $reflection->invoke( ( new \ReflectionClass( \Saltus\WP\Framework\Core::class ) )->newInstanceWithoutConstructor() );

		$this->assertArrayHasKey( 'relationships', $services );
		$this->assertSame( Relationships::class, $services['relationships'] );
	}
}

/** Modeler double returning a fixed model list. */
class ServiceRelationshipModeler extends Modeler {

	/** @var array<string, Model> */
	private array $models;

	/** @param array<string, Model> $models */
	public function __construct( array $models ) {
		$this->models = $models;
	}

	/** @return array<string, Model> */
	public function get_models(): array {
		return $this->models;
	}
}
