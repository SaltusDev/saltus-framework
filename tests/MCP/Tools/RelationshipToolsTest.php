<?php
namespace Saltus\WP\Framework\Tests\MCP\Tools;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\EditorialReview\ProposalService;
use Saltus\WP\Framework\Features\EditorialReview\ProposalStore;
use Saltus\WP\Framework\Features\Relationships\RelationshipManager;
use Saltus\WP\Framework\Features\Relationships\RelationshipRegistry;
use Saltus\WP\Framework\Features\Relationships\RelationshipStore;
use Saltus\WP\Framework\MCP\Tools\AttachRelated;
use Saltus\WP\Framework\MCP\Tools\DetachRelated;
use Saltus\WP\Framework\MCP\Tools\GetRelated;
use Saltus\WP\Framework\MCP\Tools\ListRelationships;
use Saltus\WP\Framework\MCP\Tools\SyncRelated;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Rest\RelationshipsController;
use Saltus\WP\Framework\Tests\Rest\RestRelationshipModeler;
use WP_REST_Request;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';
// `RestRelationshipModeler` lives here; the MCP read tool is dispatched against the
// same controller that test class builds.
require_once dirname( __DIR__, 2 ) . '/Rest/RelationshipsControllerTest.php';

/**
 * @covers \Saltus\WP\Framework\MCP\Tools\ListRelationships
 * @covers \Saltus\WP\Framework\MCP\Tools\GetRelated
 * @covers \Saltus\WP\Framework\MCP\Tools\AttachRelated
 * @covers \Saltus\WP\Framework\MCP\Tools\DetachRelated
 * @covers \Saltus\WP\Framework\MCP\Tools\SyncRelated
 */
class RelationshipToolsTest extends TestCase {

	protected function setUp(): void {
		global $wp_posts, $wp_current_user_can, $wp_post_type_objects, $wp_filter_values, $wp_filters_registered;
		$wp_posts              = [];
		$wp_post_type_objects  = [];
		$wp_current_user_can   = true;
		$wp_filter_values      = [];
		$wp_filters_registered = [];
	}

	/**
	 * Resetting in setUp protects this class but not the next one.
	 *
	 * This class seeds post 7 as a `movie`, and `AbilityRuntimeTest` asserts on
	 * post 7 expecting a `book` — `AiContextProvider::validate_mutation()` reads
	 * the model name off the stored post when one exists. Under a random ordering
	 * that made this class's leftovers fail a test in another file, with nothing
	 * pointing back here.
	 */
	protected function tearDown(): void {
		global $wp_posts, $wp_post_type_objects, $wp_current_user_can, $wp_filter_values, $wp_filters_registered;
		$wp_posts              = [];
		$wp_post_type_objects  = [];
		$wp_current_user_can   = true;
		$wp_filter_values      = [];
		$wp_filters_registered = [];
	}

	public function testEveryToolDeclaresItsNameDescriptionAndRelationshipCapability(): void {
		$expected = [
			'list_relationships' => new ListRelationships(),
			'get_related'        => new GetRelated(),
			'attach_related'     => new AttachRelated(),
			'detach_related'     => new DetachRelated(),
			'sync_related'       => new SyncRelated(),
		];

		foreach ( $expected as $name => $tool ) {
			$this->assertSame( $name, $tool->get_name() );
			$this->assertNotSame( '', $tool->get_description(), $name );
			$this->assertNotSame( [], $tool->get_parameters(), $name );

			$capability = $tool->get_rest_capability();
			$this->assertNotNull( $capability, $name );
			$this->assertSame( ModelRestPolicy::CAPABILITY_RELATIONSHIPS, $capability->get_capability(), $name );
		}
	}

	public function testOnlyReadToolsAreCacheable(): void {
		$this->assertTrue( ( new ListRelationships() )->is_cacheable() );
		$this->assertTrue( ( new GetRelated() )->is_cacheable() );
		$this->assertFalse( ( new AttachRelated() )->is_cacheable() );
		$this->assertFalse( ( new DetachRelated() )->is_cacheable() );
		$this->assertFalse( ( new SyncRelated() )->is_cacheable() );
	}

	public function testListRelationshipsRequestsTheDiscoveryRoute(): void {
		$request = ( new ListRelationships() )->build_rest_request( [ 'post_type' => 'movie' ] );

		$this->assertInstanceOf( WP_REST_Request::class, $request );
		$this->assertSame( 'GET', $request->get_method() );
		$this->assertSame( '/saltus-framework/v1/relationships/movie', $request->get_route() );
	}

	public function testGetRelatedRequestsThePerPostReadRoute(): void {
		$request = ( new GetRelated() )->build_rest_request(
			[
				'post_id'      => 12,
				'relationship' => 'actors',
			]
		);

		$this->assertInstanceOf( WP_REST_Request::class, $request );
		$this->assertSame( 'GET', $request->get_method() );
		$this->assertSame( '/saltus-framework/v1/posts/12/relationships/actors', $request->get_route() );
	}

