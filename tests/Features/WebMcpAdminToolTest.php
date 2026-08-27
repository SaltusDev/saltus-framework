<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\EditorialReview\ProposalService;
use Saltus\WP\Framework\Features\EditorialReview\ProposalStore;
use Saltus\WP\Framework\MCP\Tools\RestBackedToolInterface;
use Saltus\WP\Framework\MCP\Tools\RestCapabilityRequirement;
use Saltus\WP\Framework\MCP\Tools\ToolInterface;
use Saltus\WP\Framework\WebMcp\WebMcpAnnotated;
use Saltus\WP\Framework\WebMcp\WebMcpTool;
use Saltus\WP\Framework\WebMcp\Tools\AdminTool;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * AdminTool projects an existing ability onto the browser surface.
 *
 * Its security-relevant property is that a mutating call is never applied: it
 * becomes a pending proposal for a human. If the review service is missing, a
 * write must fail rather than quietly downgrade to a direct write.
 *
 * @covers \Saltus\WP\Framework\WebMcp\Tools\AdminTool
 */
class WebMcpAdminToolTest extends TestCase {

	protected function setUp(): void {
		global $wp_posts, $wp_current_user_can, $wp_rest_request_log, $wp_rest_response_override;

		$wp_posts                  = [];
		$wp_current_user_can       = true;
		$wp_rest_request_log       = [];
		$wp_rest_response_override = null;
	}

	private function proposals(): ProposalService {
		return new ProposalService( new ProposalStore( null ) );
	}

	/** A REST-backed read tool: cacheable, so treated as non-mutating. */
	private function read_tool( string $name = 'list_posts' ): RestBackedToolInterface {
		$tool = $this->createMock( RestBackedToolInterface::class );
		$tool->method( 'get_name' )->willReturn( $name );
		$tool->method( 'get_description' )->willReturn( 'Read something.' );
		$tool->method( 'get_parameters' )->willReturn( [ 'query' => [ 'type' => 'string' ] ] );
		$tool->method( 'has_permission' )->willReturn( true );
		$tool->method( 'is_cacheable' )->willReturn( true );
		$tool->method( 'get_rest_capability' )->willReturn( null );
		$tool->method( 'build_rest_request' )->willReturn( new \WP_REST_Request( 'GET', '/wp/v2/posts' ) );

		return $tool;
	}

	/** A REST-backed write tool: not cacheable, and named in the mutating list. */
	private function write_tool( string $name = 'update_post' ): RestBackedToolInterface {
		$tool = $this->createMock( RestBackedToolInterface::class );
		$tool->method( 'get_name' )->willReturn( $name );
		$tool->method( 'get_description' )->willReturn( 'Change something.' );
		$tool->method( 'get_parameters' )->willReturn( [] );
		$tool->method( 'has_permission' )->willReturn( true );
		$tool->method( 'is_cacheable' )->willReturn( false );
		$tool->method( 'get_rest_capability' )->willReturn( null );
		$tool->method( 'build_rest_request' )->willReturn( new \WP_REST_Request( 'PUT', '/wp/v2/posts/1' ) );

		return $tool;
	}

	public function testIdentityAndParametersComeFromTheWrappedAbility(): void {
		$tool = new AdminTool( $this->read_tool(), $this->proposals() );

		$this->assertSame( 'list_posts', $tool->get_name() );
		$this->assertSame( [ 'query' => [ 'type' => 'string' ] ], $tool->get_parameters() );
		$this->assertSame( AdminTool::SURFACE_ADMIN, $tool->get_surface() );
	}

	public function testAdminCallsRequireAuthentication(): void {
		$this->assertTrue( ( new AdminTool( $this->read_tool(), $this->proposals() ) )->requires_authentication() );
	}

