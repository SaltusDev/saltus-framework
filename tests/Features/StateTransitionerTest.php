<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Workflow\InvalidWorkflow;
use Saltus\WP\Framework\Features\Workflow\StateTransitioner;
use Saltus\WP\Framework\Features\Workflow\WorkflowRegistry;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/** @covers \Saltus\WP\Framework\Features\Workflow\StateTransitioner */
class StateTransitionerTest extends TestCase {

	private StateTransitioner $transitioner;

	protected function setUp(): void {
		global $wp_post_meta, $wp_meta_updates, $wp_posts, $wp_current_user_can;
		$wp_post_meta        = [];
		$wp_meta_updates     = [];
		$wp_current_user_can = true;

		// wp_update_post() only touches posts that exist, so seed the two the
		// tests transition.
		$wp_posts = [];
		foreach ( [ 1, 2 ] as $post_id ) {
			$post              = new \WP_Post();
			$post->ID          = $post_id;
			$post->post_type   = 'movie';
			$post->post_status = 'draft';
			$wp_posts[ $post_id ] = $post;
		}

		$registry = new WorkflowRegistry();
		$registry->register(
			'movie',
			[
				'states'      => [
					[ 'slug' => 'draft', 'label' => 'Draft' ],
					[ 'slug' => 'in_legal_review', 'label' => 'In Legal Review', 'notification' => 'legal@company.com' ],
					[ 'slug' => 'approved', 'label' => 'Approved' ],
					[ 'slug' => 'published', 'label' => 'Published', 'public' => true ],
					[ 'slug' => 'archived', 'label' => 'Archived' ],
				],
				'transitions' => [
					'submit_for_review' => [ 'from' => [ 'draft' ], 'to' => 'in_legal_review' ],
					'approve'           => [ 'from' => [ 'in_legal_review' ], 'to' => 'approved', 'capability' => 'publish_posts' ],
					'publish'           => [ 'from' => [ 'approved' ], 'to' => 'published', 'capability' => 'publish_posts', 'action_hook' => true ],
					'archive'           => [ 'from' => [ 'published' ], 'to' => 'archived' ],
					'reject'            => [ 'from' => [ 'in_legal_review', 'approved' ], 'to' => 'draft' ],
				],
			]
		);

		$this->transitioner = new StateTransitioner( $registry );
	}

	public function testUntouchedPostReportsTheInitialState(): void {
		$this->assertSame( 'draft', $this->transitioner->current_state( 'movie', 1 ) );
	}

	public function testTransitionMovesThePostForward(): void {
		$result = $this->transitioner->transition( 'movie', 1, 'submit_for_review' );

		$this->assertTrue( $result['transitioned'] );
		$this->assertSame( 'draft', $result['from'] );
		$this->assertSame( 'in_legal_review', $result['to'] );
		$this->assertSame( 'in_legal_review', $this->transitioner->current_state( 'movie', 1 ) );
	}

	public function testStateIsPersistedToMeta(): void {
		$this->transitioner->transition( 'movie', 1, 'submit_for_review' );

		$this->assertSame(
			'in_legal_review',
			get_post_meta( 1, StateTransitioner::META_KEY, true )
		);
	}

	public function testIllegalTransitionIsRefusedWithAnExplanation(): void {
		// publish requires 'approved'; the post is still in draft.
		$result = $this->transitioner->transition( 'movie', 1, 'publish' );

		$this->assertFalse( $result['transitioned'] );
		$this->assertStringContainsString( 'cannot be applied from state "draft"', $result['error'] );
		$this->assertSame( 'draft', $this->transitioner->current_state( 'movie', 1 ) );
	}

	public function testUnknownTransitionIsRefused(): void {
		$result = $this->transitioner->transition( 'movie', 1, 'teleport' );

		$this->assertFalse( $result['transitioned'] );
		$this->assertStringContainsString( 'no transition named "teleport"', $result['error'] );
	}

	public function testTransitionIsRefusedWithoutTheCapability(): void {
		$GLOBALS['wp_current_user_can'] = [ 'edit_posts' => true, 'publish_posts' => false ];

		$this->transitioner->transition( 'movie', 1, 'submit_for_review' );
		$result = $this->transitioner->transition( 'movie', 1, 'approve' );

		$this->assertFalse( $result['transitioned'] );
		$this->assertStringContainsString( 'do not have permission', $result['error'] );
		$this->assertSame( 'in_legal_review', $this->transitioner->current_state( 'movie', 1 ) );
	}

	public function testFullApprovalChainRunsEndToEnd(): void {
		$this->assertTrue( $this->transitioner->transition( 'movie', 1, 'submit_for_review' )['transitioned'] );
		$this->assertTrue( $this->transitioner->transition( 'movie', 1, 'approve' )['transitioned'] );
		$this->assertTrue( $this->transitioner->transition( 'movie', 1, 'publish' )['transitioned'] );
		$this->assertTrue( $this->transitioner->transition( 'movie', 1, 'archive' )['transitioned'] );

		$this->assertSame( 'archived', $this->transitioner->current_state( 'movie', 1 ) );
	}

	public function testATransitionMayHaveSeveralSourceStates(): void {
		$this->transitioner->transition( 'movie', 1, 'submit_for_review' );
		$this->assertTrue( $this->transitioner->transition( 'movie', 1, 'reject' )['transitioned'] );
		$this->assertSame( 'draft', $this->transitioner->current_state( 'movie', 1 ) );

		$this->transitioner->transition( 'movie', 1, 'submit_for_review' );
		$this->transitioner->transition( 'movie', 1, 'approve' );
		$this->assertTrue( $this->transitioner->transition( 'movie', 1, 'reject' )['transitioned'] );
	}