	public function testAttachSendsRelatedIdPivotAndOrderInTheBody(): void {
		$request = ( new AttachRelated() )->build_rest_request(
			[
				'post_id'      => 12,
				'relationship' => 'actors',
				'related_id'   => 34,
				'pivot'        => [ 'role' => 'Lead' ],
				'order'        => 2,
			]
		);

		$this->assertInstanceOf( WP_REST_Request::class, $request );
		$this->assertSame( 'POST', $request->get_method() );
		$this->assertSame( '/saltus-framework/v1/posts/12/relationships/actors', $request->get_route() );
		$this->assertSame(
			[
				'related_id' => 34,
				'pivot'      => [ 'role' => 'Lead' ],
				'order'      => 2,
			],
			$request->get_json_params()
		);
	}

	public function testAttachOmitsPivotAndOrderWhenNotSupplied(): void {
		$request = ( new AttachRelated() )->build_rest_request(
			[
				'post_id'      => 12,
				'relationship' => 'actors',
				'related_id'   => 34,
			]
		);

		$this->assertInstanceOf( WP_REST_Request::class, $request );
		$this->assertSame( [ 'related_id' => 34 ], $request->get_json_params() );
	}

	public function testDetachTargetsThePairRoute(): void {
		$request = ( new DetachRelated() )->build_rest_request(
			[
				'post_id'      => 12,
				'relationship' => 'actors',
				'related_id'   => 34,
			]
		);

		$this->assertInstanceOf( WP_REST_Request::class, $request );
		$this->assertSame( 'DELETE', $request->get_method() );
		$this->assertSame( '/saltus-framework/v1/posts/12/relationships/actors/34', $request->get_route() );
	}

	public function testSyncSendsAnIntegerListEvenWhenGivenStrings(): void {
		$request = ( new SyncRelated() )->build_rest_request(
			[
				'post_id'      => 12,
				'relationship' => 'actors',
				'related_ids'  => [ '34', '35' ],
			]
		);

		$this->assertInstanceOf( WP_REST_Request::class, $request );
		$this->assertSame( 'PUT', $request->get_method() );
		$this->assertSame( [ 'related_ids' => [ 34, 35 ] ], $request->get_json_params() );
	}

	public function testSyncSendsAnEmptyListWhenRelatedIdsAreMissing(): void {
		$request = ( new SyncRelated() )->build_rest_request(
			[
				'post_id'      => 12,
				'relationship' => 'actors',
			]
		);

		$this->assertInstanceOf( WP_REST_Request::class, $request );
		$this->assertSame( [ 'related_ids' => [] ], $request->get_json_params() );
	}

	public function testRelationshipNamesAreUrlEncodedIntoRoutes(): void {
		$request = ( new GetRelated() )->build_rest_request(
			[
				'post_id'      => 1,
				'relationship' => 'a b/c',
			]
		);

		$this->assertInstanceOf( WP_REST_Request::class, $request );
		$this->assertSame( '/saltus-framework/v1/posts/1/relationships/a%20b%2Fc', $request->get_route() );
	}

