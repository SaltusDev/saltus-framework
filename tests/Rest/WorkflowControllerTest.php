<?php

namespace Saltus\WP\Framework\Tests\Rest;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Workflow\StateTransitioner;
use Saltus\WP\Framework\Features\Workflow\Workflow;
use Saltus\WP\Framework\Features\Workflow\WorkflowRegistry;
use Saltus\WP\Framework\MCP\Tools\Workflow\GetWorkflowStates;
use Saltus\WP\Framework\MCP\Tools\Workflow\TransitionWorkflowState;
use Saltus\WP\Framework\Models\Config\NoFile;
use Saltus\WP\Framework\Models\PostType;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Rest\WorkflowController;

require_once __DIR__ . '/functions.php';

/** @covers \Saltus\WP\Framework\Rest\WorkflowController */
/** @covers \Saltus\WP\Framework\Features\Workflow\Workflow */
class WorkflowControllerTest extends TestCase {

	private WorkflowController $controller;
	private StateTransitioner $transitioner;

	protected function setUp(): void {
		global $wp_rest_routes_registered, $wp_post_meta, $wp_posts, $wp_current_user_can, $wp_mail_sent, $wp_actions_registered;
		$wp_rest_routes_registered = [];
		$wp_post_meta              = [];
		$wp_mail_sent              = [];
		$wp_actions_registered     = [];
		$wp_current_user_can       = true;

		$wp_posts = [];
		foreach ( [ 1, 2 ] as $post_id ) {
			$post                 = new \WP_Post();
			$post->ID             = $post_id;
			$post->post_type      = 'movie';
			$post->post_status    = 'draft';
			$wp_posts[ $post_id ] = $post;
		}

		$registry = new WorkflowRegistry();
		$registry->register( 'movie', $this->config() );

		$this->transitioner = new StateTransitioner( $registry );
		$this->controller   = new WorkflowController( $registry, $this->transitioner );
	}

	/** @return array<string, mixed> */
	private function config(): array {
		return [
			'states'      => [
				[ 'slug' => 'draft', 'label' => 'Draft' ],
				[ 'slug' => 'in_legal_review', 'label' => 'In Legal Review', 'notification' => 'legal@company.com' ],
				[ 'slug' => 'approved', 'label' => 'Approved' ],
				[ 'slug' => 'published', 'label' => 'Published', 'public' => true ],
			],
			'transitions' => [
				'submit_for_review' => [ 'from' => [ 'draft' ], 'to' => 'in_legal_review' ],
				'approve'           => [ 'from' => [ 'in_legal_review' ], 'to' => 'approved', 'capability' => 'publish_posts' ],
				'publish'           => [ 'from' => [ 'approved' ], 'to' => 'published', 'capability' => 'publish_posts', 'action_hook' => true ],
			],
		];
	}

