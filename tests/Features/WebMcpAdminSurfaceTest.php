<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\EditorialReview\ProposalService;
use Saltus\WP\Framework\Features\EditorialReview\ProposalStore;
use Saltus\WP\Framework\Features\WebMcp\AdminScreen;
use Saltus\WP\Framework\Features\WebMcp\AdminToolSet;
use Saltus\WP\Framework\Features\WebMcp\WebMcp;
use Saltus\WP\Framework\Features\WebMcp\WebMcpPolicy;
use Saltus\WP\Framework\MCP\Tools\ToolInterface;
use Saltus\WP\Framework\MCP\Tools\UpdatePost;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Rest\WebMcpController;
use Saltus\WP\Framework\WebMcp\Tools\AdminTool;
use Saltus\WP\Framework\WebMcp\WebMcpTool;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * Phase 8B: the admin surface and its governed writes.
 *
 * The load-bearing claim of 8B is that no WebMCP write ever mutates directly, so
 * these tests assert the negative as well as the positive: a queued write must
 * produce a proposal *and* leave the post untouched.
 *
 * @covers \Saltus\WP\Framework\Features\WebMcp\AdminScreen
 * @covers \Saltus\WP\Framework\Features\WebMcp\AdminToolSet
 * @covers \Saltus\WP\Framework\Features\WebMcp\WebMcp
 * @covers \Saltus\WP\Framework\Features\WebMcp\WebMcpPolicy
 * @covers \Saltus\WP\Framework\WebMcp\Tools\AdminTool
 * @covers \Saltus\WP\Framework\Rest\WebMcpController
 */
class WebMcpAdminSurfaceTest extends TestCase {

	/** @var array<string, mixed> */
	private array $get = [];

	protected function setUp(): void {
		global $wp_post_type_objects, $wp_current_user_can, $wp_current_user_id, $wp_nonce_valid, $wp_posts, $wp_filter_values, $wp_transients, $wp_scripts_localized, $wp_scripts_enqueued, $wp_current_screen, $wp_rest_routes_registered;
		$wp_post_type_objects      = [];
		$wp_current_user_can       = true;
		$wp_current_user_id        = 7;
		$wp_nonce_valid            = true;
		$wp_posts                  = [];
		$wp_filter_values          = [ 'saltus/framework/mcp/rate_limit/enabled' => false ];
		$wp_transients             = [];
		$wp_scripts_localized      = [];
		$wp_scripts_enqueued       = [];
		$wp_current_screen         = null;
		$wp_rest_routes_registered = [];
		$this->get                 = $_GET;
	}

	protected function tearDown(): void {
		global $wp_current_user_can, $wp_current_user_id, $wp_nonce_valid, $wp_filter_values, $wp_current_screen;
		$wp_current_user_can = null;
		$wp_current_user_id  = null;
		$wp_nonce_valid      = null;
		$wp_filter_values    = [];
		$wp_current_screen   = null;
		$_GET                = $this->get;
	}

	public function testAdminScreenIdentifiesEditorListSettingsAndReviewQueue(): void {
		$this->assertSame( AdminScreen::POST_EDITOR, ( new AdminScreen( [ 'base' => 'post' ] ) )->resolve() );
		$this->assertSame( AdminScreen::POST_EDITOR, ( new AdminScreen( [ 'base' => 'post-new' ] ) )->resolve() );
		$this->assertSame( AdminScreen::POST_LIST, ( new AdminScreen( [ 'base' => 'edit' ] ) )->resolve() );
		$this->assertSame( AdminScreen::SETTINGS, ( new AdminScreen( [ 'page' => 'saltus-settings' ] ) )->resolve() );
		$this->assertSame( AdminScreen::REVIEW_QUEUE, ( new AdminScreen( [ 'page' => 'saltus-ai-review' ] ) )->resolve() );
		$this->assertNull( ( new AdminScreen( [ 'base' => 'dashboard' ] ) )->resolve(), 'An unrelated screen exposes no tools.' );
	}

