<?php

namespace Saltus\WP\Framework\Tests\Rest;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Relationships\RelationshipManager;
use Saltus\WP\Framework\Features\Relationships\RelationshipRegistry;
use Saltus\WP\Framework\Features\Relationships\RelationshipStore;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Rest\RelationshipsController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

require_once __DIR__ . '/functions.php';

/**
 * @covers \Saltus\WP\Framework\Rest\RelationshipsController
 */
class RelationshipsControllerTest extends TestCase {

	protected function setUp(): void {
		global $wp_rest_routes_registered, $wp_current_user_can, $wp_posts, $wp_post_type_objects;
		$wp_rest_routes_registered = [];
		$wp_current_user_can       = true;
		$wp_posts                  = [];
		$wp_post_type_objects      = [];
	}

	/**
	 * Build a controller over a movie/person relationship pair.
	 *
	 * @param array<string, mixed> $movie_config Extra config for the movie model.
	 */
	private function controller( array $movie_config = [], bool $with_policy = true ): RelationshipsController {
		$models  = [
			'movie'  => $this->model(
				'movie',
				array_merge(
					[
						'relationships' => [
							'actors'   => [
								'type'       => 'has_many',
								'model'      => 'person',
								'reciprocal' => 'acted_in',
								'meta'       => [ 'role' => [ 'type' => 'text' ] ],
							],
							'director' => [
								'type'  => 'has_one',
								'model' => 'person',
							],
						],
					],
					$movie_config
				)
			),
			'person' => $this->model( 'person', [] ),
		];
		$modeler = new RestRelationshipModeler( $models );
		$manager = new RelationshipManager( new RelationshipRegistry( $modeler ), new RelationshipStore( null ) );

		return new RelationshipsController( $modeler, $with_policy ? new ModelRestPolicy( $modeler ) : null, $manager );
	}

	/**
	 * Minimal post-type model exposing options and config.
	 *
	 * @param array<string, mixed> $config Raw model configuration.
	 */
	private function model( string $name, array $config ): Model {
		$model = $this->createStub( Model::class );
		$model->method( 'get_name' )->willReturn( $name );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_options' )->willReturn( [ 'show_in_rest' => true ] );
		$model->method( 'get_args' )->willReturn( [] );
		$model->method( 'get_config' )->willReturn( $config );

		return $model;
	}

	private function seed_post( int $post_id, string $post_type ): void {
		global $wp_posts;
		$post                 = new \WP_Post(
			[
				'post_type'  => $post_type,
				'post_title' => $post_type . '-' . $post_id,
			]
		);
		$post->ID             = $post_id;
		$wp_posts[ $post_id ] = $post;
	}

	/** @param array<string, mixed> $params */
	private function request( array $params ): WP_REST_Request {
		return new WP_REST_Request( $params );
	}

	public function testConstructorSetsNamespaceAndRestBase(): void {
		$controller = $this->controller();

		$this->assertSame( 'saltus-framework/v1', $this->protected_property( $controller, 'namespace' ) );
		$this->assertSame( 'relationships', $this->protected_property( $controller, 'rest_base' ) );
	}

	/** @return mixed */
	private function protected_property( object $target, string $property ) {
		$reflection = new \ReflectionProperty( $target, $property );
		$reflection->setAccessible( true );

		return $reflection->getValue( $target );
	}

	public function testRegisterRoutesCoversDiscoveryReadAndEveryWriteVerb(): void {
		global $wp_rest_routes_registered;

		$this->controller()->register_routes();

		$this->assertCount( 3, $wp_rest_routes_registered );

		$routes = array_column( $wp_rest_routes_registered, 'route' );
		$this->assertSame( '/relationships/(?P<post_type>[a-z0-9_-]+)', $routes[0] );
		$this->assertStringContainsString( '/posts/(?P<post_id>\d+)/relationships/', $routes[1] );
		$this->assertStringEndsWith( '/(?P<related_id>\d+)', $routes[2] );

		// The per-post route carries GET, POST, and PUT as separate entries.
		$this->assertSame( [ 'GET', 'POST', 'PUT' ], array_column( $wp_rest_routes_registered[1]['args'], 'methods' ) );
		$this->assertSame( 'DELETE', $wp_rest_routes_registered[2]['args']['methods'] );
	}

