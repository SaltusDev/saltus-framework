<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Relationships\RelationshipDefinition;
use Saltus\WP\Framework\Features\Relationships\RelationshipManager;
use Saltus\WP\Framework\Features\Relationships\RelationshipRegistry;
use Saltus\WP\Framework\Features\Relationships\RelationshipStore;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Models\ModelFactory;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\Features\Relationships\RelationshipDefinition
 * @covers \Saltus\WP\Framework\Features\Relationships\RelationshipRegistry
 * @covers \Saltus\WP\Framework\Features\Relationships\RelationshipStore
 * @covers \Saltus\WP\Framework\Features\Relationships\RelationshipManager
 */
class RelationshipsTest extends TestCase {

	protected function setUp(): void {
		global $wp_posts;
		$wp_posts = [];
	}

	/**
	 * Seed a post the stubs can resolve through get_post().
	 */
	private function seed_post( int $post_id, string $post_type, string $title = '' ): void {
		global $wp_posts;
		$post              = new \WP_Post(
			[
				'post_type'  => $post_type,
				'post_title' => $title === '' ? $post_type . '-' . $post_id : $title,
			]
		);
		$post->ID          = $post_id;
		$wp_posts[ $post_id ] = $post;
	}

	/**
	 * Build a registry over models declaring the given relationship configs.
	 *
	 * @param array<string, array<string, mixed>> $models Model name mapped to its relationships section.
	 */
	private function registry( array $models ): RelationshipRegistry {
		$built = [];
		foreach ( $models as $name => $relationships ) {
			$built[ $name ] = $this->model( (string) $name, $relationships );
		}

		return new RelationshipRegistry( new RelationshipModeler( $this->createStub( ModelFactory::class ), $built ) );
	}

	/**
	 * Build a manager over models declaring the given relationship configs.
	 *
	 * @param array<string, array<string, mixed>> $models Model name mapped to its relationships section.
	 */
	private function manager( array $models, ?RelationshipStore $store = null ): RelationshipManager {
		return new RelationshipManager( $this->registry( $models ), $store ?? new RelationshipStore( null ) );
	}

	/**
	 * Minimal post-type model exposing only a relationships config section.
	 *
	 * @param array<string, mixed> $relationships Relationships config section.
	 */
	private function model( string $name, array $relationships ): Model {
		return new RelationshipModel( $name, $relationships );
	}

	public function testRegistrySynthesizesReciprocalDefinitionSharingOneStorageKey(): void {
		$registry = $this->registry(
			[
				'movie'  => [
					'actors' => [
						'type'       => 'has_many',
						'model'      => 'person',
						'reciprocal' => 'acted_in',
					],
				],
				'person' => [],
			]
		);

		$declared = $registry->get( 'movie', 'actors' );
		$inverse  = $registry->get( 'person', 'acted_in' );

		$this->assertInstanceOf( RelationshipDefinition::class, $declared );
		$this->assertInstanceOf( RelationshipDefinition::class, $inverse );
		$this->assertSame( $declared->get_key(), $inverse->get_key(), 'Both sides must address the same stored row.' );
		$this->assertFalse( $declared->is_inverse() );
		$this->assertTrue( $inverse->is_inverse() );
		$this->assertSame( 'belongs_to', $inverse->get_type() );
		$this->assertSame( 'movie', $inverse->get_to() );
		$this->assertSame( 'from_post_id', $declared->own_column() );
		$this->assertSame( 'to_post_id', $inverse->own_column() );
	}

	public function testRegistryKeyIsIndependentOfModelLoadOrder(): void {
		$config = [
			'type'       => 'many_to_many',
			'model'      => 'genre',
			'reciprocal' => 'movies',
		];

		$forward = $this->registry(
			[
				'movie' => [ 'genres' => $config ],
				'genre' => [],
			]
		)->get( 'movie', 'genres' );

		// Same relationship declared from the other side of the pair.
		$reverse = $this->registry(
			[
				'genre' => [
					'movies' => [
						'type'       => 'many_to_many',
						'model'      => 'movie',
						'reciprocal' => 'genres',
					],
				],
				'movie' => [],
			]
		)->get( 'genre', 'movies' );

		$this->assertInstanceOf( RelationshipDefinition::class, $forward );
		$this->assertInstanceOf( RelationshipDefinition::class, $reverse );
		$this->assertSame( $forward->get_key(), $reverse->get_key() );
	}

	public function testRegistryKeepsExplicitDeclarationOverSynthesizedReciprocal(): void {
		$registry = $this->registry(
			[
				'movie'  => [
					'actors' => [
						'type'       => 'has_many',
						'model'      => 'person',
						'reciprocal' => 'acted_in',
					],
				],
				'person' => [
					'acted_in' => [
						'type'           => 'belongs_to',
						'model'          => 'movie',
						'reciprocal'     => 'actors',
						'cascade_delete' => true,
					],
				],
			]
		);

		$explicit = $registry->get( 'person', 'acted_in' );

		$this->assertInstanceOf( RelationshipDefinition::class, $explicit );
		$this->assertFalse( $explicit->is_inverse(), 'An explicitly declared side owns its own rows.' );
		$this->assertTrue( $explicit->cascades_delete(), 'The explicit declaration must not be overwritten.' );
	}