	public function testAdminScreenReadsTheCurrentWpScreen(): void {
		global $wp_current_screen;

		$wp_current_screen = (object) [
			'base'      => 'post',
			'id'        => 'book',
			'post_type' => 'book',
		];

		$screen = new AdminScreen();

		$this->assertSame( AdminScreen::POST_EDITOR, $screen->resolve() );
		$this->assertSame( 'book', $screen->post_type() );
	}

	public function testReviewQueueOffersNoWriteTools(): void {
		$writes = [ 'update_post', 'create_post', 'update_settings', 'update_meta_fields', 'delete_post', 'reorder_posts', 'duplicate_post', 'create_term' ];

		foreach ( ( new AdminToolSet() )->for_screen( AdminScreen::REVIEW_QUEUE ) as $tool ) {
			$this->assertNotContains(
				$tool,
				$writes,
				'An agent proposing changes from the review screen would be arguing with itself.'
			);
		}
	}

	public function testScreensOfferOnlyToolsRelevantToThem(): void {
		$set = new AdminToolSet();

		$this->assertContains( 'update_post', $set->for_screen( AdminScreen::POST_EDITOR ) );
		$this->assertNotContains( 'update_settings', $set->for_screen( AdminScreen::POST_EDITOR ), 'Settings writes do not belong on the post editor.' );

		$this->assertContains( 'update_settings', $set->for_screen( AdminScreen::SETTINGS ) );
		$this->assertNotContains( 'update_post', $set->for_screen( AdminScreen::SETTINGS ) );

		$this->assertSame( [], $set->for_screen( null ), 'An unrecognized screen exposes nothing.' );
	}

	public function testAdminToolSetIsFilterable(): void {
		global $wp_filter_values;

		$wp_filter_values['saltus/framework/webmcp/admin_tools'] = [ 'get_post' ];

		$this->assertSame( [ 'get_post' ], ( new AdminToolSet() )->for_screen( AdminScreen::POST_EDITOR ) );
	}

	public function testPolicyResolvesTheAdminSurfaceSeparatelyFromFrontend(): void {
		$policy = new WebMcpPolicy( $this->modeler( [ 'webmcp' => [ 'enabled' => true, 'frontend' => false, 'admin' => true ] ] ) );

		$this->assertTrue( $policy->is_admin_enabled( 'book' ) );
		$this->assertFalse( $policy->is_frontend_enabled( 'book' ) );
		$this->assertSame( [ 'book' ], $policy->admin_models() );
		$this->assertSame( [], $policy->frontend_models(), 'Enabling admin must not open the public surface.' );
		$this->assertTrue( $policy->has_admin_surface() );
		$this->assertFalse( $policy->has_frontend_surface() );
		$this->assertSame( [ 'book' ], $policy->enabled_models() );
	}

	public function testAdminSurfaceIncludesModelsThatAreNotPubliclyQueryable(): void {
		global $wp_post_type_objects;

		$model = $this->createStub( Model::class );
		$model->method( 'get_name' )->willReturn( 'internal' );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_config' )->willReturn( [ 'webmcp' => [ 'enabled' => true, 'admin' => true ] ] );
		$model->method( 'get_args' )->willReturn( [ 'public' => false, 'publicly_queryable' => false ] );

		$wp_post_type_objects['internal'] = (object) [
			'name'               => 'internal',
			'publicly_queryable' => false,
		];

		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( [ 'internal' => $model ] );

		$policy = new WebMcpPolicy( $modeler );

		// An authenticated editor may legitimately work on a private post type;
		// the gate for the admin surface is capability, not public visibility.
		$this->assertSame( [ 'internal' ], $policy->admin_models() );
		$this->assertSame( [], $policy->frontend_models() );
	}