	public function testPermissionIsDelegatedToTheWrappedAbility(): void {
		$denied = $this->createMock( ToolInterface::class );
		$denied->method( 'get_name' )->willReturn( 'list_posts' );
		$denied->method( 'has_permission' )->willReturn( false );

		$this->assertFalse( ( new AdminTool( $denied, $this->proposals() ) )->has_permission( [] ) );
		$this->assertTrue( ( new AdminTool( $this->read_tool(), $this->proposals() ) )->has_permission( [] ) );
	}

	public function testAReadKeepsItsDescriptionUnchanged(): void {
		$tool = new AdminTool( $this->read_tool(), $this->proposals() );

		$this->assertSame( 'Read something.', $tool->get_description() );
	}

	/**
	 * The agent plans against the description, so a queued write has to say so —
	 * otherwise the agent reports the task done when a human still has to act.
	 */
	public function testAWriteAnnouncesThatItQueuesForReview(): void {
		$tool = new AdminTool( $this->write_tool(), $this->proposals() );

		$this->assertSame(
			'Change something. Queues the change for human review instead of applying it.',
			$tool->get_description()
		);
	}

	public function testReadsAreAnnotatedReadOnlyAndWritesAreNot(): void {
		$read  = new AdminTool( $this->read_tool(), $this->proposals() );
		$write = new AdminTool( $this->write_tool(), $this->proposals() );

		$this->assertTrue( $read->get_annotations()['readOnlyHint'] );
		$this->assertFalse(
			$write->get_annotations()['readOnlyHint'],
			'A queued write still changes state, so it must not claim to be read-only.'
		);
	}

	public function testAllContentIsMarkedUntrusted(): void {
		$annotations = ( new AdminTool( $this->read_tool(), $this->proposals() ) )->get_annotations();

		$this->assertTrue( $annotations['untrustedContentHint'] );
	}

	public function testAnnotationsFromTheWrappedToolAreUsedWhenItProvidesThem(): void {
		$annotated = new class() implements ToolInterface, WebMcpAnnotated {
			public function get_name(): string {
				return 'custom';
			}

			public function get_description(): string {
				return 'Custom.';
			}

			public function get_parameters(): array {
				return [];
			}

			public function has_permission( array $args ): bool {
				return true;
			}

			public function get_annotations(): array {
				return [ 'readOnlyHint' => true, 'untrustedContentHint' => false, 'custom' => true ];
			}
		};

		$annotations = ( new AdminTool( $annotated, $this->proposals() ) )->get_annotations();

		$this->assertSame( [ 'readOnlyHint' => true, 'untrustedContentHint' => false, 'custom' => true ], $annotations );
	}

	public function testDiscoveryCapabilityComesFromTheAbilityRequirement(): void {
		$tool = $this->createMock( RestBackedToolInterface::class );
		$tool->method( 'get_name' )->willReturn( 'list_posts' );
		$tool->method( 'is_cacheable' )->willReturn( true );
		$tool->method( 'get_rest_capability' )->willReturn( new RestCapabilityRequirement( 'manage_options' ) );

		$this->assertSame( 'manage_options', ( new AdminTool( $tool, $this->proposals() ) )->get_discovery_capability() );
	}

	/**
	 * An ability declaring no requirement inherits edit_posts rather than
	 * becoming visible to subscribers.
	 */
	public function testDiscoveryCapabilityFallsBackToEditPosts(): void {
		$this->assertSame( 'edit_posts', ( new AdminTool( $this->read_tool(), $this->proposals() ) )->get_discovery_capability() );

		$plain = $this->createMock( ToolInterface::class );
		$plain->method( 'get_name' )->willReturn( 'plain' );

		$this->assertSame( 'edit_posts', ( new AdminTool( $plain, $this->proposals() ) )->get_discovery_capability() );
	}

	public function testAReadIsDispatchedThroughTheRestApi(): void {
		global $wp_rest_request_log;

		$result = ( new AdminTool( $this->read_tool(), $this->proposals() ) )->execute( [ 'query' => 'x' ] );

		$this->assertCount( 1, $wp_rest_request_log );
		$this->assertSame( '/wp/v2/posts', $wp_rest_request_log[0]['route'] );
		$this->assertTrue( $result['ok'] );
	}

