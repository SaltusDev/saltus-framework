<?php

namespace Saltus\WP\Framework\Tests\Models;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Models\Config\NoFile;
use Saltus\WP\Framework\Models\PostType;
use WP_Post;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * The admin notice text a model contributes via post_updated_messages and
 * bulk_post_updated_messages.
 *
 * @covers \Saltus\WP\Framework\Models\BaseModel
 */
class ModelMessagesTest extends TestCase {

	protected function setUp(): void {
		global $wp_posts, $wp_post_type_objects, $wp_post_revisions, $wp_post_revision_titles, $post;

		$wp_posts                = [];
		$wp_post_type_objects    = [];
		$wp_post_revisions       = [];
		$wp_post_revision_titles = [];
		unset( $_GET['revision'] );

		// The filters run on an edit screen, where the global $post is the one
		// being saved and a bare get_post() resolves to it.
		$post        = new WP_Post(
			[
				'ID'        => 1,
				'post_type' => 'movie',
				'post_date' => '2026-03-04 15:30:00',
			]
		);
		$wp_posts[1] = $post;
	}

	protected function tearDown(): void {
		global $post;

		unset( $_GET['revision'] );
		$post = null;
	}

	private function model( array $config = [] ): PostType {
		$model = new PostType( new NoFile( $config + [ 'name' => 'movie', 'type' => 'post_type' ] ) );
		$model->setup();

		return $model;
	}

	/** Make get_post() return the seeded post, as it would on an edit screen. */
	private function on_edit_screen( bool $publicly_queryable = true ): void {
		global $wp_post_type_objects;

		$wp_post_type_objects['movie'] = (object) [
			'name'               => 'movie',
			'publicly_queryable' => $publicly_queryable,
		];
	}

	private function messages( array $config = [], bool $publicly_queryable = true ): array {
		$this->on_edit_screen( $publicly_queryable );

		return $this->model( $config )->post_updated_messages( [] )['movie'];
	}

	public function testMessagesAreKeyedByPostTypeAndCoverEveryWordPressSlot(): void {
		$this->on_edit_screen();

		$messages = $this->model()->post_updated_messages( [] );

		$this->assertArrayHasKey( 'movie', $messages );
		// WordPress reads slots 1-10; a gap shows as a blank admin notice.
		$this->assertSame( range( 1, 10 ), array_keys( $messages['movie'] ) );
	}

	public function testExistingMessagesForOtherPostTypesArePreserved(): void {
		$this->on_edit_screen();

		$messages = $this->model()->post_updated_messages( [ 'page' => [ 1 => 'Page updated.' ] ] );

		$this->assertSame( [ 1 => 'Page updated.' ], $messages['page'] );
	}

	public function testPubliclyQueryableTypesGetViewAndPreviewLinks(): void {
		$messages = $this->messages();

		$this->assertStringContainsString( 'http://example.com/?p=1', $messages[1] );
		$this->assertStringContainsString( 'View movie', $messages[1] );
		$this->assertStringContainsString( 'preview=true', $messages[8] );
		$this->assertStringContainsString( 'Preview movie', $messages[10] );
	}

	/**
	 * A private post type has no public URL, so offering "View" or "Preview"
	 * would link the editor to a 404.
	 */
	public function testPrivateTypesGetPlainMessagesWithoutLinks(): void {
		$messages = $this->messages( [], false );

		$this->assertSame( 'Movie updated.', $messages[1] );
		$this->assertSame( 'Movie published.', $messages[6] );
		$this->assertSame( 'Movie submitted.', $messages[8] );
		$this->assertSame( 'Movie draft updated.', $messages[10] );
		$this->assertStringNotContainsString( '<a', $messages[1] );
		$this->assertStringNotContainsString( '<a', $messages[9] );
	}

	public function testScheduledMessageCarriesTheFormattedPostDate(): void {
		$messages = $this->messages();

		$this->assertStringContainsString( 'Mar 4, 2026', $messages[9] );
		$this->assertStringContainsString( 'scheduled for', $messages[9] );
	}