	public function testRegistrySkipsDeclarationsMissingModelOrWithUnknownType(): void {
		$registry = $this->registry(
			[
				'movie' => [
					'nowhere'   => [ 'type' => 'has_many' ],
					'bad_type'  => [
						'type'  => 'has_infinite',
						'model' => 'person',
					],
					'not_array' => 'person',
					'valid'     => [
						'type'  => 'has_one',
						'model' => 'person',
					],
				],
			]
		);

		$this->assertSame( [ 'valid' ], array_keys( $registry->get_for_model( 'movie' ) ) );
	}

	public function testRegistryDistinguishesTwoRelationshipsBetweenTheSameModels(): void {
		$registry = $this->registry(
			[
				'movie'  => [
					'director' => [
						'type'       => 'has_one',
						'model'      => 'person',
						'reciprocal' => 'directed',
					],
					'actors'   => [
						'type'       => 'has_many',
						'model'      => 'person',
						'reciprocal' => 'acted_in',
					],
				],
				'person' => [],
			]
		);

		$director = $registry->get( 'movie', 'director' );
		$actors   = $registry->get( 'movie', 'actors' );

		$this->assertInstanceOf( RelationshipDefinition::class, $director );
		$this->assertInstanceOf( RelationshipDefinition::class, $actors );
		$this->assertNotSame(
			$director->get_key(),
			$actors->get_key(),
			'Distinct relationships between the same two models must not share storage.'
		);
	}

	public function testAttachAndReadBackRelatedPostsInStoredOrder(): void {
		$manager = $this->manager(
			[
				'movie'  => [
					'actors' => [
						'type'  => 'has_many',
						'model' => 'person',
					],
				],
				'person' => [],
			]
		);
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'person', 'Second' );
		$this->seed_post( 3, 'person', 'Third' );

		$this->assertIsArray( $manager->attach( 1, 'movie', 'actors', 2 ) );
		$this->assertIsArray( $manager->attach( 1, 'movie', 'actors', 3 ) );

		$this->assertSame( [ 2, 3 ], $manager->get_related_ids( 1, 'movie', 'actors' ) );

