<?php

namespace Saltus\WP\Framework\Tests\Rest;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\EditorialReview\ProposalService;
use Saltus\WP\Framework\Features\EditorialReview\ProposalStore;
use Saltus\WP\Framework\Rest\EditorialReviewController;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

require_once __DIR__ . '/functions.php';

/**
 * The REST surface of the AI proposal review queue: route shape, who may read
 * and act on a proposal, and how missing proposals are reported.
 *
 * @covers \Saltus\WP\Framework\Rest\EditorialReviewController
 */
class EditorialReviewControllerTest extends TestCase {

	private ProposalService $proposals;
	private EditorialReviewController $controller;

	protected function setUp(): void {
		global $wp_posts, $wp_rest_routes_registered, $wp_current_user_can, $wp_filter_values, $wp_rest_response_override;

		$wp_posts                  = [];
		$wp_rest_routes_registered = [];
		$wp_current_user_can       = true;
		$wp_filter_values          = [];
		$wp_rest_response_override = null;

		$this->proposals  = new ProposalService( new ProposalStore( null ) );
		$this->controller = new EditorialReviewController( $this->proposals );
	}

	/**
	 * Clean the shared globals this class writes to.
	 *
	 * Resetting in setUp only protects this class. The namespace override set by
	 * testRoutesFollowTheFilteredMcpNamespace would otherwise survive into
	 * whichever class the randomised order runs next.
	 */
	protected function tearDown(): void {
		global $wp_posts, $wp_rest_routes_registered, $wp_current_user_can, $wp_filter_values, $wp_rest_response_override;

		$wp_posts                  = [];
		$wp_rest_routes_registered = [];
		$wp_current_user_can       = true;
		$wp_filter_values          = [];
		$wp_rest_response_override = null;
	}

	private function request( array $params ): WP_REST_Request {
		return new WP_REST_Request( $params );
	}

	public function testRegistersTheFourReviewRoutes(): void {
		global $wp_rest_routes_registered;

		$this->controller->register_routes();

		$routes = array_column( $wp_rest_routes_registered, 'route' );

		$this->assertSame(
			[ '/proposals', '/proposals/(?P<id>[0-9]+)', '/proposals/(?P<id>[0-9]+)/approve', '/proposals/(?P<id>[0-9]+)/reject' ],
			$routes
		);

		foreach ( $wp_rest_routes_registered as $route ) {
			$this->assertSame( 'saltus-framework/v1', $route['namespace'] );
			$this->assertIsCallable( $route['args']['permission_callback'], 'Every route must be permission-gated.' );
		}
	}

	public function testReadRoutesAreReadableAndReviewActionsAreEditable(): void {
		global $wp_rest_routes_registered;

		$this->controller->register_routes();

		$methods = array_combine(
			array_column( $wp_rest_routes_registered, 'route' ),
			array_column( array_column( $wp_rest_routes_registered, 'args' ), 'methods' )
		);

		$this->assertSame( \WP_REST_Server::READABLE, $methods['/proposals'] );
		$this->assertSame( \WP_REST_Server::READABLE, $methods['/proposals/(?P<id>[0-9]+)'] );
		$this->assertSame( \WP_REST_Server::EDITABLE, $methods['/proposals/(?P<id>[0-9]+)/approve'] );
		$this->assertSame( \WP_REST_Server::EDITABLE, $methods['/proposals/(?P<id>[0-9]+)/reject'] );
	}

	public function testRoutesFollowTheFilteredMcpNamespace(): void {
		global $wp_filter_values, $wp_rest_routes_registered;

		$wp_filter_values['saltus/framework/mcp/namespace'] = 'acme/v3';

		$this->controller->register_routes();

		$this->assertSame( 'acme/v3', $wp_rest_routes_registered[0]['namespace'] );
	}

	public function testListDefaultsAreDeclaredOnTheCollectionRoute(): void {
		global $wp_rest_routes_registered;

		$this->controller->register_routes();
		$args = $wp_rest_routes_registered[0]['args']['args'];

		$this->assertSame( '', $args['status']['default'] );
		$this->assertSame( 50, $args['per_page']['default'] );
	}