	/** @param array<string, mixed> $params */
	private function request( array $params ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'GET', '/test' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	/**
	 * @param mixed $response
	 *
	 * @return array<string, mixed>
	 */
	private function data( $response ): array {
		if ( is_object( $response ) && method_exists( $response, 'get_data' ) ) {
			$data = $response->get_data();
			return is_array( $data ) ? $data : [];
		}

		return is_array( $response ) ? $response : [];
	}

	public function testRegistersWorkflowRoutes(): void {
		$this->controller->register_routes();

		$routes = array_column( $GLOBALS['wp_rest_routes_registered'], 'route' );

		$this->assertContains( '/workflow/(?P<post_id>[0-9]+)', $routes );
		$this->assertContains( '/workflow/(?P<post_id>[0-9]+)/transition', $routes );
		$this->assertContains( '/workflow/(?P<post_id>[0-9]+)/history', $routes );
	}

	public function testGetItemReportsCurrentStateAndAvailableMoves(): void {
		$data = $this->data( $this->controller->get_item( $this->request( [ 'post_id' => 1, 'model' => 'movie' ] ) ) );

		$this->assertSame( 'draft', $data['state'] );
		$this->assertSame( 'Draft', $data['label'] );
		$this->assertFalse( $data['public'] );
		$this->assertSame( [ 'submit_for_review' ], array_column( $data['available_transitions'], 'name' ) );
	}

	public function testGetItemExcludesMovesTheUserCannotMake(): void {
		$GLOBALS['wp_current_user_can'] = [ 'read' => true, 'edit_posts' => true, 'edit_post' => true, 'publish_posts' => false ];
		$this->transitioner->transition( 'movie', 1, 'submit_for_review' );

		$data = $this->data( $this->controller->get_item( $this->request( [ 'post_id' => 1, 'model' => 'movie' ] ) ) );

		$this->assertSame( 'in_legal_review', $data['state'] );
		$this->assertSame( [], $data['available_transitions'] );
	}

	public function testGetItemIncludesTheWholeWorkflowForClients(): void {
		$data = $this->data( $this->controller->get_item( $this->request( [ 'post_id' => 1, 'model' => 'movie' ] ) ) );

		$this->assertCount( 4, $data['workflow']['states'] );
		$this->assertCount( 3, $data['workflow']['transitions'] );
	}

	public function testGetItemRejectsAModelWithoutAWorkflow(): void {
		$response = $this->controller->get_item( $this->request( [ 'post_id' => 1, 'model' => 'unknown' ] ) );

		$this->assertTrue( is_wp_error( $response ) );
	}

	public function testTransitionMovesThePost(): void {
		$data = $this->data(
			$this->controller->transition( $this->request( [ 'post_id' => 1, 'model' => 'movie', 'transition' => 'submit_for_review' ] ) )
		);

		$this->assertTrue( $data['transitioned'] );
		$this->assertSame( 'draft', $data['from'] );
		$this->assertSame( 'in_legal_review', $data['to'] );
	}

	public function testIllegalTransitionIsA400NotASilentNoOp(): void {
		$response = $this->controller->transition(
			$this->request( [ 'post_id' => 1, 'model' => 'movie', 'transition' => 'publish' ] )
		);

		$this->assertTrue( is_wp_error( $response ) );
		$this->assertSame( 'draft', $this->transitioner->current_state( 'movie', 1 ) );
	}

	public function testUnknownTransitionIsRejected(): void {
		$response = $this->controller->transition(
			$this->request( [ 'post_id' => 1, 'model' => 'movie', 'transition' => 'teleport' ] )
		);

		$this->assertTrue( is_wp_error( $response ) );
	}

	public function testMissingTransitionNameIsRejected(): void {
		$response = $this->controller->transition(
			$this->request( [ 'post_id' => 1, 'model' => 'movie', 'transition' => '' ] )
		);

		$this->assertTrue( is_wp_error( $response ) );
	}

	public function testTransitionWithoutCapabilityIsRejected(): void {
		$GLOBALS['wp_current_user_can'] = [ 'read' => true, 'edit_posts' => true, 'edit_post' => true, 'publish_posts' => false ];
		$this->transitioner->transition( 'movie', 1, 'submit_for_review' );

		$response = $this->controller->transition(
			$this->request( [ 'post_id' => 1, 'model' => 'movie', 'transition' => 'approve' ] )
		);

		$this->assertTrue( is_wp_error( $response ) );
	}

	public function testNoteIsRecordedInHistory(): void {
		$this->controller->transition(
			$this->request( [ 'post_id' => 1, 'model' => 'movie', 'transition' => 'submit_for_review', 'note' => 'Ready for legal.' ] )
		);

		$data = $this->data( $this->controller->get_history( $this->request( [ 'post_id' => 1 ] ) ) );

		$this->assertCount( 1, $data['history'] );
		$this->assertSame( 'Ready for legal.', $data['history'][0]['note'] );
	}

	public function testHistoryIsEmptyForAnUntouchedPost(): void {
		$data = $this->data( $this->controller->get_history( $this->request( [ 'post_id' => 2 ] ) ) );

		$this->assertSame( [], $data['history'] );
	}

	public function testWritesRequireEditCapabilityOnThePost(): void {
		$GLOBALS['wp_current_user_can'] = [ 'edit_post' => false ];
		$this->assertFalse( $this->controller->write_permissions_check( $this->request( [ 'post_id' => 1 ] ) ) );

		$GLOBALS['wp_current_user_can'] = [ 'edit_post' => true ];
		$this->assertTrue( $this->controller->write_permissions_check( $this->request( [ 'post_id' => 1 ] ) ) );
	}

	public function testWritesAreRefusedWithoutAPostId(): void {
		$this->assertFalse( $this->controller->write_permissions_check( $this->request( [ 'post_id' => 0 ] ) ) );
	}

	public function testReadsRequireOnlyRead(): void {
		$GLOBALS['wp_current_user_can'] = [ 'read' => true ];

		$this->assertTrue( $this->controller->read_permissions_check( $this->request( [ 'post_id' => 1 ] ) ) );
	}

	public function testMcpToolsDeclareNamesAndSchemas(): void {
		$tools = [ new GetWorkflowStates(), new TransitionWorkflowState() ];

		$this->assertSame(
			[ 'get_workflow_states', 'transition_workflow_state' ],
			array_map( static fn( $tool ): string => $tool->get_name(), $tools )
		);

		foreach ( $tools as $tool ) {
			$this->assertNotSame( '', $tool->get_description() );
			$this->assertNotSame( [], $tool->get_parameters() );
		}
	}

	public function testReadToolIsBrieflyCacheableAndWriteToolIsNot(): void {
		$this->assertTrue( ( new GetWorkflowStates() )->is_cacheable() );
		$this->assertSame( 30, ( new GetWorkflowStates() )->cache_ttl() );
		$this->assertFalse( ( new TransitionWorkflowState() )->is_cacheable() );
	}

	public function testTransitionToolRequiresEditCapability(): void {
		$GLOBALS['wp_current_user_can'] = [ 'edit_post' => false ];
		$this->assertFalse( ( new TransitionWorkflowState() )->has_permission( [ 'post_id' => 1 ] ) );

		$GLOBALS['wp_current_user_can'] = [ 'edit_post' => true ];
		$this->assertTrue( ( new TransitionWorkflowState() )->has_permission( [ 'post_id' => 1 ] ) );
	}

	public function testTransitionToolBuildsAPostRequest(): void {
		$request = ( new TransitionWorkflowState() )->build_rest_request(
			[ 'post_id' => 7, 'model' => 'movie', 'transition' => 'approve', 'note' => 'ok' ]
		);

		$this->assertInstanceOf( \WP_REST_Request::class, $request );
		$this->assertSame( 'POST', $request->get_method() );
		$this->assertStringContainsString( '/workflow/7/transition', $request->get_route() );
		$this->assertSame( 'approve', $request->get_json_params()['transition'] );
	}

	public function testStatesToolBuildsAGetRequest(): void {
		$request = ( new GetWorkflowStates() )->build_rest_request( [ 'post_id' => 7, 'model' => 'movie' ] );

		$this->assertInstanceOf( \WP_REST_Request::class, $request );
		$this->assertSame( 'GET', $request->get_method() );
		$this->assertStringContainsString( '/workflow/7', $request->get_route() );
	}

	public function testFeatureContributesBothMcpTools(): void {
		$feature = new Workflow();

		$tools = $feature->get_mcp_tools( $this->modeler( [] ) );

		$this->assertCount( 2, $tools );
	}

	public function testFeatureReadsWorkflowFromModelConfig(): void {
		$feature = new Workflow();

		$feature->load_models( $this->modeler( [ new PostType( new NoFile( [ 'name' => 'movie', 'workflow' => $this->config() ] ) ) ] ) );

		$this->assertTrue( $feature->registry()->has( 'movie' ) );
	}

	public function testFeatureSurvivesAnInvalidWorkflowDeclaration(): void {
		$feature = new Workflow();

		$feature->load_models(
			$this->modeler(
				[
					new PostType( new NoFile( [ 'name' => 'movie', 'workflow' => [ 'states' => [] ] ] ) ),
					new PostType( new NoFile( [ 'name' => 'book', 'workflow' => $this->config() ] ) ),
				]
			)
		);

		$this->assertFalse( $feature->registry()->has( 'movie' ) );
		$this->assertTrue( $feature->registry()->has( 'book' ) );
	}

	public function testFeatureSendsNotificationForStatesThatDeclareOne(): void {
		$feature = new Workflow();

		$feature->send_notification( 'legal@company.com', [ 'model' => 'movie', 'post_id' => 1, 'from' => 'draft', 'to' => 'in_legal_review', 'note' => 'Please review.' ] );

		$this->assertCount( 1, $GLOBALS['wp_mail_sent'] );
		$this->assertSame( 'legal@company.com', $GLOBALS['wp_mail_sent'][0]['to'] );
		$this->assertStringContainsString( 'in_legal_review', $GLOBALS['wp_mail_sent'][0]['subject'] );
		$this->assertStringContainsString( 'Please review.', $GLOBALS['wp_mail_sent'][0]['message'] );
	}

	public function testNotificationIsSkippedWithoutARecipient(): void {
		( new Workflow() )->send_notification( '', [ 'model' => 'movie' ] );

		$this->assertSame( [], $GLOBALS['wp_mail_sent'] );
	}

	public function testFeatureHooksTheNotificationlistener(): void {
		$feature = new Workflow();

		$feature->register();

		$this->assertContains( 'saltus/framework/workflow/notify', array_column( $GLOBALS['wp_actions_registered'], 'hook_name' ) );
	}

	public function testFeatureIsRegisteredInTheCoreServiceList(): void {
		$core   = new \ReflectionClass( \Saltus\WP\Framework\Core::class );
		$method = $core->getMethod( 'get_service_classes' );
		$method->setAccessible( true );

		$services = $method->invoke( $core->newInstanceWithoutConstructor() );

		$this->assertContains( Workflow::class, array_values( $services ) );
	}

	/** @param list<PostType> $models */
	private function modeler( array $models ): Modeler {
		return new class( $models ) extends Modeler {
			/** @param list<PostType> $models */
			public function __construct( private array $stub_models ) {
			}

			/** @return list<\Saltus\WP\Framework\Models\Model> */
			public function get_models(): array {
				return $this->stub_models;
			}
		};
	}
}
