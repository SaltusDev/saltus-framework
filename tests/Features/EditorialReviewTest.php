<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\EditorialReview\ProposalService;
use Saltus\WP\Framework\Features\EditorialReview\ProposalStore;
use Saltus\WP\Framework\Rest\EditorialReviewController;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/** @covers \Saltus\WP\Framework\Features\EditorialReview\ProposalStore */
/** @covers \Saltus\WP\Framework\Features\EditorialReview\ProposalService */
class EditorialReviewTest extends TestCase {

	protected function setUp(): void {
		global $wp_posts, $wp_rest_routes_registered;
		$wp_posts                  = [];
		$wp_rest_routes_registered = [];
	}

	public function testProposalLifecycleCreatesPendingRecordAndRejectsIt(): void {
		$service = new ProposalService( new ProposalStore( null ) );

		$created = $service->propose( 'create_post', [ 'post_type' => 'book', 'title' => 'Draft book', 'status' => 'draft' ] );

		$this->assertSame( 'pending', $created['status'] );
		$this->assertSame( 1, $created['proposal_id'] );
		$this->assertCount( 1, $service->list( 'pending' ) );
		$this->assertSame(
			[
				'action' => 'create',
				'before' => [],
				'after'  => [
					'post_title'  => 'Draft book',
					'post_status' => 'publish',
				],
			],
			$service->get( 1 )['changeset']
		);

		$rejected = $service->reject( 1, 'Needs source review.' );

		$this->assertIsArray( $rejected );
		$this->assertSame( 'rejected', $rejected['status'] );
		$this->assertEmpty( $service->list( 'pending' ) );
	}

	public function testApprovalAppliesCreateAndRecordsResult(): void {
		$service = new ProposalService( new ProposalStore( null ) );
		$service->propose( 'create_post', [ 'post_type' => 'book', 'title' => 'Approved book', 'content' => 'Text', 'status' => 'draft' ] );

		$approved = $service->approve( 1 );

		$this->assertIsArray( $approved );
		$this->assertSame( 'approved', $approved['status'] );
		$this->assertSame( 100, $approved['result_post_id'] );
		$this->assertSame( 'Approved book', $GLOBALS['wp_posts'][100]->post_title );
		$this->assertSame( 'publish', $GLOBALS['wp_posts'][100]->post_status );
	}

	public function testControllerRegistersReviewRoutes(): void {
		$controller = new EditorialReviewController( new ProposalService( new ProposalStore( null ) ) );
		$controller->register_routes();

		$this->assertCount( 4, $GLOBALS['wp_rest_routes_registered'] );
		$routes = array_column( $GLOBALS['wp_rest_routes_registered'], 'route' );
		$this->assertContains( '/proposals', $routes );
	}

	public function testAllMutatingAbilityNamesAreReviewableByDefault(): void {
		$service = new ProposalService( new ProposalStore( null ) );

		foreach ( [ 'create_post', 'update_post', 'delete_post', 'create_term', 'duplicate_post', 'update_meta_fields', 'update_settings', 'reorder_posts' ] as $tool ) {
			$this->assertTrue( $service->should_queue( $tool ), $tool );
		}
		$this->assertFalse( $service->should_queue( 'list_posts' ) );
	}

	public function testApprovalUsesExistingRestDispatcherForDuplicateMutation(): void {
		global $wp_rest_response_override;
		$wp_rest_response_override = new \WP_REST_Response( [ 'id' => 101 ], 200 );
		$service                  = new ProposalService( new ProposalStore( null ) );
		$service->propose( 'duplicate_post', [ 'post_id' => 42, 'post_type' => 'book' ] );

		$approved = $service->approve( 1 );

		$this->assertIsArray( $approved );
		$this->assertSame( 'approved', $approved['status'] );
		$this->assertSame( 101, $approved['result_post_id'] );
	}
}