	public function testCustomFieldSlotsUseTheGenericWordPressText(): void {
		$messages = $this->messages();

		$this->assertSame( 'Custom field updated.', $messages[2] );
		$this->assertSame( 'Custom field deleted.', $messages[3] );
	}

	public function testSingularLabelDrivesTheMessageText(): void {
		$messages = $this->messages( [ 'labels' => [ 'has_one' => 'Film', 'has_many' => 'Films' ] ] );

		$this->assertStringContainsString( 'Film updated', $messages[1] );
		$this->assertStringContainsString( 'View film', $messages[1] );
	}

	/**
	 * Slot 5 is the "restored to revision" notice, which only applies when the
	 * screen was reached from a revision — otherwise it must be false so
	 * WordPress skips it.
	 */
	public function testRevisionSlotIsFalseWithoutARevisionInTheQuery(): void {
		$this->assertFalse( $this->messages()[5] );
	}

	public function testRevisionSlotNamesTheRevisionWhenPresent(): void {
		global $wp_post_revision_titles;

		$_GET['revision']          = '77';
		$wp_post_revision_titles[77] = 'March 1, 2026 @ 09:00';

		$messages = $this->messages();

		$this->assertSame( 'Movie restored to revision from March 1, 2026 @ 09:00', $messages[5] );
	}

	public function testRevisionIdIsCoercedToAPositiveInteger(): void {
		global $wp_post_revision_titles;

		$_GET['revision']          = '-77abc';
		$wp_post_revision_titles[77] = 'March 1, 2026 @ 09:00';

		$this->assertStringContainsString( 'March 1, 2026 @ 09:00', $this->messages()[5] );
	}

	public function testOverriddenMessagesReplaceTheDefaults(): void {
		$messages = $this->messages(
			[ 'labels' => [ 'overrides' => [ 'messages' => [ 'post_published' => 'Movie is live!' ] ] ] ]
		);

		$this->assertSame( 'Movie is live!', $messages[6] );
		$this->assertStringContainsString( 'updated', $messages[1], 'Unlisted slots keep their defaults.' );
	}

	public function testOverriddenMessagesExpandPlaceholders(): void {
		$messages = $this->messages(
			[
				'labels' => [
					'overrides' => [
						'messages' => [
							'post_published' => 'Live at {permalink} on {date}. Preview: {preview_url}',
						],
					],
				],
			]
		);

		$this->assertStringContainsString( 'http://example.com/?p=1', $messages[6] );
		$this->assertStringContainsString( 'Mar 4, 2026', $messages[6] );
		$this->assertStringContainsString( 'preview=true', $messages[6] );
		$this->assertStringNotContainsString( '{permalink}', $messages[6] );
		$this->assertStringNotContainsString( '{date}', $messages[6] );
		$this->assertStringNotContainsString( '{preview_url}', $messages[6] );
	}

	public function testMessagesAreUntouchedWhenThereIsNoCurrentPost(): void {
		global $post, $wp_posts;

		// Outside an edit screen there is no current post; building a permalink
		// for a post that does not exist would emit a bogus link.
		$post     = null;
		$wp_posts = [];
		$this->on_edit_screen();

		$this->assertSame( [ 'page' => [] ], $this->model()->post_updated_messages( [ 'page' => [] ] ) );
	}

	public function testMessagesAreUntouchedWhenThePostTypeIsNotRegistered(): void {
		global $wp_post_type_objects;

		$wp_post_type_objects['movie'] = null;

		$this->assertSame( [], $this->model()->post_updated_messages( [] ) );
	}