	public function testAWriteIsQueuedAndNeverDispatched(): void {
		global $wp_rest_request_log;

		$proposals = $this->proposals();
		$result    = ( new AdminTool( $this->write_tool(), $proposals ) )->execute( [ 'post_id' => 1, 'title' => 'New' ] );

		$this->assertSame( [], $wp_rest_request_log, 'A write must not reach the REST API.' );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertSame( 1, $result['proposal_id'] );
		$this->assertNotNull( $proposals->get( 1 ) );
	}

	/**
	 * The agent gets a link it can hand to the user, not just an opaque id.
	 */
	public function testAQueuedWriteReturnsAReviewUrlForThatProposal(): void {
		$result = ( new AdminTool( $this->write_tool(), $this->proposals() ) )->execute( [ 'post_id' => 1 ] );

		$this->assertStringContainsString( 'page=saltus-ai-review', $result['review_url'] );
		$this->assertStringContainsString( 'proposal=1', $result['review_url'] );
	}

	/**
	 * Losing the review service must fail the call. Falling through to a direct
	 * write would turn a missing dependency into an unreviewed change.
	 */
	public function testAWriteWithoutTheReviewServiceIsRefused(): void {
		global $wp_rest_request_log;

		$result = ( new AdminTool( $this->write_tool(), null ) )->execute( [ 'post_id' => 1 ] );

		$this->assertSame( 'saltus_webmcp_review_unavailable', $result['error']['code'] );
		$this->assertSame( 503, $result['error']['status'] );
		$this->assertSame( [], $wp_rest_request_log );
	}

	/** Without the service, a cacheable REST-backed tool is still a safe read. */
	public function testAReadWithoutTheReviewServiceStillDispatches(): void {
		$result = ( new AdminTool( $this->read_tool(), null ) )->execute( [] );

		$this->assertTrue( $result['ok'] );
	}

	/**
	 * A tool that is not REST-backed has no route to dispatch to, so it cannot be
	 * offered in the browser at all.
	 */
	public function testANonRestBackedReadIsReportedAsUnsupported(): void {
		$plain = $this->createMock( ToolInterface::class );
		$plain->method( 'get_name' )->willReturn( 'list_posts' );
		$plain->method( 'get_description' )->willReturn( 'Plain.' );

		$result = ( new AdminTool( $plain, $this->proposals() ) )->execute( [] );

		$this->assertSame( 'saltus_webmcp_unsupported_tool', $result['error']['code'] );
		$this->assertSame( 501, $result['error']['status'] );
	}

	public function testAnUnbuildableRequestIsReportedAsInvalid(): void {
		$tool = $this->createMock( RestBackedToolInterface::class );
		$tool->method( 'get_name' )->willReturn( 'list_posts' );
		$tool->method( 'get_description' )->willReturn( 'Read.' );
		$tool->method( 'is_cacheable' )->willReturn( true );
		$tool->method( 'build_rest_request' )->willReturn( null );

		$result = ( new AdminTool( $tool, $this->proposals() ) )->execute( [] );

		$this->assertSame( 'saltus_webmcp_request_invalid', $result['error']['code'] );
		$this->assertSame( 400, $result['error']['status'] );
	}

	public function testARestErrorStatusIsSurfacedWithItsCodeAndMessage(): void {
		global $wp_rest_response_override;

		$wp_rest_response_override = new \WP_REST_Response(
			[ 'code' => 'rest_forbidden', 'message' => 'Not allowed.' ],
			403
		);

		$result = ( new AdminTool( $this->read_tool(), $this->proposals() ) )->execute( [] );

		$this->assertSame( 'rest_forbidden', $result['error']['code'] );
		$this->assertSame( 'Not allowed.', $result['error']['message'] );
		$this->assertSame( 403, $result['error']['status'] );
	}

