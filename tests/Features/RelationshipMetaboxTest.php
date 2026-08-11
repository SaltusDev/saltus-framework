<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Relationships\RelationshipManager;
use Saltus\WP\Framework\Features\Relationships\RelationshipMetabox;
use Saltus\WP\Framework\Features\Relationships\RelationshipRegistry;
use Saltus\WP\Framework\Features\Relationships\RelationshipStore;
use Saltus\WP\Framework\Models\ModelFactory;

require_once dirname( __DIR__ ) . '/Rest/functions.php';
require_once __DIR__ . '/RelationshipsTest.php';

/**
 * @covers \Saltus\WP\Framework\Features\Relationships\RelationshipMetabox
 */
class RelationshipMetaboxTest extends TestCase {

	protected function setUp(): void {
		global $wp_posts, $wp_meta_boxes, $wp_current_user_id, $wp_current_user_can, $wp_nonce_valid;

		$wp_posts            = [];
		$wp_meta_boxes       = [];
		$wp_current_user_id  = 1;
		$wp_current_user_can = true;
		$wp_nonce_valid      = true;
	}

	protected function tearDown(): void {
		global $wp_posts, $wp_meta_boxes, $wp_current_user_can, $wp_nonce_valid, $wp_post_revisions;

		// `$wp_posts` is shared and not every class resets it in setUp, so a post
		// left here can change an unrelated class's result under a random ordering.
		$wp_posts            = [];
		$wp_meta_boxes       = [];
		$wp_current_user_can = true;
		$wp_nonce_valid      = true;
		$wp_post_revisions   = [];
	}

	/** A valid submission carrying the nonce field the save path requires. */
	private function submission( array $relationships ): array {
		return [
			'saltus_relationships_nonce' => 'valid-nonce',
			'saltus_relationships'       => $relationships,
		];
	}

	private function seed_post( int $post_id, string $post_type, string $title = '' ): void {
		global $wp_posts;
		$post                 = new \WP_Post(
			[
				'post_type'  => $post_type,
				'post_title' => $title === '' ? $post_type . '-' . $post_id : $title,
			]
		);
		$post->ID             = $post_id;
		$wp_posts[ $post_id ] = $post;
	}

	/**
	 * Manager over a movie→person has_many, which is the shape most tests need.
	 */
	private function manager( ?RelationshipStore $store = null ): RelationshipManager {
		$models = [
			'movie'  => new RelationshipModel(
				'movie',
				[
					'actors' => [
						'type'       => 'has_many',
						'model'      => 'person',
						'reciprocal' => 'acted_in',
					],
				]
			),
			'person' => new RelationshipModel( 'person', [] ),
		];

		return new RelationshipManager(
			new RelationshipRegistry( new RelationshipModeler( $this->createStub( ModelFactory::class ), $models ) ),
			$store ?? new RelationshipStore( null )
		);
	}

	/** Manager whose single relationship is capped at one target. */
	private function single_manager(): RelationshipManager {
		$models = [
			'movie'  => new RelationshipModel(
				'movie',
				[
					'director' => [
						'type'       => 'has_one',
						'model'      => 'person',
						'reciprocal' => 'directed',
					],
				]
			),
			'person' => new RelationshipModel( 'person', [] ),
		];

		return new RelationshipManager(
			new RelationshipRegistry( new RelationshipModeler( $this->createStub( ModelFactory::class ), $models ) ),
			new RelationshipStore( null )
		);
	}

	public function testSaveStoresSubmittedIdsInOrder(): void {
		$this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person' );
		$this->seed_post( 21, 'person' );

		$manager = $this->manager();
		$metabox = new RelationshipMetabox( $manager );

		$metabox->save( 10, $this->submission( [ 'actors' => [ '21', '20' ] ] ) );

		$this->assertSame( [ 21, 20 ], $manager->get_related_ids( 10, 'movie', 'actors' ), 'Submitted order is the stored order.' );
	}