	public function testGetItemsReturnsTheQueue(): void {
		$this->proposals->propose( 'create_post', [ 'post_type' => 'book', 'title' => 'One' ] );
		$this->proposals->propose( 'create_post', [ 'post_type' => 'book', 'title' => 'Two' ] );

		$response = $this->controller->get_items( $this->request( [ 'status' => '', 'per_page' => 50 ] ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertCount( 2, $response->get_data() );
	}

	public function testGetItemsFiltersByStatus(): void {
		$this->proposals->propose( 'create_post', [ 'post_type' => 'book', 'title' => 'Kept' ] );
		$this->proposals->propose( 'create_post', [ 'post_type' => 'book', 'title' => 'Rejected' ] );
		$this->proposals->reject( 2, 'no' );

		$pending = $this->controller->get_items( $this->request( [ 'status' => 'pending', 'per_page' => 50 ] ) )->get_data();

		$this->assertCount( 1, $pending );
		$this->assertSame( 'pending', $pending[0]['status'] );
	}

	public function testGetItemsHonoursPerPage(): void {
		foreach ( range( 1, 5 ) as $n ) {
			$this->proposals->propose( 'create_post', [ 'post_type' => 'book', 'title' => 'Book ' . $n ] );
		}

		$this->assertCount( 2, $this->controller->get_items( $this->request( [ 'status' => '', 'per_page' => 2 ] ) )->get_data() );
	}

	public function testGetItemReturnsOneProposal(): void {
		$this->proposals->propose( 'create_post', [ 'post_type' => 'book', 'title' => 'Findable' ] );

		$response = $this->controller->get_item( $this->request( [ 'id' => 1 ] ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 1, $response->get_data()['id'] );
	}

	public function testGetItemReportsAMissingProposalAsA404(): void {
		$response = $this->controller->get_item( $this->request( [ 'id' => 999 ] ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'proposal_not_found', $response->get_error_code() );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	public function testApproveAppliesTheProposalAndPersistsTheReview(): void {
		$this->proposals->propose( 'create_post', [ 'post_type' => 'book', 'title' => 'Approved', 'status' => 'draft' ] );

		$response = $this->controller->approve( $this->request( [ 'id' => 1, 'note' => 'Looks good.' ] ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'approved', $response->get_data()['status'] );

		// The response echoes the proposal as it was read, so the reviewer's note
		// is confirmed against the stored record rather than the payload.
		$stored = $this->proposals->get( 1 );
		$this->assertSame( 'approved', $stored['status'] );
		$this->assertSame( 'Looks good.', $stored['review_note'] );
	}

	public function testApproveRecordsTheResultingPost(): void {
		$this->proposals->propose( 'create_post', [ 'post_type' => 'book', 'title' => 'Approved', 'status' => 'draft' ] );

		$data = $this->controller->approve( $this->request( [ 'id' => 1, 'note' => '' ] ) )->get_data();

		$this->assertSame( 100, $data['result_post_id'] );
		$this->assertSame( 'Approved', $GLOBALS['wp_posts'][100]->post_title );
	}

	public function testRejectMarksTheProposalRejectedWithItsNote(): void {
		$this->proposals->propose( 'create_post', [ 'post_type' => 'book', 'title' => 'Rejected' ] );

		$response = $this->controller->reject( $this->request( [ 'id' => 1, 'note' => 'Not now.' ] ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'rejected', $response->get_data()['status'] );

		$stored = $this->proposals->get( 1 );
		$this->assertSame( 'rejected', $stored['status'] );
		$this->assertSame( 'Not now.', $stored['review_note'] );
	}

	/** A rejection must not create the post the proposal described. */
	public function testRejectDoesNotApplyTheChange(): void {
		$this->proposals->propose( 'create_post', [ 'post_type' => 'book', 'title' => 'Never', 'status' => 'draft' ] );

		$this->controller->reject( $this->request( [ 'id' => 1, 'note' => 'no' ] ) );

		$this->assertSame( [], $GLOBALS['wp_posts'] );
	}

	public function testApprovingAnUnknownProposalSurfacesTheError(): void {
		$result = $this->controller->approve( $this->request( [ 'id' => 999, 'note' => '' ] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function testRejectingAnUnknownProposalSurfacesTheError(): void {
		$result = $this->controller->reject( $this->request( [ 'id' => 999, 'note' => '' ] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * An already-reviewed proposal must not be approved twice: the second call
	 * would re-dispatch the mutation and create a duplicate post.
	 */
	public function testAProposalCannotBeReviewedTwice(): void {
		$this->proposals->propose( 'create_post', [ 'post_type' => 'book', 'title' => 'Once', 'status' => 'draft' ] );
		$this->controller->approve( $this->request( [ 'id' => 1, 'note' => '' ] ) );

		$second = $this->controller->approve( $this->request( [ 'id' => 1, 'note' => '' ] ) );

		$this->assertInstanceOf( WP_Error::class, $second );
	}

	public function testPermissionFallsBackToEditPostsWithoutAProposalId(): void {
		global $wp_current_user_can;

		$wp_current_user_can = [ 'edit_posts' => true ];
		$this->assertTrue( $this->controller->permissions_check( $this->request( [] ) ) );

		$wp_current_user_can = [ 'edit_posts' => false ];
		$this->assertFalse( $this->controller->permissions_check( $this->request( [] ) ) );
	}

	public function testPermissionFallsBackToEditPostsForANonRequestArgument(): void {
		global $wp_current_user_can;

		$wp_current_user_can = [ 'edit_posts' => true ];

		$this->assertTrue( $this->controller->permissions_check( null ) );
		$this->assertTrue( $this->controller->permissions_check( 'not-a-request' ) );
	}

	/**
	 * A proposal targeting an existing post is gated per-post, so an editor who
	 * cannot edit that specific post cannot approve a change to it.
	 */
	public function testPermissionIsCheckedAgainstTheTargetPostWhenOneExists(): void {
		global $wp_posts, $wp_current_user_can;

		$wp_posts[42] = new WP_Post( [ 'ID' => 42, 'post_type' => 'book' ] );

		$this->proposals->propose( 'update_post', [ 'post_id' => 42, 'post_type' => 'book', 'title' => 'Edit' ] );

		$wp_current_user_can = [ 'edit_post:42' => true, 'edit_posts' => false ];
		$this->assertTrue( $this->controller->permissions_check( $this->request( [ 'id' => 1 ] ) ) );

		$wp_current_user_can = [ 'edit_post:42' => false, 'edit_posts' => true ];
		$this->assertFalse(
			$this->controller->permissions_check( $this->request( [ 'id' => 1 ] ) ),
			'Per-post denial must not be rescued by the generic edit_posts capability.'
		);
	}

	/**
	 * A create proposal has no target post yet, so there is nothing to check
	 * per-post and the generic capability applies.
	 */
	public function testPermissionUsesTheGenericCapabilityWhenTheProposalHasNoPost(): void {
		global $wp_current_user_can;

		$this->proposals->propose( 'create_post', [ 'post_type' => 'book', 'title' => 'New' ] );

		$wp_current_user_can = [ 'edit_posts' => true, 'edit_post:0' => false ];
		$this->assertTrue( $this->controller->permissions_check( $this->request( [ 'id' => 1 ] ) ) );
	}

	/**
	 * If the target post was deleted between proposal and review, the per-post
	 * check has nothing to resolve and the generic capability governs.
	 */
	public function testPermissionFallsBackWhenTheTargetPostNoLongerExists(): void {
		global $wp_current_user_can;

		$this->proposals->propose( 'update_post', [ 'post_id' => 4242, 'post_type' => 'book', 'title' => 'Edit' ] );

		$wp_current_user_can = [ 'edit_posts' => true ];
		$this->assertTrue( $this->controller->permissions_check( $this->request( [ 'id' => 1 ] ) ) );

		$wp_current_user_can = [ 'edit_posts' => false ];
		$this->assertFalse( $this->controller->permissions_check( $this->request( [ 'id' => 1 ] ) ) );
	}

	public function testPermissionFallsBackForAnUnknownProposalId(): void {
		global $wp_current_user_can;

		$wp_current_user_can = [ 'edit_posts' => false ];

		$this->assertFalse( $this->controller->permissions_check( $this->request( [ 'id' => 999 ] ) ) );
	}

	public function testControllerBuildsItsOwnServiceWhenNoneIsInjected(): void {
		$controller = new EditorialReviewController();

		// A default-constructed controller must still register routes rather than
		// fataling on a null service.
		$controller->register_routes();

		$this->assertCount( 4, $GLOBALS['wp_rest_routes_registered'] );
	}
}