	public function testBulkMessagesCoverEverySlotAndPluralizeOnCount(): void {
		$counts = [ 'updated' => 1, 'locked' => 2, 'deleted' => 3, 'trashed' => 4, 'untrashed' => 5 ];

		$bulk = $this->model()->bulk_post_updated_messages( [], $counts )['movie'];

		$this->assertSame( [ 'updated', 'locked', 'deleted', 'trashed', 'untrashed' ], array_keys( $bulk ) );
		$this->assertSame( 'Movie updated.', $bulk['updated'], 'A count of 1 uses the singular label.' );
		$this->assertSame( '2 movies not updated, somebody is editing them.', $bulk['locked'] );
		$this->assertSame( '3 movies permanently deleted.', $bulk['deleted'] );
		$this->assertSame( '4 movies moved to the trash.', $bulk['trashed'] );
		$this->assertSame( '5 movies restored from the trash.', $bulk['untrashed'] );
	}

	public function testBulkMessagesUseTheSingularFormForOneItem(): void {
		$counts = [ 'updated' => 1, 'locked' => 1, 'deleted' => 1, 'trashed' => 1, 'untrashed' => 1 ];

		$bulk = $this->model()->bulk_post_updated_messages( [], $counts )['movie'];

		$this->assertSame( 'Movie not updated, somebody is editing it.', $bulk['locked'] );
		$this->assertSame( 'Movie permanently deleted.', $bulk['deleted'] );
		$this->assertSame( 'Movie moved to the trash.', $bulk['trashed'] );
		$this->assertSame( 'Movie restored from the trash.', $bulk['untrashed'] );
	}

	/**
	 * An override only applies when both halves of the pair are present: with one
	 * missing there is no correct form to use for the other count.
	 */
	public function testBulkOverridesRequireBothSingularAndPlural(): void {
		$counts = [ 'updated' => 2, 'locked' => 2, 'deleted' => 2, 'trashed' => 2, 'untrashed' => 2 ];

		$partial = $this->model(
			[ 'labels' => [ 'overrides' => [ 'bulk_messages' => [ 'updated_singular' => 'One film updated.' ] ] ] ]
		)->bulk_post_updated_messages( [], $counts )['movie'];

		$this->assertSame( '2 movies updated.', $partial['updated'] );

		$complete = $this->model(
			[
				'labels' => [
					'overrides' => [
						'bulk_messages' => [
							'updated_singular' => 'One film updated.',
							'updated_plural'   => 'Several films updated.',
						],
					],
				],
			]
		)->bulk_post_updated_messages( [], $counts )['movie'];

		$this->assertSame( 'Several films updated.', $complete['updated'] );
	}

	public function testBulkOverridesPickTheSingularFormForOneItem(): void {
		$counts = [ 'updated' => 1, 'locked' => 1, 'deleted' => 1, 'trashed' => 1, 'untrashed' => 1 ];

		$bulk = $this->model(
			[
				'labels' => [
					'overrides' => [
						'bulk_messages' => [
							'updated_singular' => 'One film updated.',
							'updated_plural'   => 'Several films updated.',
						],
					],
				],
			]
		)->bulk_post_updated_messages( [], $counts )['movie'];

		$this->assertSame( 'One film updated.', $bulk['updated'] );
	}

	public function testBulkMessagesPreserveOtherPostTypes(): void {
		$counts = [ 'updated' => 1, 'locked' => 0, 'deleted' => 0, 'trashed' => 0, 'untrashed' => 0 ];

		$messages = $this->model()->bulk_post_updated_messages( [ 'page' => [ 'updated' => 'Pages updated.' ] ], $counts );

		$this->assertSame( [ 'updated' => 'Pages updated.' ], $messages['page'] );
	}

	public function testUpdateMessageFiltersAreRegisteredDuringSetup(): void {
		global $wp_filters_registered;

		$wp_filters_registered = [];
		$this->model();

		$this->assertArrayHasKey( 'post_updated_messages', $wp_filters_registered );
		$this->assertArrayHasKey( 'bulk_post_updated_messages', $wp_filters_registered );
		$this->assertSame( 1, $wp_filters_registered['post_updated_messages'][0]['priority'] );
		$this->assertSame( 2, $wp_filters_registered['bulk_post_updated_messages'][0]['accepted_args'] );
	}
}