	public function testAnErrorResponseWithoutAPayloadStillReportsItsStatus(): void {
		global $wp_rest_response_override;

		$wp_rest_response_override = new \WP_REST_Response( 'plain string body', 500 );

		$result = ( new AdminTool( $this->read_tool(), $this->proposals() ) )->execute( [] );

		$this->assertSame( 'saltus_webmcp_dispatch_failed', $result['error']['code'] );
		$this->assertSame( 500, $result['error']['status'] );
	}

	public function testAScalarSuccessBodyIsWrappedInAResultKey(): void {
		global $wp_rest_response_override;

		$wp_rest_response_override = new \WP_REST_Response( 'just text', 200 );

		$result = ( new AdminTool( $this->read_tool(), $this->proposals() ) )->execute( [] );

		$this->assertSame( [ 'result' => 'just text' ], $result );
	}

	/**
	 * A read dispatches whether or not the review service is wired.
	 *
	 * The answer used to be inferred from cacheability when the service was
	 * absent, on the assumption that every read is cacheable. `get_context` and
	 * `export_post` are reads that are not, so both were refused as unreviewable
	 * writes. The tool table answers the same way in either arrangement.
	 */
	public function testAReadDispatchesWithoutTheReviewService(): void {
		$uncacheable_read = $this->createMock( RestBackedToolInterface::class );
		$uncacheable_read->method( 'get_name' )->willReturn( 'get_context' );
		$uncacheable_read->method( 'get_description' )->willReturn( 'Fetch.' );
		$uncacheable_read->method( 'is_cacheable' )->willReturn( false );
		$uncacheable_read->method( 'build_rest_request' )->willReturn( new \WP_REST_Request( 'GET', '/context' ) );

		$result = ( new AdminTool( $uncacheable_read, null ) )->execute( [] );

		$this->assertArrayNotHasKey( 'error', $result, 'A read must not be refused for want of a review queue.' );
	}

	/**
	 * A write with no review queue is refused rather than written directly.
	 */
	public function testAWriteIsRefusedWithoutTheReviewService(): void {
		$write = $this->createMock( RestBackedToolInterface::class );
		$write->method( 'get_name' )->willReturn( 'delete_post' );
		$write->method( 'get_description' )->willReturn( 'Delete.' );

		$result = ( new AdminTool( $write, null ) )->execute( [] );

		$this->assertSame( 'saltus_webmcp_review_unavailable', $result['error']['code'] );
	}

	/**
	 * With the service present, the mutation list is authoritative: a
	 * non-cacheable read like get_context dispatches rather than being queued.
	 */
	public function testWithTheServiceTheMutationListDecidesRatherThanCacheability(): void {
		$uncacheable_read = $this->createMock( RestBackedToolInterface::class );
		$uncacheable_read->method( 'get_name' )->willReturn( 'get_context' );
		$uncacheable_read->method( 'get_description' )->willReturn( 'Fetch.' );
		$uncacheable_read->method( 'is_cacheable' )->willReturn( false );
		$uncacheable_read->method( 'build_rest_request' )->willReturn( new \WP_REST_Request( 'GET', '/context' ) );

		$result = ( new AdminTool( $uncacheable_read, $this->proposals() ) )->execute( [] );

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertTrue( $result['ok'] );
	}

	public function testEveryMutatingAbilityIsQueuedRatherThanApplied(): void {
		global $wp_rest_request_log;

		foreach ( [ 'create_post', 'update_post', 'delete_post', 'create_term', 'update_meta_fields', 'update_settings', 'reorder_posts' ] as $name ) {
			$wp_rest_request_log = [];

			$result = ( new AdminTool( $this->write_tool( $name ), $this->proposals() ) )->execute( [ 'post_id' => 1 ] );

			$this->assertSame( 'pending', $result['status'] ?? null, $name . ' must be queued.' );
			$this->assertSame( [], $wp_rest_request_log, $name . ' must not dispatch.' );
		}
	}
}