	public function testMutatingToolQueuesAProposalAndReturnsAReviewUrl(): void {
		global $wp_posts;

		$wp_posts[9] = new \WP_Post(
			[
				'ID'         => 9,
				'post_type'  => 'book',
				'post_title' => 'Original title',
			]
		);

		$store = new ProposalStore();
		$tool  = new AdminTool( new UpdatePost(), new ProposalService( $store ) );

		$result = $tool->execute(
			[
				'post_id' => 9,
				'title'   => 'Rewritten by an agent',
			]
		);

		$this->assertTrue( $result['requires_review'] );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertGreaterThan( 0, $result['proposal_id'] );
		$this->assertStringContainsString( 'saltus-ai-review', $result['review_url'] );
		$this->assertStringContainsString( 'proposal=' . $result['proposal_id'], $result['review_url'] );

		$proposal = $store->get( (int) $result['proposal_id'] );
		$this->assertIsArray( $proposal );
		$this->assertSame( 'pending', $proposal['status'] );
		$this->assertSame( 'update_post', $proposal['tool'] );

		// The write must not have been applied.
		$this->assertSame( 'Original title', $wp_posts[9]->post_title, 'A WebMCP write must never mutate directly.' );
	}

	public function testMutatingToolIsUnavailableWithoutTheReviewQueue(): void {
		$tool = new AdminTool( new UpdatePost(), null );

		$result = $tool->execute( [ 'post_id' => 9, 'title' => 'Direct write' ] );

		$this->assertSame( 'saltus_webmcp_review_unavailable', $result['error']['code'] );
		$this->assertSame( 503, $result['error']['status'] );
	}

	public function testWriteToolIsNotAnnotatedReadOnly(): void {
		$write = new AdminTool( new UpdatePost(), new ProposalService( new ProposalStore() ) );
		$read  = new AdminTool( new \Saltus\WP\Framework\MCP\Tools\GetPost(), new ProposalService( new ProposalStore() ) );

		// readOnlyHint is the only signal that makes an agent confirm with a human.
		// A queued write still changes state, so it must not claim to be read-only.
		$this->assertFalse( $write->get_annotations()['readOnlyHint'] );
		$this->assertTrue( $read->get_annotations()['readOnlyHint'] );
		$this->assertStringContainsString( 'human review', $write->get_description() );
	}

	public function testAdminToolsRequireAuthenticationAndDeclareTheirSurface(): void {
		$tool = new AdminTool( new UpdatePost(), new ProposalService( new ProposalStore() ) );

		$this->assertSame( WebMcpTool::SURFACE_ADMIN, $tool->get_surface() );
		$this->assertTrue( $tool->requires_authentication() );
		$this->assertSame( 'edit_posts', $tool->get_discovery_capability() );
	}