	public function testAvailableTransitionsReflectTheCurrentState(): void {
		$this->assertSame( [ 'submit_for_review' ], array_keys( $this->transitioner->available_transitions( 'movie', 1 ) ) );

		$this->transitioner->transition( 'movie', 1, 'submit_for_review' );

		$this->assertSame( [ 'approve', 'reject' ], array_keys( $this->transitioner->available_transitions( 'movie', 1 ) ) );
	}

	public function testAvailableTransitionsExcludeThoseTheUserCannotApply(): void {
		$GLOBALS['wp_current_user_can'] = [ 'edit_posts' => true, 'publish_posts' => false ];
		$this->transitioner->transition( 'movie', 1, 'submit_for_review' );

		// approve needs publish_posts; reject needs edit_posts.
		$this->assertSame( [ 'reject' ], array_keys( $this->transitioner->available_transitions( 'movie', 1 ) ) );
	}

	public function testCanTransitionReportsLegalityAndPermission(): void {
		$this->assertTrue( $this->transitioner->can_transition( 'movie', 1, 'submit_for_review' ) );
		$this->assertFalse( $this->transitioner->can_transition( 'movie', 1, 'publish' ) );
		$this->assertFalse( $this->transitioner->can_transition( 'movie', 1, 'nonexistent' ) );
	}

	public function testPublicStateSyncsPostStatusToPublish(): void {
		$this->transitioner->transition( 'movie', 1, 'submit_for_review' );
		$this->transitioner->transition( 'movie', 1, 'approve' );
		$this->transitioner->transition( 'movie', 1, 'publish' );

		$this->assertSame( 'publish', $GLOBALS['wp_posts'][1]->post_status );
	}

	public function testNonPublicStateKeepsPostOutOfPublicView(): void {
		$this->transitioner->transition( 'movie', 1, 'submit_for_review' );

		$this->assertSame( 'draft', $GLOBALS['wp_posts'][1]->post_status );
	}

	public function testArchivingAPublishedPostRemovesPublicVisibility(): void {
		$this->transitioner->transition( 'movie', 1, 'submit_for_review' );
		$this->transitioner->transition( 'movie', 1, 'approve' );
		$this->transitioner->transition( 'movie', 1, 'publish' );
		$this->assertSame( 'publish', $GLOBALS['wp_posts'][1]->post_status );

		$this->transitioner->transition( 'movie', 1, 'archive' );

		// An archived post must not stay publicly readable.
		$this->assertSame( 'draft', $GLOBALS['wp_posts'][1]->post_status );
	}

	public function testForceStateAlsoSyncsPostStatus(): void {
		$this->transitioner->force_state( 'movie', 1, 'published' );

		$this->assertSame( 'publish', $GLOBALS['wp_posts'][1]->post_status );
	}

	public function testHistoryRecordsEachMoveWithItsNote(): void {
		$this->transitioner->transition( 'movie', 1, 'submit_for_review', 'Ready for legal.' );
		$this->transitioner->transition( 'movie', 1, 'reject', 'Needs sources.' );

		$history = $this->transitioner->history( 1 );

		$this->assertCount( 2, $history );
		$this->assertSame( 'submit_for_review', $history[0]['transition'] );
		$this->assertSame( 'Ready for legal.', $history[0]['note'] );
		$this->assertSame( 'reject', $history[1]['transition'] );
		$this->assertSame( 'in_legal_review', $history[1]['from'] );
		$this->assertSame( 'draft', $history[1]['to'] );
	}

	public function testHistoryIsEmptyForAnUntouchedPost(): void {
		$this->assertSame( [], $this->transitioner->history( 1 ) );
	}

	public function testRefusedTransitionsAreNotRecorded(): void {
		$this->transitioner->transition( 'movie', 1, 'publish' );

		$this->assertSame( [], $this->transitioner->history( 1 ) );
	}

	public function testForceStateBypassesTransitionRules(): void {
		$this->assertTrue( $this->transitioner->force_state( 'movie', 1, 'published' ) );

		$this->assertSame( 'published', $this->transitioner->current_state( 'movie', 1 ) );
	}

	public function testForceStateRejectsUnknownStates(): void {
		$this->assertFalse( $this->transitioner->force_state( 'movie', 1, 'nowhere' ) );
		$this->assertSame( 'draft', $this->transitioner->current_state( 'movie', 1 ) );
	}

	public function testCurrentStateIgnoresAStoredStateNoLongerDeclared(): void {
		update_post_meta( 1, StateTransitioner::META_KEY, 'retired_state' );

		// A state removed from config must not leave posts stranded.
		$this->assertSame( 'draft', $this->transitioner->current_state( 'movie', 1 ) );
	}

	public function testUnknownModelThrows(): void {
		$this->expectException( InvalidWorkflow::class );
		$this->transitioner->current_state( 'unknown', 1 );
	}

	public function testTwoPostsTrackStateIndependently(): void {
		$this->transitioner->transition( 'movie', 1, 'submit_for_review' );

		$this->assertSame( 'in_legal_review', $this->transitioner->current_state( 'movie', 1 ) );
		$this->assertSame( 'draft', $this->transitioner->current_state( 'movie', 2 ) );
	}
}