	/**
	 * The MCP read tool is REST-backed, so its refusal is the controller's refusal.
	 *
	 * Asserting the route shape alone would not show that, which is the failure mode
	 * this suite exists to catch: a gate written and tested but never reached. The
	 * tool's own request is resolved against the routes the controller registers and
	 * dispatched through the matching callback, so the denial travels the whole path
	 * an ability takes.
	 */
	public function testDeniedReadIsRefusedThroughTheMcpReadTool(): void {
		global $wp_current_user_can, $wp_posts, $wp_rest_routes_registered;

		$wp_rest_routes_registered = [];
		$post                      = new \WP_Post( [ 'post_type' => 'movie' ] );
		$post->ID                  = 12;
		$wp_posts[12]              = $post;

		$controller = $this->relationship_controller();
		$controller->register_routes();

		$wp_current_user_can = [ 'view_cast' => false ];

		$request = ( new GetRelated() )->build_rest_request(
			[
				'post_id'      => 12,
				'relationship' => 'actors',
			]
		);
		$this->assertInstanceOf( WP_REST_Request::class, $request );

		$refused = $this->dispatch( $request );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'rest_relationship_forbidden', $refused->get_error_code() );
		$this->assertSame( 403, $refused->get_error_data()['status'] );
	}

	/** A controller whose `actors` relationship declares a read rule. */
	private function relationship_controller(): RelationshipsController {
		$models = [
			'movie'  => $this->rest_model(
				'movie',
				[
					'relationships' => [
						'actors' => [
							'type'         => 'has_many',
							'model'        => 'person',
							'capabilities' => [ 'read' => [ 'view_cast' ] ],
						],
					],
				]
			),
			'person' => $this->rest_model( 'person', [] ),
		];

		$modeler = new RestRelationshipModeler( $models );

		return new RelationshipsController(
			$modeler,
			new ModelRestPolicy( $modeler ),
			new RelationshipManager( new RelationshipRegistry( $modeler ), new RelationshipStore( null ) )
		);
	}

	/**
	 * Minimal REST-enabled post-type model.
	 *
	 * @param array<string, mixed> $config Raw model configuration.
	 */
	private function rest_model( string $name, array $config ): Model {
		$model = $this->createStub( Model::class );
		$model->method( 'get_name' )->willReturn( $name );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_options' )->willReturn( [ 'show_in_rest' => true ] );
		$model->method( 'get_args' )->willReturn( [] );
		$model->method( 'get_config' )->willReturn( $config );

		return $model;
	}

	/**
	 * Resolve a request against the registered routes and invoke the callback.
	 *
	 * Stands in for `rest_do_request()`, which the stubs answer with a canned response
	 * rather than routing. Matching the tool's route against the registered patterns is
	 * what proves the tool and the gated handler are the same endpoint.
	 *
	 * @return mixed
	 */
	private function dispatch( WP_REST_Request $request ) {
		global $wp_rest_routes_registered;

		foreach ( $wp_rest_routes_registered as $registered ) {
			$pattern = '#^/' . trim( (string) $registered['namespace'], '/' ) . (string) $registered['route'] . '$#';
			if ( preg_match( $pattern, $request->get_route(), $matches ) !== 1 ) {
				continue;
			}

			foreach ( $matches as $key => $value ) {
				if ( is_string( $key ) ) {
					$request->set_param( $key, $value );
				}
			}

			foreach ( $this->endpoints( $registered['args'] ) as $endpoint ) {
				if ( (string) ( $endpoint['methods'] ?? '' ) !== $request->get_method() ) {
					continue;
				}

				return ( $endpoint['callback'] )( $request );
			}
		}

		$this->fail( 'No registered route matched ' . $request->get_route() );
	}

	/**
	 * Normalize a route's args to a list of endpoints.
	 *
	 * `register_rest_route()` accepts either one endpoint or a list of them, and the
	 * per-post route uses the list form to carry GET, POST, and PUT.
	 *
	 * @param array<string, mixed> $args Registered route args.
	 * @return list<array<string, mixed>>
	 */
	private function endpoints( array $args ): array {
		return isset( $args['methods'] ) ? [ $args ] : array_values( $args );
	}

	public function testWriteToolsRequireEditPermissionOnTheOwningPost(): void {
		global $wp_current_user_can;
		$wp_current_user_can = [
			'edit_post:5' => true,
			'edit_post:6' => false,
			'read_post:5' => true,
		];

		foreach ( [ new AttachRelated(), new DetachRelated(), new SyncRelated() ] as $tool ) {
			$this->assertTrue( $tool->has_permission( [ 'post_id' => 5 ] ), $tool->get_name() );
			$this->assertFalse( $tool->has_permission( [ 'post_id' => 6 ] ), $tool->get_name() );
			$this->assertFalse( $tool->has_permission( [] ), $tool->get_name() );
		}

		$this->assertTrue( ( new GetRelated() )->has_permission( [ 'post_id' => 5 ] ) );
		$this->assertFalse( ( new GetRelated() )->has_permission( [] ) );
	}

	public function testListRelationshipsRequiresThePostTypeEditCapability(): void {
		global $wp_current_user_can;
		$wp_current_user_can = [ 'edit_posts' => false ];

		$this->assertFalse( ( new ListRelationships() )->has_permission( [ 'post_type' => 'movie' ] ) );

		$wp_current_user_can = [ 'edit_posts' => true ];
		$this->assertTrue( ( new ListRelationships() )->has_permission( [ 'post_type' => 'movie' ] ) );
	}

	public function testEveryRelationshipWriteToolIsGovernedByTheReviewQueue(): void {
		$service = new ProposalService( new ProposalStore( null ) );

		foreach ( [ 'attach_related', 'detach_related', 'sync_related' ] as $tool ) {
			$this->assertTrue( $service->is_mutating( $tool ), $tool );
			$this->assertTrue( $service->should_queue( $tool ), $tool );
		}

		foreach ( [ 'list_relationships', 'get_related' ] as $tool ) {
			$this->assertFalse( $service->is_mutating( $tool ), $tool );
			$this->assertFalse( $service->should_queue( $tool ), $tool );
		}
	}

	public function testQueuedRelationshipWritesAreAppliedThroughRestOnApproval(): void {
		global $wp_rest_response_override, $wp_posts;
		$wp_posts[7]               = new \WP_Post( [ 'ID' => 7, 'post_type' => 'movie' ] );
		$wp_rest_response_override = new \WP_REST_Response(
			[
				'relationship' => 'actors',
				'post_id'      => 7,
				'related_id'   => 9,
				'attached'     => true,
			],
			200
		);

		$service = new ProposalService( new ProposalStore( null ) );
		$queued  = $service->propose(
			'attach_related',
			[
				'post_id'      => 7,
				'relationship' => 'actors',
				'related_id'   => 9,
			]
		);

		$this->assertSame( 'pending', $queued['status'] );
		$this->assertTrue( $queued['requires_review'] );

		$approved = $service->approve( (int) $queued['proposal_id'] );

		$this->assertIsArray( $approved );
		$this->assertSame( 'approved', $approved['status'] );
		$this->assertSame( 'attach_related', $approved['action'] );
	}
}
