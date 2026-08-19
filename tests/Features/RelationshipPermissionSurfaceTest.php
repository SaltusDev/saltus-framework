<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Relationships\RelationshipManager;
use Saltus\WP\Framework\Features\Relationships\RelationshipMetabox;
use Saltus\WP\Framework\Features\Relationships\RelationshipPermissionPolicy;
use Saltus\WP\Framework\Features\Relationships\RelationshipRegistry;
use Saltus\WP\Framework\Features\Relationships\RelationshipStore;
use Saltus\WP\Framework\Models\ModelFactory;

require_once dirname( __DIR__ ) . '/Rest/functions.php';
require_once __DIR__ . '/RelationshipsTest.php';

/**
 * Enforcement of `capabilities` at the manager, and at the surfaces above it.
 *
 * `RelationshipPermissionPolicyTest` proves the policy resolves correctly. These
 * prove it actually runs — the distinction that mattered in Phase 11, where
 * `reject_query_arguments()` was written, tested, and committed without being called
 * anywhere. A unit test on a guard says nothing about whether the guard is wired.
 *
 * @covers \Saltus\WP\Framework\Features\Relationships\RelationshipManager
 * @covers \Saltus\WP\Framework\Features\Relationships\RelationshipMetabox
 */
class RelationshipPermissionSurfaceTest extends TestCase {

	protected function setUp(): void {
		global $wp_posts, $wp_current_user_can, $wp_filter_values, $wp_filters_registered;

		$wp_posts              = [];
		$wp_current_user_can   = true;
		$wp_filter_values      = [];
		$wp_filters_registered = [];
	}

	protected function tearDown(): void {
		global $wp_posts, $wp_current_user_can, $wp_filter_values, $wp_filters_registered;

		$wp_posts              = [];
		$wp_current_user_can   = true;
		$wp_filter_values      = [];
		$wp_filters_registered = [];
	}

	private function seed_post( int $post_id, string $post_type ): \WP_Post {
		global $wp_posts;

		$post                 = new \WP_Post(
			[
				'post_type'  => $post_type,
				'post_title' => $post_type . '-' . $post_id,
			]
		);
		$post->ID             = $post_id;
		$wp_posts[ $post_id ] = $post;

		return $post;
	}

	/**
	 * A manager whose `actors` relationship carries the given rule.
	 *
	 * @param array<string, mixed> $capabilities Rule to declare, or [] for none.
	 * @param list<string>         $granted      Capabilities the caller holds.
	 */
	private function manager(
		array $capabilities = [],
		array $granted = [],
		?RelationshipStore $store = null
	): RelationshipManager {
		$declaration = [
			'type'       => 'has_many',
			'model'      => 'person',
			'reciprocal' => 'acted_in',
		];

		if ( $capabilities !== [] ) {
			$declaration['capabilities'] = $capabilities;
		}

		$models = [
			'movie'  => new RelationshipModel( 'movie', [ 'actors' => $declaration ] ),
			'person' => new RelationshipModel( 'person', [] ),
		];

		return new RelationshipManager(
			new RelationshipRegistry(
				new RelationshipModeler( $this->createStub( ModelFactory::class ), $models )
			),
			$store ?? new RelationshipStore( null ),
			new RelationshipPermissionPolicy(
				static function ( string $capability ) use ( $granted ): bool {
					return in_array( $capability, $granted, true );
				}
			)
		);
	}

	// -- Writes ------------------------------------------------------------

