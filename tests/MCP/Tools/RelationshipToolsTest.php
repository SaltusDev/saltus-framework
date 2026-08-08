<?php
namespace Saltus\WP\Framework\Tests\MCP\Tools;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\EditorialReview\ProposalService;
use Saltus\WP\Framework\Features\EditorialReview\ProposalStore;
use Saltus\WP\Framework\MCP\Tools\AttachRelated;
use Saltus\WP\Framework\MCP\Tools\DetachRelated;
use Saltus\WP\Framework\MCP\Tools\GetRelated;
use Saltus\WP\Framework\MCP\Tools\ListRelationships;
use Saltus\WP\Framework\MCP\Tools\SyncRelated;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use WP_REST_Request;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\MCP\Tools\ListRelationships
 * @covers \Saltus\WP\Framework\MCP\Tools\GetRelated
 * @covers \Saltus\WP\Framework\MCP\Tools\AttachRelated
 * @covers \Saltus\WP\Framework\MCP\Tools\DetachRelated
 * @covers \Saltus\WP\Framework\MCP\Tools\SyncRelated
 */
class RelationshipToolsTest extends TestCase {

	protected function setUp(): void {
		global $wp_posts, $wp_current_user_can, $wp_post_type_objects;
		$wp_posts             = [];
		$wp_post_type_objects = [];
		$wp_current_user_can  = true;
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