		$related = $manager->get_related( 1, 'movie', 'actors' );
		$this->assertCount( 2, $related );
		$this->assertSame( 'Second', $related[0]['title'] );
		$this->assertSame( 'person', $related[0]['post_type'] );
		$this->assertSame( 0, $related[0]['order_index'] );
		$this->assertSame( 1, $related[1]['order_index'] );
	}

	public function testRelationshipIsReadableFromTheReciprocalSide(): void {
		$manager = $this->manager(
			[
				'movie'  => [
					'actors' => [
						'type'       => 'has_many',
						'model'      => 'person',
						'reciprocal' => 'acted_in',
					],
				],
				'person' => [],
			]
		);
		$this->seed_post( 1, 'movie', 'Alien' );
		$this->seed_post( 2, 'person' );

		$manager->attach( 1, 'movie', 'actors', 2 );

		// One stored row, readable from either direction.
		$this->assertSame( [ 2 ], $manager->get_related_ids( 1, 'movie', 'actors' ) );
		$this->assertSame( [ 1 ], $manager->get_related_ids( 2, 'person', 'acted_in' ) );
		$this->assertSame( 'Alien', $manager->get_related( 2, 'person', 'acted_in' )[0]['title'] );
	}

	public function testEagerLoadingGroupsManyPostsAndKeepsEmptyOnesPresent(): void {
		$manager = $this->manager(
			[
				'movie'  => [
					'actors' => [
						'type'  => 'has_many',
						'model' => 'person',
					],
				],
				'person' => [],
			]
		);
		foreach ( [ 1, 2, 3 ] as $movie_id ) {
			$this->seed_post( $movie_id, 'movie' );
		}
		$this->seed_post( 10, 'person' );
		$this->seed_post( 11, 'person' );

		$manager->attach( 1, 'movie', 'actors', 10 );
		$manager->attach( 1, 'movie', 'actors', 11 );
		$manager->attach( 2, 'movie', 'actors', 11 );

		$grouped = $manager->get_related_for_posts( [ 1, 2, 3 ], 'movie', 'actors' );

		$this->assertSame( [ 1, 2, 3 ], array_keys( $grouped ) );
		$this->assertSame( [ 10, 11 ], array_column( $grouped[1], 'post_id' ) );
		$this->assertSame( [ 11 ], array_column( $grouped[2], 'post_id' ) );
		$this->assertSame( [], $grouped[3], 'A post with no relations must still be present as an empty list.' );
	}

	public function testEagerLoadingReadsManyPostsWithASinglePreparedSelect(): void {
		$database = new RecordingRelationshipDatabase();
		$store    = new RelationshipStore( $database );

		$store->get_by_posts( 'movie_person_actors', 'from_post_id', [ 1, 2, 3 ] );

		$selects = array_values(
			array_filter(
				$database->queries,
				static function ( string $query ): bool {
					return strpos( $query, 'SELECT' ) === 0;
				}
			)
		);

		$this->assertCount( 1, $selects, 'Eager loading must not issue one query per post.' );
		$this->assertStringContainsString( 'IN (1, 2, 3)', $selects[0] );
		$this->assertStringContainsString( "relationship_key = 'movie_person_actors'", $selects[0] );
		$this->assertStringContainsString( 'ORDER BY order_index ASC, id ASC', $selects[0] );
	}

	public function testStoreDeduplicatesAndIgnoresInvalidPostIdsAndColumns(): void {
		$database = new RecordingRelationshipDatabase();
		$store    = new RelationshipStore( $database );

		$this->assertSame( [], $store->get_by_posts( 'key', 'from_post_id', [ 0, -3 ] ) );
		$this->assertSame( [], $store->get_by_posts( 'key', 'nonsense_column', [ 1 ] ) );
		$store->get_by_posts( 'key', 'to_post_id', [ 4, 4, 5 ] );

		$selects = array_values(
			array_filter(
				$database->queries,
				static function ( string $query ): bool {
					return strpos( $query, 'SELECT' ) === 0;
				}
			)
		);

		$this->assertCount( 1, $selects, 'Empty id sets and unknown columns must not reach the database.' );
		$this->assertStringContainsString( 'IN (4, 5)', $selects[0] );
	}

	public function testHasOneRejectsASecondTargetAndAllowsReplacementViaSync(): void {
		$manager = $this->manager(
			[
				'movie'  => [
					'director' => [
						'type'  => 'has_one',
						'model' => 'person',
					],
				],
				'person' => [],
			]
		);
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'person' );
		$this->seed_post( 3, 'person' );

		$this->assertIsArray( $manager->attach( 1, 'movie', 'director', 2 ) );

		$rejected = $manager->attach( 1, 'movie', 'director', 3 );
		$this->assertInstanceOf( \WP_Error::class, $rejected );
		$this->assertSame( 'saltus_relationship_cardinality', $rejected->get_error_code() );
		$this->assertSame( 409, $rejected->get_error_data()['status'] );
		$this->assertSame( [ 2 ], $manager->get_related_ids( 1, 'movie', 'director' ) );

		$this->assertIsArray( $manager->sync( 1, 'movie', 'director', [ 3 ] ) );
		$this->assertSame( [ 3 ], $manager->get_related_ids( 1, 'movie', 'director' ) );
	}

	public function testCardinalityIsEnforcedWhenWritingThroughTheReciprocalSide(): void {
		$manager = $this->manager(
			[
				'movie'  => [
					'director' => [
						'type'       => 'has_one',
						'model'      => 'person',
						'reciprocal' => 'directed',
					],
				],
				'person' => [],
			]
		);
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'movie' );
		$this->seed_post( 5, 'person' );

		$manager->attach( 1, 'movie', 'director', 5 );

		// movie.director is has_one, so movie 2 already having no director is
		// fine; the limit belongs to the movie side and must hold when the
		// write arrives through person.directed.
		$this->assertIsArray( $manager->attach( 5, 'person', 'directed', 2 ) );
		$this->assertSame( [ 5 ], $manager->get_related_ids( 2, 'movie', 'director' ) );

		// Giving movie 1 a second director through the reciprocal must fail.
		$this->seed_post( 6, 'person' );
		$rejected = $manager->attach( 6, 'person', 'directed', 1 );

		$this->assertInstanceOf( \WP_Error::class, $rejected );
		$this->assertSame( 'saltus_relationship_cardinality', $rejected->get_error_code() );
		$this->assertSame( [ 5 ], $manager->get_related_ids( 1, 'movie', 'director' ) );
	}

	public function testReattachingTheSamePairUpdatesInsteadOfDuplicating(): void {
		$manager = $this->manager(
			[
				'movie'  => [
					'actors' => [
						'type'  => 'has_many',
						'model' => 'person',
						'meta'  => [ 'role' => [ 'type' => 'text' ] ],
					],
				],
				'person' => [],
			]
		);
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'person' );

		$first  = $manager->attach( 1, 'movie', 'actors', 2, [ 'role' => 'Ripley' ] );
		$second = $manager->attach( 1, 'movie', 'actors', 2, [ 'role' => 'Ellen Ripley' ] );

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertSame( $first['id'], $second['id'], 'The unique pair must be updated, not duplicated.' );
		$this->assertSame( [ 2 ], $manager->get_related_ids( 1, 'movie', 'actors' ) );
		$this->assertSame( 'Ellen Ripley', $manager->get_related( 1, 'movie', 'actors' )[0]['pivot']['role'] );
	}

	public function testHasOneAcceptsReattachingItsExistingTarget(): void {
		$manager = $this->manager(
			[
				'movie'  => [
					'director' => [
						'type'  => 'has_one',
						'model' => 'person',
						'meta'  => [ 'credited_as' => [ 'type' => 'text' ] ],
					],
				],
				'person' => [],
			]
		);
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'person' );

		$manager->attach( 1, 'movie', 'director', 2 );
		$again = $manager->attach( 1, 'movie', 'director', 2, [ 'credited_as' => 'A. Nonymous' ] );

		$this->assertIsArray( $again, 'Updating the existing single target is not a capacity breach.' );
		$this->assertSame( 'A. Nonymous', $manager->get_related( 1, 'movie', 'director' )[0]['pivot']['credited_as'] );
	}

	public function testPivotValuesOutsideTheDeclaredFieldsAreDiscarded(): void {
		$manager = $this->manager(
			[
				'movie'  => [
					'actors' => [
						'type'  => 'has_many',
						'model' => 'person',
						'meta'  => [ 'role' => [ 'type' => 'text' ] ],
					],
				],
				'person' => [],
			]
		);
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'person' );

		$manager->attach( 1, 'movie', 'actors', 2, [ 'role' => 'Lead', 'injected' => 'nope' ] );

		$pivot = $manager->get_related( 1, 'movie', 'actors' )[0]['pivot'];
		$this->assertSame( [ 'role' => 'Lead' ], $pivot );
	}

	public function testSyncReplacesTheSetPreservesPivotDataAndReorders(): void {
		$manager = $this->manager(
			[
				'movie'  => [
					'actors' => [
						'type'  => 'has_many',
						'model' => 'person',
						'meta'  => [ 'role' => [ 'type' => 'text' ] ],
					],
				],
				'person' => [],
			]
		);
		$this->seed_post( 1, 'movie' );
		foreach ( [ 10, 11, 12 ] as $person_id ) {
			$this->seed_post( $person_id, 'person' );
		}

		$manager->attach( 1, 'movie', 'actors', 10, [ 'role' => 'Lead' ] );
		$manager->attach( 1, 'movie', 'actors', 11 );

		$result = $manager->sync( 1, 'movie', 'actors', [ 12, 10 ] );

		$this->assertIsArray( $result );
		$this->assertSame( [ 12, 10 ], $result['related_ids'] );
		$this->assertSame( [ 12, 10 ], $manager->get_related_ids( 1, 'movie', 'actors' ), 'Sync defines the order.' );

		$related = $manager->get_related( 1, 'movie', 'actors' );
		$this->assertSame( [], $related[0]['pivot'] );
		$this->assertSame(
			[ 'role' => 'Lead' ],
			$related[1]['pivot'],
			'A retained pair must keep its pivot data through a reorder.'
		);
	}

	public function testSyncRejectsMultipleIdsForASingleTargetRelationship(): void {
		$manager = $this->manager(
			[
				'movie'  => [
					'director' => [
						'type'  => 'has_one',
						'model' => 'person',
					],
				],
				'person' => [],
			]
		);
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'person' );
		$this->seed_post( 3, 'person' );

		$rejected = $manager->sync( 1, 'movie', 'director', [ 2, 3 ] );

		$this->assertInstanceOf( \WP_Error::class, $rejected );
		$this->assertSame( 'saltus_relationship_cardinality', $rejected->get_error_code() );
		$this->assertSame( [], $manager->get_related_ids( 1, 'movie', 'director' ), 'A rejected sync must not partially apply.' );
	}

	public function testSyncRejectsAnInvalidTargetBeforeChangingStoredRows(): void {
		$manager = $this->manager(
			[
				'movie'  => [
					'actors' => [
						'type'  => 'has_many',
						'model' => 'person',
					],
				],
				'person' => [],
			]
		);
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 10, 'person' );
		$this->seed_post( 11, 'person' );

		$manager->attach( 1, 'movie', 'actors', 10 );
		$manager->attach( 1, 'movie', 'actors', 11 );

		// Post 11 is absent from the new set, so a sync that cleared rows before
		// validating would drop it and leave the relationship half-written.
		$rejected = $manager->sync( 1, 'movie', 'actors', [ 10, 999 ] );

		$this->assertInstanceOf( \WP_Error::class, $rejected );
		$this->assertSame( 'saltus_relationship_target_missing', $rejected->get_error_code() );
		$this->assertSame(
			[ 10, 11 ],
			$manager->get_related_ids( 1, 'movie', 'actors' ),
			'Validation must run before the existing set is cleared.'
		);
	}

	public function testSyncRejectsMoreIdsThanTheDocumentedLimit(): void {
		$manager = $this->manager(
			[
				'movie'  => [
					'actors' => [
						'type'  => 'has_many',
						'model' => 'person',
					],
				],
				'person' => [],
			]
		);
		$this->seed_post( 1, 'movie' );

		$rejected = $manager->sync( 1, 'movie', 'actors', range( 1000, 1300 ) );

		$this->assertInstanceOf( \WP_Error::class, $rejected );
		$this->assertSame( 'saltus_relationship_too_many', $rejected->get_error_code() );
	}

	public function testSyncWithAnEmptyListClearsTheRelationship(): void {
		$manager = $this->manager(
			[
				'movie'  => [
					'actors' => [
						'type'  => 'has_many',
						'model' => 'person',
					],
				],
				'person' => [],
			]
		);
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'person' );
		$manager->attach( 1, 'movie', 'actors', 2 );

		$this->assertIsArray( $manager->sync( 1, 'movie', 'actors', [] ) );
		$this->assertSame( [], $manager->get_related_ids( 1, 'movie', 'actors' ) );
	}

	/**
	 * A manager whose reciprocal side accepts only one movie per person.
	 *
	 * Syncing `movie.stars` therefore fails on any person already starring in
	 * another movie, which interrupts the sequence after earlier ids have
	 * already been written — the mid-sequence failure these tests need.
	 */
	private function star_manager(): RelationshipManager {
		return $this->manager(
			[
				'movie'  => [],
				'person' => [
					'stars_in' => [
						'type'       => 'has_one',
						'model'      => 'movie',
						'reciprocal' => 'stars',
					],
				],
			]
		);
	}

	public function testAnInterruptedSyncAttachLeavesThePriorSetIntact(): void {
		$manager = $this->star_manager();
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'movie' );
		foreach ( [ 10, 11, 12 ] as $person_id ) {
			$this->seed_post( $person_id, 'person' );
		}

		$manager->attach( 1, 'movie', 'stars', 10 );
		$manager->attach( 2, 'movie', 'stars', 11 );

		// 12 is written, then 11 fails because it already stars in movie 2.
		$rejected = $manager->sync( 1, 'movie', 'stars', [ 10, 12, 11 ] );

		$this->assertInstanceOf( \WP_Error::class, $rejected );
		$this->assertSame( 'saltus_relationship_cardinality', $rejected->get_error_code() );
		$this->assertSame(
			[ 10 ],
			$manager->get_related_ids( 1, 'movie', 'stars' ),
			'The attach of 12 must roll back with the failed sequence.'
		);
		$this->assertSame( [], $manager->get_related_ids( 12, 'person', 'stars_in' ) );
		$this->assertSame( [ 11 ], $manager->get_related_ids( 2, 'movie', 'stars' ) );
	}

	public function testAnInterruptedSyncDetachRestoresTheRemovedRelationship(): void {
		$manager = $this->star_manager();
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'movie' );
		foreach ( [ 10, 11, 12 ] as $person_id ) {
			$this->seed_post( $person_id, 'person' );
		}

		$manager->attach( 1, 'movie', 'stars', 10 );
		$manager->attach( 1, 'movie', 'stars', 12 );
		$manager->attach( 2, 'movie', 'stars', 11 );

		// Dropping 12 is the first mutation; 11 then fails on capacity.
		$rejected = $manager->sync( 1, 'movie', 'stars', [ 10, 11 ] );

		$this->assertInstanceOf( \WP_Error::class, $rejected );
		$this->assertSame(
			[ 10, 12 ],
			$manager->get_related_ids( 1, 'movie', 'stars' ),
			'The detach of 12 must roll back with the failed sequence.'
		);
		$this->assertSame( [ 1 ], $manager->get_related_ids( 12, 'person', 'stars_in' ), 'The reciprocal view must agree.' );
	}

	public function testAnInterruptedSyncReorderRestoresThePriorOrder(): void {
		$manager = $this->star_manager();
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'movie' );
		foreach ( [ 10, 11, 12 ] as $person_id ) {
			$this->seed_post( $person_id, 'person' );
		}

		$manager->attach( 1, 'movie', 'stars', 10 );
		$manager->attach( 1, 'movie', 'stars', 12 );
		$manager->attach( 2, 'movie', 'stars', 11 );

		// 12 and 10 are reordered before 11 fails on capacity.
		$rejected = $manager->sync( 1, 'movie', 'stars', [ 12, 10, 11 ] );

		$this->assertInstanceOf( \WP_Error::class, $rejected );
		$this->assertSame(
			[ 10, 12 ],
			$manager->get_related_ids( 1, 'movie', 'stars' ),
			'The reorder must roll back with the failed sequence.'
		);
	}

	public function testSyncOpensItsTransactionAfterTheTableExistsAndRollsBackOnFailure(): void {
		$database = new RecordingRelationshipDatabase();
		// Person 11 already stars in movie 2, so syncing it into movie 1 fails.
		$database->canned['from_post_id IN (11)'] = [
			[
				'id'               => 7,
				'relationship_key' => 'movie_person_stars_stars_in',
				'from_post_id'     => 11,
				'to_post_id'       => 2,
				'pivot_data'       => '{}',
				'order_index'      => 0,
			],
		];

		$manager = $this->manager(
			[
				'movie'  => [],
				'person' => [
					'stars_in' => [
						'type'       => 'has_one',
						'model'      => 'movie',
						'reciprocal' => 'stars',
					],
				],
			],
			new RelationshipStore( $database )
		);
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 10, 'person' );
		$this->seed_post( 11, 'person' );

		$rejected = $manager->sync( 1, 'movie', 'stars', [ 10, 11 ] );

		$this->assertInstanceOf( \WP_Error::class, $rejected );

		$begin = array_search( 'START TRANSACTION', $database->queries, true );
		$this->assertIsInt( $begin, 'The sequence must run inside a transaction.' );
		$this->assertSame( 'ROLLBACK', end( $database->queries ), 'A failed sequence must roll back.' );
		$this->assertNotContains( 'COMMIT', $database->queries );

		// DDL implicitly commits in MySQL, so a CREATE TABLE inside the boundary
		// would end it and leave the writes before the failure already published.
		$ddl = array_values(
			array_filter(
				array_keys( $database->queries ),
				static function ( int $index ) use ( $database ): bool {
					return strpos( $database->queries[ $index ], 'CREATE TABLE' ) !== false;
				}
			)
		);
		$this->assertNotSame( [], $ddl, 'The table is still created.' );
		foreach ( $ddl as $index ) {
			$this->assertLessThan( $begin, $index, 'The table must exist before the boundary opens.' );
		}
	}

	public function testANestedRollbackDiscardsOnlyItsOwnWritesInProcess(): void {
		$store = new RelationshipStore( null );

		$committed = $store->transact(
			function () use ( $store ): bool {
				$store->upsert( [ 'relationship_key' => 'k', 'from_post_id' => 1, 'to_post_id' => 10 ] );

				$store->transact(
					function () use ( $store ): bool {
						$store->upsert( [ 'relationship_key' => 'k', 'from_post_id' => 1, 'to_post_id' => 11 ] );

						return false;
					}
				);

				return true;
			}
		);

		$this->assertTrue( $committed );
		$this->assertNotNull( $store->find( 'k', 1, 10 ), 'The outer write must survive an inner rollback.' );
		$this->assertNull( $store->find( 'k', 1, 11 ), 'The inner write must be discarded.' );
	}

	public function testANestedTransactionTakesASavepointRatherThanRestarting(): void {
		$database = new RecordingRelationshipDatabase();
		$store    = new RelationshipStore( $database );

		$store->transact(
			function () use ( $store ): bool {
				$store->transact(
					static function (): bool {
						return false;
					}
				);

				return true;
			}
		);

		// A second START TRANSACTION would commit the outer one in MySQL, so the
		// inner boundary must unwind through a savepoint instead.
		$this->assertSame( [ 'START TRANSACTION' ], array_values( array_filter( $database->queries, static fn( string $query ): bool => $query === 'START TRANSACTION' ) ) );
		$this->assertContains( 'SAVEPOINT saltus_rel_2', $database->queries );
		$this->assertContains( 'ROLLBACK TO SAVEPOINT saltus_rel_2', $database->queries );
		$this->assertSame( 'COMMIT', end( $database->queries ) );
	}

	public function testAThrownFailureRollsBackAndPropagates(): void {
		$store = new RelationshipStore( null );
		$store->upsert( [ 'relationship_key' => 'k', 'from_post_id' => 1, 'to_post_id' => 10 ] );

		try {
			$store->transact(
				function () use ( $store ): bool {
					$store->delete( 'k', 1, 10 );

					throw new \RuntimeException( 'interrupted' );
				}
			);
			$this->fail( 'The exception must reach the caller.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'interrupted', $error->getMessage() );
		}

		$this->assertNotNull( $store->find( 'k', 1, 10 ), 'An interrupted sequence must roll back.' );
	}

	public function testDetachRemovesOnlyTheGivenPair(): void {
		$manager = $this->manager(
			[
				'movie'  => [
					'actors' => [
						'type'  => 'has_many',
						'model' => 'person',
					],
				],
				'person' => [],
			]
		);
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'person' );
		$this->seed_post( 3, 'person' );
		$manager->attach( 1, 'movie', 'actors', 2 );
		$manager->attach( 1, 'movie', 'actors', 3 );

		$result = $manager->detach( 1, 'movie', 'actors', 2 );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['detached'] );
		$this->assertSame( [ 3 ], $manager->get_related_ids( 1, 'movie', 'actors' ) );

		$repeat = $manager->detach( 1, 'movie', 'actors', 2 );
		$this->assertIsArray( $repeat );
		$this->assertFalse( $repeat['detached'], 'Detaching an absent pair reports no change rather than failing.' );
	}

	public function testDetachThroughTheReciprocalSideRemovesTheSharedRow(): void {
		$manager = $this->manager(
			[
				'movie'  => [
					'actors' => [
						'type'       => 'has_many',
						'model'      => 'person',
						'reciprocal' => 'acted_in',
					],
				],
				'person' => [],
			]
		);
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'person' );
		$manager->attach( 1, 'movie', 'actors', 2 );

		$this->assertTrue( $manager->detach( 2, 'person', 'acted_in', 1 )['detached'] );
		$this->assertSame( [], $manager->get_related_ids( 1, 'movie', 'actors' ) );
	}

	public function testWritesRejectUnknownRelationshipsSelfReferenceAndWrongTypes(): void {
		$manager = $this->manager(
			[
				'movie'  => [
					'actors' => [
						'type'  => 'has_many',
						'model' => 'person',
					],
				],
				'person' => [],
			]
		);
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'movie' );
		$this->seed_post( 3, 'person' );

		$unknown = $manager->attach( 1, 'movie', 'nope', 3 );
		$this->assertInstanceOf( \WP_Error::class, $unknown );
		$this->assertSame( 'saltus_relationship_not_found', $unknown->get_error_code() );
		$this->assertSame( 404, $unknown->get_error_data()['status'] );

		$self = $manager->attach( 1, 'movie', 'actors', 1 );
		$this->assertInstanceOf( \WP_Error::class, $self );
		$this->assertSame( 'saltus_relationship_self_reference', $self->get_error_code() );

		$wrong_type = $manager->attach( 1, 'movie', 'actors', 2 );
		$this->assertInstanceOf( \WP_Error::class, $wrong_type );
		$this->assertSame( 'saltus_relationship_type_mismatch', $wrong_type->get_error_code() );
		$this->assertSame( 'person', $wrong_type->get_error_data()['expected'] );
		$this->assertSame( 'movie', $wrong_type->get_error_data()['actual'] );

		$missing = $manager->attach( 1, 'movie', 'actors', 999 );
		$this->assertInstanceOf( \WP_Error::class, $missing );
		$this->assertSame( 'saltus_relationship_target_missing', $missing->get_error_code() );

		$invalid = $manager->attach( 0, 'movie', 'actors', 3 );
		$this->assertInstanceOf( \WP_Error::class, $invalid );
		$this->assertSame( 'saltus_relationship_invalid_post', $invalid->get_error_code() );
	}

	public function testDeletingAPostClearsItsRowsAndCascadesOnlyWhereDeclared(): void {
		$manager = $this->manager(
			[
				'movie'  => [
					'actors'  => [
						'type'  => 'has_many',
						'model' => 'person',
					],
					'reviews' => [
						'type'           => 'has_many',
						'model'          => 'review',
						'cascade_delete' => true,
					],
				],
				'person' => [],
				'review' => [],
			]
		);
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'person' );
		$this->seed_post( 20, 'review' );
		$manager->attach( 1, 'movie', 'actors', 2 );
		$manager->attach( 1, 'movie', 'reviews', 20 );

		$deleted = $manager->delete_all_for_post( 1, 'movie' );

		$this->assertSame( [ 20 ], $deleted, 'Only the cascading relationship deletes its targets.' );
		$this->assertArrayNotHasKey( 20, $GLOBALS['wp_posts'] );
		$this->assertArrayHasKey( 2, $GLOBALS['wp_posts'], 'A non-cascading target survives.' );
		$this->assertSame( [], $manager->get_related_ids( 1, 'movie', 'actors' ) );
	}

	public function testCascadeKeepsATargetThatAnotherPostStillReferences(): void {
		$manager = $this->manager(
			[
				'movie' => [
					'reviews' => [
						'type'           => 'has_many',
						'model'          => 'review',
						'cascade_delete' => true,
					],
				],
				'review' => [],
			]
		);
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'movie' );
		$this->seed_post( 20, 'review' );
		$this->seed_post( 21, 'review' );
		$manager->attach( 1, 'movie', 'reviews', 20 );
		$manager->attach( 2, 'movie', 'reviews', 20 );
		$manager->attach( 1, 'movie', 'reviews', 21 );

		$deleted = $manager->delete_all_for_post( 1, 'movie' );

		$this->assertSame( [ 21 ], $deleted );
		$this->assertArrayHasKey( 20, $GLOBALS['wp_posts'], 'A shared target must not be deleted.' );
		$this->assertSame( [ 20 ], $manager->get_related_ids( 2, 'movie', 'reviews' ) );
	}

	public function testReciprocalSideDoesNotInheritCascadeDelete(): void {
		$manager = $this->manager(
			[
				'movie'  => [
					'reviews' => [
						'type'           => 'has_many',
						'model'          => 'review',
						'reciprocal'     => 'movie',
						'cascade_delete' => true,
					],
				],
				'review' => [],
			]
		);
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 20, 'review' );
		$manager->attach( 1, 'movie', 'reviews', 20 );

		// Deleting the review must not delete the movie that declared cascade.
		$deleted = $manager->delete_all_for_post( 20, 'review' );

		$this->assertSame( [], $deleted );
		$this->assertArrayHasKey( 1, $GLOBALS['wp_posts'] );
	}

	public function testDescribeReportsBothDeclaredAndReciprocalRelationships(): void {
		$manager = $this->manager(
			[
				'movie'  => [
					'actors' => [
						'type'       => 'has_many',
						'model'      => 'person',
						'reciprocal' => 'acted_in',
						'meta'       => [ 'role' => [ 'type' => 'text' ] ],
					],
				],
				'person' => [],
			]
		);

		$movie = $manager->describe( 'movie' );

		$this->assertCount( 1, $movie );
		$this->assertSame( 'actors', $movie[0]['name'] );
		$this->assertSame( 'person', $movie[0]['to'] );
		$this->assertTrue( $movie[0]['multiple'] );
		$this->assertSame( [ 'role' ], $movie[0]['pivot_fields'] );

		$person = $manager->describe( 'person' );
		$this->assertSame( 'acted_in', $person[0]['name'] );
		$this->assertTrue( $person[0]['inverse'] );

		$this->assertTrue( $manager->has_readable_relationships( 'movie' ) );
		$this->assertFalse( $manager->has_readable_relationships( 'unrelated' ) );
	}

	public function testStoreReportsWhetherRowsArePersisted(): void {
		$this->assertFalse( ( new RelationshipStore( null ) )->is_persistent() );
	}

	public function testReadsForUnknownRelationshipsReturnEmptyResults(): void {
		$manager = $this->manager( [ 'movie' => [] ] );

		$this->assertSame( [], $manager->get_related_ids( 1, 'movie', 'nope' ) );
		$this->assertSame( [], $manager->get_related( 1, 'movie', 'nope' ) );
		$this->assertSame( [], $manager->get_related_for_posts( [ 1 ], 'movie', 'nope' ) );
		$this->assertNull( $manager->get_definition( 'movie', 'nope' ) );
		$this->assertSame( [], $manager->delete_all_for_post( 0, 'movie' ) );
	}
}