	/**
	 * The picker submits the whole set every time, so an emptied field means the
	 * editor removed everything — that has to clear, not be ignored.
	 */
	public function testSaveWithNoIdsClearsTheSet(): void {
		$this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person' );

		$manager = $this->manager();
		$metabox = new RelationshipMetabox( $manager );

		$metabox->save( 10, $this->submission( [ 'actors' => [ '20' ] ] ) );
		$this->assertSame( [ 20 ], $manager->get_related_ids( 10, 'movie', 'actors' ) );

		$metabox->save( 10, $this->submission( [ 'actors' => [] ] ) );
		$this->assertSame( [], $manager->get_related_ids( 10, 'movie', 'actors' ) );
	}

	/**
	 * A post saved by a path that never rendered the picker — REST, WP-CLI,
	 * another plugin — carries no nonce field. Absent that field there is no
	 * submission to apply, and treating it as an empty set would silently wipe
	 * every relationship on the post.
	 */
	public function testSaveWithoutNonceFieldLeavesStoredSetIntact(): void {
		$this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person' );

		$manager = $this->manager();
		$metabox = new RelationshipMetabox( $manager );

		$metabox->save( 10, $this->submission( [ 'actors' => [ '20' ] ] ) );
		$this->assertSame( [ 20 ], $manager->get_related_ids( 10, 'movie', 'actors' ) );

		// No nonce key at all, as a REST-driven save would arrive.
		$metabox->save( 10, [ 'post_title' => 'Renamed' ] );

		$this->assertSame( [ 20 ], $manager->get_related_ids( 10, 'movie', 'actors' ), 'A save with no picker submission must not clear relationships.' );
	}

	public function testSaveWithInvalidNonceLeavesStoredSetIntact(): void {
		global $wp_nonce_valid;

		$this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person' );

		$manager = $this->manager();
		$metabox = new RelationshipMetabox( $manager );

		$metabox->save( 10, $this->submission( [ 'actors' => [ '20' ] ] ) );

		$wp_nonce_valid = false;
		$metabox->save( 10, $this->submission( [ 'actors' => [] ] ) );

		$this->assertSame( [ 20 ], $manager->get_related_ids( 10, 'movie', 'actors' ) );
	}

	public function testSaveWithoutEditCapabilityLeavesStoredSetIntact(): void {
		global $wp_current_user_can;

		$this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person' );

		$manager = $this->manager();
		$metabox = new RelationshipMetabox( $manager );

		$metabox->save( 10, $this->submission( [ 'actors' => [ '20' ] ] ) );

		// Deny edit_post, as a contributor editing someone else's post would be.
		$wp_current_user_can = [ 'edit_post' => false ];
		$metabox->save( 10, $this->submission( [ 'actors' => [] ] ) );

		$this->assertSame( [ 20 ], $manager->get_related_ids( 10, 'movie', 'actors' ) );
	}

	/**
	 * Junk in the submitted list is dropped and the valid selection survives.
	 *
	 * The sanitizing happens in `PostIdListTrait::post_id_list()`, reached through
	 * `sync()` — the metabox deliberately does not filter again. This asserts the
	 * end-to-end behavior rather than where it lives, so moving the filter does
	 * not break the test while removing it does.
	 */
	public function testSaveDiscardsJunkIdsWithoutLosingValidOnes(): void {
		$this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person' );

		$manager = $this->manager();
		$metabox = new RelationshipMetabox( $manager );

		$metabox->save( 10, $this->submission( [ 'actors' => [ '-5', '0', '20', 'abc' ] ] ) );

		$this->assertSame(
			[ 20 ],
			$manager->get_related_ids( 10, 'movie', 'actors' ),
			'Junk ids must be dropped before sync, leaving the valid selection intact.'
		);
	}

	public function testSaveDeduplicatesRepeatedIds(): void {
		$this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person' );

		$manager = $this->manager();
		$metabox = new RelationshipMetabox( $manager );

		$metabox->save( 10, $this->submission( [ 'actors' => [ '20', '20', '20' ] ] ) );

		$this->assertSame( [ 20 ], $manager->get_related_ids( 10, 'movie', 'actors' ) );
	}