	public function testAttachIsRefusedWhenWriteIsDenied(): void {
		$this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person' );

		$manager = $this->manager( [ 'write' => [ 'manage_cast' ] ] );

		$result = $manager->attach( 10, 'movie', 'actors', 20 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_relationship_forbidden', $result->get_error_code() );
	}

	public function testDetachIsRefusedWhenWriteIsDenied(): void {
		$this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person' );

		$manager = $this->manager( [ 'write' => [ 'manage_cast' ] ] );

		$result = $manager->detach( 10, 'movie', 'actors', 20 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_relationship_forbidden', $result->get_error_code() );
	}

	public function testSyncIsRefusedWhenWriteIsDenied(): void {
		$this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person' );

		$manager = $this->manager( [ 'write' => [ 'manage_cast' ] ] );

		$result = $manager->sync( 10, 'movie', 'actors', [ 20 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_relationship_forbidden', $result->get_error_code() );
	}

	public function testRefusedSyncLeavesStoredRowsIntact(): void {
		$this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person' );
		$this->seed_post( 30, 'person' );

		$store = new RelationshipStore( null );

		// Seeded through a permitted manager, then refused through a denied one, so the
		// rows exist before the refusal.
		$this->manager( [], [], $store )->sync( 10, 'movie', 'actors', [ 20 ] );

		$denied = $this->manager( [ 'write' => [ 'manage_cast' ] ], [], $store );
		$result = $denied->sync( 10, 'movie', 'actors', [ 30 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		// The gate runs before anything is cleared, matching every other sync check: a
		// refused call must leave the set exactly as it was, never half-applied.
		$this->assertSame(
			[ 20 ],
			$this->manager( [], [], $store )->get_related_ids( 10, 'movie', 'actors' )
		);
	}

	public function testWriteIsAllowedWhenTheCapabilityIsHeld(): void {
		$this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person' );

		$manager = $this->manager( [ 'write' => [ 'manage_cast' ] ], [ 'manage_cast' ] );

		$this->assertNotInstanceOf( \WP_Error::class, $manager->attach( 10, 'movie', 'actors', 20 ) );
	}

	public function testWriteThroughTheReciprocalIsRefusedToo(): void {
		$this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person' );

		$manager = $this->manager( [ 'write' => [ 'manage_cast' ] ] );

		// The far-end bypass. `person.acted_in` is synthesized, writes the same row,
		// and is the obvious way around a rule declared on `movie.actors`.
		$result = $manager->attach( 20, 'person', 'acted_in', 10 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_relationship_forbidden', $result->get_error_code() );
	}

	// -- Reads -------------------------------------------------------------

	public function testReadsReturnNothingWhenReadIsDenied(): void {
		$this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person' );

		$store = new RelationshipStore( null );
		$this->manager( [], [], $store )->sync( 10, 'movie', 'actors', [ 20 ] );

		$denied = $this->manager( [ 'read' => [ 'view_cast' ] ], [], $store );

		$this->assertSame( [], $denied->get_related_ids( 10, 'movie', 'actors' ) );
		$this->assertSame( [], $denied->get_related( 10, 'movie', 'actors' ) );
		$this->assertSame( [], $denied->get_related_for_posts( [ 10 ], 'movie', 'actors' ) );
	}

	public function testDescribeOmitsRelationshipsTheCallerCannotRead(): void {
		$denied = $this->manager( [ 'read' => [ 'view_cast' ] ] );

		$this->assertSame( [], $denied->describe( 'movie' ) );

		// Filtered rather than refused: one denied relationship must not hide every
		// other one on the post type.
		$allowed = $this->manager( [ 'read' => [ 'view_cast' ] ], [ 'view_cast' ] );
		$this->assertCount( 1, $allowed->describe( 'movie' ) );
	}

	public function testWriteDenialDoesNotHideReads(): void {
		$this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person' );

		$store = new RelationshipStore( null );
		$this->manager( [], [], $store )->sync( 10, 'movie', 'actors', [ 20 ] );

		$readonly = $this->manager( [ 'write' => [ 'manage_cast' ] ], [], $store );

		$this->assertSame( [ 20 ], $readonly->get_related_ids( 10, 'movie', 'actors' ) );
		$this->assertCount( 1, $readonly->describe( 'movie' ) );
	}

	// -- Cascade exemption -------------------------------------------------

	public function testCascadeResolvesTargetsEvenWhenReadIsDenied(): void {
		$this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person' );

		$store  = new RelationshipStore( null );
		$models = [
			'movie'  => new RelationshipModel(
				'movie',
				[
					'actors' => [
						'type'           => 'has_many',
						'model'          => 'person',
						'cascade_delete' => true,
						'capabilities'   => [ 'read' => [ 'view_cast' ] ],
					],
				]
			),
			'person' => new RelationshipModel( 'person', [] ),
		];

		$registry = new RelationshipRegistry(
			new RelationshipModeler( $this->createStub( ModelFactory::class ), $models )
		);

		// Seed with a permissive policy, then delete with a denying one.
		$seeder = new RelationshipManager( $registry, $store, new RelationshipPermissionPolicy( static fn (): bool => true ) );
		$seeder->sync( 10, 'movie', 'actors', [ 20 ] );

		$denied = new RelationshipManager( $registry, $store, new RelationshipPermissionPolicy( static fn (): bool => false ) );

		$deleted = $denied->delete_all_for_post( 10, 'movie' );

		// Cascade is not a user action. Routing it through the gated read would make a
		// delete by a read-denied user silently strand every cascade target, leaving
		// rows pointing at a post that no longer exists. Integrity is not a permission
		// question, and the delete itself is already authorized by whatever allowed the
		// post to be deleted.
		$this->assertSame( [ 20 ], $deleted );
	}

	// -- Metabox -----------------------------------------------------------

	public function testMetaboxRendersReadOnlyWhenWriteIsDenied(): void {
		$post = $this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person' );

		$store = new RelationshipStore( null );
		$this->manager( [], [], $store )->sync( 10, 'movie', 'actors', [ 20 ] );

		$manager = $this->manager( [ 'write' => [ 'manage_cast' ] ], [], $store );

		ob_start();
		( new RelationshipMetabox( $manager ) )->render( $post );
		$markup = (string) ob_get_clean();

		$this->assertStringContainsString( 'saltus-relationship-field--readonly', $markup );
		$this->assertStringContainsString( 'person-20', $markup, 'Values stay visible: read was permitted.' );
		// No hidden input means nothing is submitted for this relationship, and no
		// data attribute means the picker script never binds a control. A disabled
		// control is still a control; re-enabling it must not yield a submittable field.
		$this->assertStringNotContainsString( 'saltus_relationships[actors]', $markup );
		$this->assertStringNotContainsString( 'data-saltus-relationship', $markup );
		$this->assertStringNotContainsString( 'saltus-relationship-remove', $markup );
	}

	public function testMetaboxRendersTheFullPickerWhenWriteIsAllowed(): void {
		$post = $this->seed_post( 10, 'movie' );

		$manager = $this->manager( [ 'write' => [ 'manage_cast' ] ], [ 'manage_cast' ] );

		ob_start();
		( new RelationshipMetabox( $manager ) )->render( $post );
		$markup = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-saltus-relationship', $markup );
		$this->assertStringNotContainsString( 'saltus-relationship-field--readonly', $markup );
	}

	public function testMetaboxOmitsTheFieldEntirelyWhenReadIsDenied(): void {
		$post = $this->seed_post( 10, 'movie' );

		$manager = $this->manager( [ 'read' => [ 'view_cast' ] ] );

		ob_start();
		( new RelationshipMetabox( $manager ) )->render( $post );
		$markup = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'saltus-relationship-field', $markup );
	}

	public function testReadOnlyRenderDoesNotClearOnSave(): void {
		$this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person' );

		$store = new RelationshipStore( null );
		$this->manager( [], [], $store )->sync( 10, 'movie', 'actors', [ 20 ] );

		$manager = $this->manager( [ 'write' => [ 'manage_cast' ] ], [], $store );

		// The read-only render submits no inputs, so the payload looks like an emptied
		// picker. Without the per-relationship write check this save would clear every
		// row — the absent-nonce failure, one relationship at a time.
		( new RelationshipMetabox( $manager ) )->save(
			10,
			[
				'saltus_relationships_nonce' => 'valid',
				'saltus_relationships'       => [],
			]
		);

		$this->assertSame(
			[ 20 ],
			$this->manager( [], [], $store )->get_related_ids( 10, 'movie', 'actors' )
		);
	}
}