/** Audit database double recording every query the store issues. */
class RecordingRelationshipDatabase implements \Saltus\WP\Framework\MCP\Audit\AuditDatabase {

	/** @var list<string> */
	public array $queries = [];

	/**
	 * Rows to answer a SELECT with, keyed by a fragment of the query.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	public array $canned = [];

	public function prefix(): string {
		return 'wp_';
	}

	/**
	 * @param array<string, mixed> $data
	 * @param list<string>         $format
	 * @return bool|int
	 */
	public function insert( string $table, array $data, array $format = [] ) {
		$this->queries[] = 'INSERT INTO ' . $table;

		return 1;
	}

	/** @return bool|int */
	public function query( string $query ) {
		$this->queries[] = $query;

		return 0;
	}

	public function get_charset_collate(): string {
		return '';
	}

	/**
	 * @param mixed $output
	 * @return list<array<string, mixed>>
	 */
	public function get_results( string $query, $output = null ): array {
		$this->queries[] = $query;

		foreach ( $this->canned as $fragment => $rows ) {
			if ( strpos( $query, (string) $fragment ) !== false ) {
				return $rows;
			}
		}

		return [];
	}

	/** @param mixed ...$args */
	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) || is_float( $arg ) ? (string) $arg : "'" . (string) $arg . "'";
			$query       = (string) preg_replace( '/%[dsf]/', $replacement, $query, 1 );
		}

		return $query;
	}
}

/** Post-type model double carrying a relationships config section. */
class RelationshipModel implements Model {

	private string $model_name;

	/** @var array<string, mixed> */
	private array $relationships;

	/** @param array<string, mixed> $relationships */
	public function __construct( string $name, array $relationships ) {
		$this->model_name    = $name;
		$this->relationships = $relationships;
	}

	public function setup(): void {}

	public function get_name(): string {
		return $this->model_name;
	}

	public function get_type(): string {
		return 'post_type';
	}

	/** @return array<string, mixed> */
	public function get_options(): array {
		return [];
	}

	/** @return array<string, mixed> */
	public function get_args(): array {
		return [];
	}

	/** @return array<string, mixed> */
	public function get_config(): array {
		return [ 'relationships' => $this->relationships ];
	}
}

/** Modeler double returning a fixed model list. */
class RelationshipModeler extends Modeler {

	/** @var array<string, Model> */
	private array $models;

	/** @param array<string, Model> $models */
	public function __construct( ModelFactory $model_factory, array $models ) {
		parent::__construct( $model_factory );
		$this->models = $models;
	}

	/** @return array<string, Model> */
	public function get_models(): array {
		return $this->models;
	}
}