	public function testExecuteRejectsAnAdminToolWithoutAValidNonce(): void {
		global $wp_nonce_valid;

		$wp_nonce_valid = false;

		$response = $this->controller()->execute_tool( $this->request( 'update_post', [ 'post_id' => 9, 'title' => 'x' ] ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'saltus_webmcp_invalid_nonce', $response->get_error_code() );
		$this->assertSame( 403, $response->get_error_data()['status'] );
		$this->assertTrue( $response->get_error_data()['refresh'], 'The bridge keys its silent retry on this flag.' );
	}

	public function testExecuteRejectsAnAdminToolForAnAnonymousCaller(): void {
		global $wp_current_user_id;

		$wp_current_user_id = 0;

		$response = $this->controller()->execute_tool( $this->request( 'update_post', [ 'post_id' => 9, 'title' => 'x' ] ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'saltus_webmcp_not_logged_in', $response->get_error_code() );
		$this->assertSame( 401, $response->get_error_data()['status'] );
	}

	public function testExecuteAcceptsAnAdminToolWithAValidNonce(): void {
		global $wp_posts;

		$wp_posts[9] = new \WP_Post( [ 'ID' => 9, 'post_type' => 'book', 'post_title' => 'Original' ] );

		$response = $this->controller()->execute_tool( $this->request( 'update_post', [ 'post_id' => 9, 'title' => 'Proposed' ] ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertTrue( $response->get_data()['result']['requires_review'] );
	}

	public function testNonceRouteIssuesATokenOnlyToALoggedInUser(): void {
		$controller = $this->controller();

		$response = $controller->get_nonce( null );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertNotSame( '', $response->get_data()['nonce'] );

		global $wp_current_user_id;
		$wp_current_user_id = 0;

		$denied = $controller->nonce_permissions_check( null );
		$this->assertInstanceOf( \WP_Error::class, $denied );
		$this->assertSame( 'saltus_webmcp_not_logged_in', $denied->get_error_code() );
	}

	public function testManifestOmitsAdminToolsTheUserCannotCall(): void {
		global $wp_current_user_can;

		$wp_current_user_can = false;

		$data = $this->controller()->get_manifest( (object) [] )->get_data();
		$names = array_column( $data['tools'], 'name' );

		$this->assertNotContains( 'update_post', $names, 'A tool that would refuse every call must not be advertised.' );
	}

	public function testManifestIncludesAdminToolsForACapableUser(): void {
		$data  = $this->controller()->get_manifest( (object) [] )->get_data();
		$names = array_column( $data['tools'], 'name' );

		$this->assertContains( 'update_post', $names );
	}

	public function testUnknownToolIsRejectedBeforeAuthentication(): void {
		$response = $this->controller()->execute_tool( $this->request( 'no_such_tool', [] ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'saltus_webmcp_unknown_tool', $response->get_error_code() );
	}

	public function testToolErrorPayloadBecomesARestErrorWithItsOwnStatus(): void {
		$modeler = $this->modeler( [ 'webmcp' => [ 'enabled' => true, 'admin' => true ] ] );

		// A mutating tool with no review queue reports 503 rather than writing.
		$controller = new WebMcpController(
			new WebMcpPolicy( $modeler ),
			[ new AdminTool( new UpdatePost(), null ) ]
		);

		$response = $controller->execute_tool( $this->request( 'update_post', [ 'post_id' => 9, 'title' => 'x' ] ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'saltus_webmcp_review_unavailable', $response->get_error_code() );
		$this->assertSame( 503, $response->get_error_data()['status'] );
	}

	public function testNonceRouteIsRegistered(): void {
		global $wp_rest_routes_registered;

		$this->controller()->register_routes();

		$routes = array_map(
			fn( $entry ) => $entry['namespace'] . '/' . ltrim( $entry['route'], '/' ),
			$wp_rest_routes_registered
		);

		$this->assertContains( 'saltus-framework/v1/webmcp/nonce', $routes );
	}

	public function testAdminBridgeLocalizesANonceAndTheRefreshEndpoint(): void {
		global $wp_scripts_localized, $wp_current_screen;

		$wp_current_screen = (object) [ 'base' => 'post', 'id' => 'book', 'post_type' => 'book' ];

		$modeler = $this->modeler( [ 'webmcp' => [ 'enabled' => true, 'admin' => true ] ] );
		$feature = new WebMcp(
			[
				'modeler'           => $modeler,
				'modeler_resolver'  => function () use ( $modeler ): Modeler {
					return $modeler;
				},
				'tool_contributors' => function (): array {
					return [ $this->contributor() ];
				},
			],
			new ProposalService( new ProposalStore() )
		);

		$feature->enqueue_admin_bridge();

		$this->assertCount( 1, $wp_scripts_localized );
		$payload = $wp_scripts_localized[0]['l10n'];

		$this->assertNotSame( '', $payload['nonce'] );
		$this->assertStringContainsString( 'webmcp/nonce', $payload['nonceEndpoint'] );
		$this->assertSame( WebMcpTool::SURFACE_ADMIN, $payload['surface'] );
		$this->assertContains( 'update_post', array_column( $payload['tools'], 'name' ) );
	}

	public function testFrontendBridgeCarriesNoNonce(): void {
		global $wp_scripts_localized, $wp_query_posts;

		$wp_query_posts = [];
		$modeler        = $this->modeler( [ 'webmcp' => true ] );
		$feature        = new WebMcp(
			[
				'modeler'          => $modeler,
				'modeler_resolver' => function () use ( $modeler ): Modeler {
					return $modeler;
				},
			]
		);

		$feature->enqueue_bridge();

		$this->assertCount( 1, $wp_scripts_localized );
		$payload = $wp_scripts_localized[0]['l10n'];

		// An anonymous visitor has no session to bind a nonce to; shipping one
		// would imply an authentication story the public surface does not have.
		$this->assertArrayNotHasKey( 'nonce', $payload );
		$this->assertArrayNotHasKey( 'nonceEndpoint', $payload );
	}

	public function testAdminBridgeIsNotEnqueuedWithoutAnAdminEnabledModel(): void {
		global $wp_scripts_enqueued, $wp_current_screen;

		$wp_current_screen = (object) [ 'base' => 'post', 'id' => 'book', 'post_type' => 'book' ];

		$modeler = $this->modeler( [ 'webmcp' => [ 'enabled' => true, 'frontend' => true ] ] );
		$feature = new WebMcp(
			[
				'modeler'          => $modeler,
				'modeler_resolver' => function () use ( $modeler ): Modeler {
					return $modeler;
				},
			]
		);

		$feature->enqueue_admin_bridge();

		$this->assertSame( [], $wp_scripts_enqueued, 'Frontend-only opt-in must not register admin tools.' );
	}

	public function testProposalServiceSeparatesMutationFactFromReviewPolicy(): void {
		global $wp_filter_values;

		$service = new ProposalService( new ProposalStore() );

		$wp_filter_values['saltus/framework/editorial_review/require_human_review'] = false;

		// A site may switch review off; that does not make a write a read.
		$this->assertFalse( $service->should_queue( 'update_post' ) );
		$this->assertTrue( $service->is_mutating( 'update_post' ) );
		$this->assertFalse( $service->is_mutating( 'get_post' ) );
	}

	/**
	 * A controller holding the admin tool set for an admin-enabled model.
	 */
	private function controller(): WebMcpController {
		$modeler = $this->modeler( [ 'webmcp' => [ 'enabled' => true, 'admin' => true ] ] );

		return new WebMcpController(
			new WebMcpPolicy( $modeler ),
			[ new AdminTool( new UpdatePost(), new ProposalService( new ProposalStore() ) ) ]
		);
	}

	/**
	 * A nonce-bearing execute request.
	 *
	 * @param string               $tool Tool name.
	 * @param array<string, mixed> $args Arguments.
	 */
	private function request( string $tool, array $args ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/saltus-framework/v1/webmcp/execute' );
		$request->set_json_params(
			[
				'tool'      => $tool,
				'arguments' => $args,
			]
		);
		$request->set_header( 'X-WP-Nonce', 'nonce-wp_rest' );

		return $request;
	}

	/**
	 * A tool contributor offering the abilities the admin surface projects.
	 */
	private function contributor(): object {
		return new class implements \Saltus\WP\Framework\MCP\Tools\ToolContributor {
			/**
			 * @return list<ToolInterface>
			 */
			public function get_mcp_tools( Modeler $modeler, ?\Saltus\WP\Framework\Rest\ModelRestPolicy $policy = null ): array {
				return [ new UpdatePost(), new \Saltus\WP\Framework\MCP\Tools\GetPost() ];
			}
		};
	}

	/**
	 * @param array<string, mixed> $config Model configuration.
	 */
	private function modeler( array $config ): Modeler {
		global $wp_post_type_objects;

		$model = $this->createStub( Model::class );
		$model->method( 'get_name' )->willReturn( 'book' );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_config' )->willReturn( $config );
		$model->method( 'get_options' )->willReturn( [ 'show_in_rest' => true ] );
		$model->method( 'get_args' )->willReturn( [ 'public' => true, 'publicly_queryable' => true ] );

		$wp_post_type_objects['book'] = (object) [
			'name'               => 'book',
			'publicly_queryable' => true,
			'public'             => true,
			'label'              => 'Book',
			'labels'             => (object) [ 'name' => 'Books' ],
		];

		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( [ 'book' => $model ] );
		$modeler->method( 'get_mcp_tools' )->willReturn( [] );

		return $modeler;
	}
}