	public function testGetDefinitionsListsDeclaredRelationships(): void {
		$response = $this->controller()->get_definitions( $this->request( [ 'post_type' => 'movie' ] ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( 'movie', $data['post_type'] );
		$this->assertSame( [ 'actors', 'director' ], array_column( $data['relationships'], 'name' ) );
	}

	public function testGetDefinitionsIsRefusedWhenTheModelDisablesRelationshipsInRest(): void {
		$controller = $this->controller( [ 'relationships' => [ 'show_in_rest' => false ] ] );

		$result = $controller->get_definitions( $this->request( [ 'post_type' => 'movie' ] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'model_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function testAttachThenReadBackThroughTheRestSurface(): void {
		$controller = $this->controller();
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'person' );

		$attached = $controller->attach_item(
			$this->request(
				[
					'post_id'      => 1,
					'relationship' => 'actors',
					'related_id'   => 2,
					'pivot'        => [ 'role' => 'Lead' ],
				]
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $attached );
		$this->assertTrue( $attached->get_data()['attached'] );

		$read = $controller->get_items( $this->request( [ 'post_id' => 1, 'relationship' => 'actors' ] ) );
		$this->assertInstanceOf( WP_REST_Response::class, $read );
		$related = $read->get_data()['related'];
		$this->assertSame( [ 2 ], array_column( $related, 'post_id' ) );
		$this->assertSame( [ 'role' => 'Lead' ], $related[0]['pivot'] );
	}

	public function testSyncReplacesTheSetAndDetachRemovesOnePair(): void {
		$controller = $this->controller();
		$this->seed_post( 1, 'movie' );
		foreach ( [ 2, 3, 4 ] as $person_id ) {
			$this->seed_post( $person_id, 'person' );
		}

		$synced = $controller->sync_items(
			$this->request(
				[
					'post_id'      => 1,
					'relationship' => 'actors',
					'related_ids'  => [ 4, 2 ],
				]
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $synced );
		$this->assertSame( [ 4, 2 ], $synced->get_data()['related_ids'] );

		$detached = $controller->detach_item(
			$this->request(
				[
					'post_id'      => 1,
					'relationship' => 'actors',
					'related_id'   => 4,
				]
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $detached );
		$this->assertTrue( $detached->get_data()['detached'] );

		$read = $controller->get_items( $this->request( [ 'post_id' => 1, 'relationship' => 'actors' ] ) );
		$this->assertSame( [ 2 ], array_column( $read->get_data()['related'], 'post_id' ) );
	}

	public function testSyncRejectsAPayloadThatIsNotAList(): void {
		$controller = $this->controller();
		$this->seed_post( 1, 'movie' );

		$result = $controller->sync_items(
			$this->request(
				[
					'post_id'      => 1,
					'relationship' => 'actors',
					'related_ids'  => 'nope',
				]
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'saltus_relationship_invalid_payload', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function testRequestsForAMissingPostOrUnknownRelationshipAreRefused(): void {
		$controller = $this->controller();
		$this->seed_post( 1, 'movie' );

		$missing_post = $controller->get_items( $this->request( [ 'post_id' => 999, 'relationship' => 'actors' ] ) );
		$this->assertInstanceOf( WP_Error::class, $missing_post );
		$this->assertSame( 'rest_post_invalid_id', $missing_post->get_error_code() );

		$unknown = $controller->get_items( $this->request( [ 'post_id' => 1, 'relationship' => 'nope' ] ) );
		$this->assertInstanceOf( WP_Error::class, $unknown );
		$this->assertSame( 'saltus_relationship_not_found', $unknown->get_error_code() );
		$this->assertSame( 404, $unknown->get_error_data()['status'] );
	}

	public function testCardinalityErrorsSurfaceThroughTheRestSurface(): void {
		$controller = $this->controller();
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'person' );
		$this->seed_post( 3, 'person' );

		$controller->attach_item( $this->request( [ 'post_id' => 1, 'relationship' => 'director', 'related_id' => 2 ] ) );
		$rejected = $controller->attach_item( $this->request( [ 'post_id' => 1, 'relationship' => 'director', 'related_id' => 3 ] ) );

		$this->assertInstanceOf( WP_Error::class, $rejected );
		$this->assertSame( 'saltus_relationship_cardinality', $rejected->get_error_code() );
		$this->assertSame( 409, $rejected->get_error_data()['status'] );
	}

	public function testWritePermissionIsCheckedAgainstTheSpecificPost(): void {
		global $wp_current_user_can;
		$controller = $this->controller();
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'movie' );

		$wp_current_user_can = [
			'edit_posts'    => true,
			'edit_post:1'   => true,
			'edit_post:2'   => false,
		];

		$this->assertTrue( $controller->update_item_permissions_check( $this->request( [ 'post_id' => 1 ] ) ) );

		$refused = $controller->update_item_permissions_check( $this->request( [ 'post_id' => 2 ] ) );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'rest_forbidden', $refused->get_error_code() );
		$this->assertSame( 403, $refused->get_error_data()['status'] );
		$this->assertSame( 2, $refused->get_error_data()['post_id'] );
	}

	public function testWritePermissionIsRefusedWithoutAPostId(): void {
		$refused = $this->controller()->update_item_permissions_check( $this->request( [] ) );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'rest_forbidden', $refused->get_error_code() );
	}

	public function testReadPermissionsFollowThePostTypeEditCapability(): void {
		global $wp_current_user_can;
		$controller = $this->controller();
		$this->seed_post( 1, 'movie' );

		$wp_current_user_can = [ 'edit_posts' => true ];
		$this->assertTrue( $controller->get_items_permissions_check( $this->request( [ 'post_id' => 1 ] ) ) );
		$this->assertTrue( $controller->get_definitions_permissions_check( $this->request( [ 'post_type' => 'movie' ] ) ) );

		$wp_current_user_can = [ 'edit_posts' => false ];
		$refused             = $controller->get_definitions_permissions_check( $this->request( [ 'post_type' => 'movie' ] ) );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'rest_forbidden', $refused->get_error_code() );

		$refused_read = $controller->get_items_permissions_check( $this->request( [ 'post_id' => 1 ] ) );
		$this->assertInstanceOf( WP_Error::class, $refused_read );
	}

	public function testShowInRestFalseGatesRoutesWithoutBecomingARelationship(): void {
		// Documented gating form: the flag sits alongside real declarations in
		// the same section, so it must gate the routes without being parsed as
		// a relationship named "show_in_rest".
		$controller = $this->controller(
			[
				'relationships' => [
					'show_in_rest' => false,
					'actors'       => [
						'type'  => 'has_many',
						'model' => 'person',
					],
				],
			]
		);

		$refused = $controller->get_definitions( $this->request( [ 'post_type' => 'movie' ] ) );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'model_not_found', $refused->get_error_code() );

		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'person' );
		$blocked = $controller->attach_item(
			$this->request( [ 'post_id' => 1, 'relationship' => 'actors', 'related_id' => 2 ] )
		);
		$this->assertInstanceOf( WP_Error::class, $blocked );
		$this->assertSame( 'model_not_found', $blocked->get_error_code() );
	}

	public function testGatingFlagIsNotRegisteredAsARelationshipName(): void {
		$modeler = new RestRelationshipModeler(
			[
				'movie'  => $this->model(
					'movie',
					[
						'relationships' => [
							'show_in_rest' => false,
							'actors'       => [
								'type'  => 'has_many',
								'model' => 'person',
							],
						],
					]
				),
				'person' => $this->model( 'person', [] ),
			]
		);
		$manager = new RelationshipManager( new RelationshipRegistry( $modeler ), new RelationshipStore( null ) );

		$this->assertSame( [ 'actors' ], array_column( $manager->describe( 'movie' ), 'name' ) );
		$this->assertNull( $manager->get_definition( 'movie', 'show_in_rest' ) );
	}

	public function testWritesAreAllowedWhenNoPolicyGatesTheModel(): void {
		$controller = $this->controller( [], false );
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'person' );

		$attached = $controller->attach_item(
			$this->request( [ 'post_id' => 1, 'relationship' => 'actors', 'related_id' => 2 ] )
		);

		$this->assertInstanceOf( WP_REST_Response::class, $attached );
	}
}

/** Modeler double returning a fixed model list. */
class RestRelationshipModeler extends Modeler {

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