	/**
	 * WordPress fires save_post for a revision too. Applying the payload there
	 * would write the editor's set against the revision's id instead of the post.
	 *
	 * The sibling `DOING_AUTOSAVE` guard is deliberately not tested: asserting it
	 * needs `define()`, and a constant defined in one test leaks into every test
	 * that runs after it in the same process. The revision guard covers the same
	 * "save_post fired for something that is not the edit" case resettably.
	 */
	public function testRevisionSaveIsIgnored(): void {
		global $wp_post_revisions;

		$this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person' );

		$manager = $this->manager();
		$metabox = new RelationshipMetabox( $manager );
		$metabox->save( 10, $this->submission( [ 'actors' => [ '20' ] ] ) );

		$wp_post_revisions = [ 10 ];
		$metabox->save( 10, $this->submission( [ 'actors' => [] ] ) );

		$this->assertSame( [ 20 ], $manager->get_related_ids( 10, 'movie', 'actors' ) );
	}

	/** Cardinality is the manager's to enforce; the metabox must not bypass it. */
	public function testSingleValueRelationshipRejectsTwoIds(): void {
		$this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person' );
		$this->seed_post( 21, 'person' );

		$manager = $this->single_manager();
		$metabox = new RelationshipMetabox( $manager );

		$metabox->save( 10, $this->submission( [ 'director' => [ '20', '21' ] ] ) );

		$this->assertSame( [], $manager->get_related_ids( 10, 'movie', 'director' ), 'A has_one write carrying two ids must be refused, not truncated.' );
	}

	public function testRenderEmitsLabelBoundToInputId(): void {
		$this->seed_post( 10, 'movie' );

		$metabox = new RelationshipMetabox( $this->manager() );

		ob_start();
		$metabox->render( get_post( 10 ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'for="saltus-rel-actors"', $html );
		$this->assertStringContainsString( 'id="saltus-rel-actors"', $html );
	}

	public function testRenderLinksDescriptionWithAriaDescribedby(): void {
		$this->seed_post( 10, 'movie' );

		$metabox = new RelationshipMetabox( $this->manager() );

		ob_start();
		$metabox->render( get_post( 10 ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'aria-describedby="saltus-rel-actors-desc"', $html );
		$this->assertStringContainsString( 'id="saltus-rel-actors-desc"', $html );
	}

	public function testRenderListsStoredSelectionsWithSubmittableInputs(): void {
		$this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person', 'Sigourney Weaver' );

		$manager = $this->manager();
		$metabox = new RelationshipMetabox( $manager );
		$metabox->save( 10, $this->submission( [ 'actors' => [ '20' ] ] ) );

		ob_start();
		$metabox->render( get_post( 10 ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Sigourney Weaver', $html );
		$this->assertStringContainsString( 'name="saltus_relationships[actors][]"', $html );
		$this->assertStringContainsString( 'value="20"', $html );
	}

	public function testRenderMarksSingleValueRelationshipAsCapped(): void {
		$this->seed_post( 10, 'movie' );

		$metabox = new RelationshipMetabox( $this->single_manager() );

		ob_start();
		$metabox->render( get_post( 10 ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-multiple="0"', $html );
	}

	public function testMetaboxIsNotRegisteredForPostTypeWithNoRelationshipsAtAll(): void {
		global $wp_meta_boxes;

		$metabox = new RelationshipMetabox( $this->manager() );
		$metabox->add_meta_boxes( 'unrelated' );

		$this->assertSame( [], $wp_meta_boxes, 'A post type outside the relationship graph gets no picker.' );
	}

	/**
	 * The target of a declared relationship gets the picker too, for the
	 * synthesized reciprocal side. `person` declares nothing, but `movie.actors`
	 * gives it `acted_in`, and an editor on a person should be able to manage it
	 * from there — the registry treats both sides as first-class.
	 */
	public function testMetaboxIsRegisteredForReciprocalSide(): void {
		global $wp_meta_boxes;

		$metabox = new RelationshipMetabox( $this->manager() );
		$metabox->add_meta_boxes( 'person' );

		$this->assertNotSame( [], $wp_meta_boxes );
		$this->assertSame( 'person', $wp_meta_boxes[0]['screen'] );
	}

	public function testMetaboxIsRegisteredForPostTypeWithRelationships(): void {
		global $wp_meta_boxes;

		$metabox = new RelationshipMetabox( $this->manager() );
		$metabox->add_meta_boxes( 'movie' );

		$this->assertNotSame( [], $wp_meta_boxes );
		$this->assertSame( 'saltus-relationships', $wp_meta_boxes[0]['id'] );
		$this->assertSame( 'movie', $wp_meta_boxes[0]['screen'] );
	}
}
