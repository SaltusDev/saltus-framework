<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Meta\FieldCipher;
use Saltus\WP\Framework\Features\Meta\FieldEncryptionKeys;
use Saltus\WP\Framework\Features\Meta\FieldEncryptionPolicy;
use Saltus\WP\Framework\Features\Meta\MetaFieldProvider;
use Saltus\WP\Framework\Features\Privacy\Privacy;
use Saltus\WP\Framework\Features\Relationships\RelationshipManager;
use Saltus\WP\Framework\Features\Relationships\RelationshipPermissionPolicy;
use Saltus\WP\Framework\Features\Relationships\RelationshipRegistry;
use Saltus\WP\Framework\Features\Relationships\RelationshipStore;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;

require_once dirname( __DIR__ ) . '/Rest/functions.php';
require_once __DIR__ . '/RelationshipColumnTest.php';

/**
 * @covers \Saltus\WP\Framework\Features\Privacy\Privacy
 */
class PrivacyTest extends TestCase {

	private const TEST_KEY = 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

	protected function setUp(): void {
		$this->reset();
	}

	protected function tearDown(): void {
		$this->reset();
	}

	private function reset(): void {
		global $wp_posts, $wp_post_meta, $wp_users_by_email, $wp_filters_registered, $wp_filter_values, $wp_meta_deletes, $wp_get_posts_args;

		$wp_posts              = [];
		$wp_post_meta          = [];
		$wp_users_by_email     = [];
		$wp_filters_registered = [];
		$wp_filter_values      = [];
		$wp_meta_deletes       = [];
		$wp_get_posts_args     = [];
	}

	private function modeler(): Modeler {
		$meta = [
			'employment' => [
				'register_rest_api' => true,
				'fields'            => [
					'job_title' => [ 'type' => 'text', 'title' => 'Job Title' ],
					'ssn'       => [ 'type' => 'text', 'title' => 'SSN', 'encrypted' => true ],
				],
			],
		];

		$model = $this->createStub( Model::class );
		$model->method( 'get_config' )->willReturn( [ 'meta' => $meta ] );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_args' )->willReturn( [ 'meta' => $meta ] );

		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( [ 'book' => $model ] );

		return $modeler;
	}

	private function privacy( ?RelationshipManager $relationships = null ): Privacy {
		$modeler = $this->modeler();

		return new Privacy(
			static fn(): Modeler => $modeler,
			new MetaFieldProvider(),
			new FieldEncryptionPolicy( new MetaFieldProvider(), new FieldCipher( new FieldEncryptionKeys() ) ),
			$relationships
		);
	}

	/**
	 * A real `RelationshipManager` over a stubbed registry.
	 *
	 * `RelationshipManager` is final, so it cannot be doubled — the same constraint
	 * `RelationshipStore` imposed on the column test. Building a real one from a
	 * stubbed `Modeler` is stronger anyway: the cascade rule under test is the
	 * production one, not a mock's idea of it.
	 *
	 * @param list<int>                        $related_ids Ids the relationship resolves to.
	 * @param array<string, list<string>>|null $capabilities Declared per-operation rules.
	 * @param bool                             $granted Whether the returned manager's policy grants.
	 */
	private function manager_with_cascade(
		bool $cascades,
		array $related_ids,
		?array $capabilities = null,
		bool $granted = true
	): RelationshipManager {
		$declaration = [
			'type'           => 'has_many',
			'model'          => 'book',
			'cascade_delete' => $cascades,
		];

		if ( $capabilities !== null ) {
			$declaration['capabilities'] = $capabilities;
		}

		$config = [
			'relationships' => [
				'records' => $declaration,
			],
		];

		$model = $this->createStub( Model::class );
		$model->method( 'get_config' )->willReturn( $config );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_args' )->willReturn( $config );

		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( [ 'book' => $model ] );

		// `RelationshipStore` is final too, so the seam is the `AuditDatabase` it
		// accepts — the same boundary `RelationshipColumnTest` intercepts at.
		$store    = new RelationshipStore( new CountingDatabase() );
		$registry = new RelationshipRegistry( $modeler );

		// Rows are seeded through a granting policy so the relationship data exists the
		// production way. A denial applied afterwards then isolates discovery, which is
		// the contradictory configuration under test: the row is there and the operator
		// erasing cannot see it.
		$seeder = new RelationshipManager( $registry, $store, $this->relationship_policy( true ) );

		foreach ( $related_ids as $related_id ) {
			$seeder->attach( 10, 'book', 'records', $related_id );
		}

		if ( $granted ) {
			return $seeder;
		}

		return new RelationshipManager( $registry, $store, $this->relationship_policy( false ) );
	}

	private function relationship_policy( bool $granted ): RelationshipPermissionPolicy {
		return new RelationshipPermissionPolicy(
			static function ( string $capability ) use ( $granted ): bool {
				return $granted;
			}
		);
	}

	private function seed_user( string $email = 'subject@example.com', int $id = 7 ): void {
		global $wp_users_by_email;
		$wp_users_by_email[ $email ] = new \WP_User( [ 'ID' => $id, 'user_email' => $email ] );
	}

	private function seed_post( int $id, int $author = 7, string $post_type = 'book' ): void {
		global $wp_posts;
		$post              = new \WP_Post( [ 'post_type' => $post_type, 'post_title' => 'Post ' . $id ] );
		$post->ID          = $id;
		$post->post_author = $author;
		$wp_posts[ $id ]   = $post;
	}

	// --- Registration ---

	public function testRegistersWithCoresExporterAndEraserRegistries(): void {
		global $wp_filters_registered;

		$this->privacy()->register();

		$this->assertArrayHasKey( 'wp_privacy_personal_data_exporters', $wp_filters_registered );
		$this->assertArrayHasKey( 'wp_privacy_personal_data_erasers', $wp_filters_registered );
	}

	public function testExporterIsAddedWithoutDisplacingOthers(): void {
		$existing = [ 'other-plugin' => [ 'exporter_friendly_name' => 'Other' ] ];

		$result = $this->privacy()->register_exporter( $existing );

		$this->assertArrayHasKey( 'other-plugin', $result, 'An existing exporter must survive.' );
		$this->assertArrayHasKey( 'saltus-framework', $result );
		$this->assertIsCallable( $result['saltus-framework']['callback'] );
	}

	public function testEraserIsAddedWithoutDisplacingOthers(): void {
		$result = $this->privacy()->register_eraser( [ 'other' => [] ] );

		$this->assertArrayHasKey( 'other', $result );
		$this->assertArrayHasKey( 'saltus-framework', $result );
	}

	public function testNonArrayRegistryIsToleratedRatherThanFatal(): void {
		$this->assertArrayHasKey( 'saltus-framework', $this->privacy()->register_exporter( null ) );
		$this->assertArrayHasKey( 'saltus-framework', $this->privacy()->register_eraser( 'nonsense' ) );
	}

	// --- Export ---

	public function testExportReturnsModelMetaForTheSubject(): void {
		$this->seed_user();
		$this->seed_post( 10 );
		update_post_meta( 10, 'job_title', 'Engineer' );

		$result = $this->privacy()->export( 'subject@example.com' );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $result['data'] );
		$this->assertSame( 'saltus-post-10', $result['data'][0]['item_id'] );

		$values = array_column( $result['data'][0]['data'], 'value', 'name' );
		$this->assertSame( 'Engineer', $values['Job Title'] );
	}

	/**
	 * An encrypted field is exported as plaintext. A data subject request asks what
	 * the site holds about a person; ciphertext answers nothing. Encryption
	 * protects the value at rest, not from the person it describes.
	 */
	public function testEncryptedFieldIsExportedAsPlaintext(): void {
		add_filter( FieldEncryptionKeys::KEY_FILTER, static fn() => self::TEST_KEY );

		$this->seed_user();
		$this->seed_post( 10 );

		$cipher   = new FieldCipher( new FieldEncryptionKeys() );
		$envelope = $cipher->encrypt( '123-45-6789' );
		$this->assertIsString( $envelope );
		update_post_meta( 10, 'ssn', $envelope );

		$result = $this->privacy()->export( 'subject@example.com' );
		$values = array_column( $result['data'][0]['data'], 'value', 'name' );

		$this->assertSame( '123-45-6789', $values['SSN'] );
		$this->assertStringNotContainsString( 'saltus:v1', (string) wp_json_encode( $result ), 'The envelope must not leak into an export.' );
	}

	public function testExportOfAnUnknownEmailIsEmptyAndDone(): void {
		$result = $this->privacy()->export( 'nobody@example.com' );

		$this->assertSame( [], $result['data'] );
		$this->assertTrue( $result['done'] );
	}

	public function testPostWithNoModelMetaProducesNoExportItem(): void {
		$this->seed_user();
		$this->seed_post( 10 );

		$result = $this->privacy()->export( 'subject@example.com' );

		$this->assertSame( [], $result['data'] );
	}

	/**
	 * Core drives batching, so a full page must not report done — otherwise the
	 * export silently stops at the first page.
	 */
	public function testFullPageReportsNotDoneSoCoreRequestsTheNext(): void {
		$this->seed_user();
		for ( $i = 1; $i <= 20; $i++ ) {
			$this->seed_post( $i );
			update_post_meta( $i, 'job_title', 'Engineer ' . $i );
		}

		$first = $this->privacy()->export( 'subject@example.com', 1 );

		$this->assertCount( 20, $first['data'] );
		$this->assertFalse( $first['done'], 'A full page means there may be another.' );
	}

	public function testSecondPageIsRequestedWithThePagedArgument(): void {
		global $wp_get_posts_args;

		$this->seed_user();
		$this->privacy()->export( 'subject@example.com', 3 );

		$this->assertSame( 3, $wp_get_posts_args[0]['paged'] );
		$this->assertSame( 7, $wp_get_posts_args[0]['author'], 'Only the subject\'s posts may be read.' );
	}

	// --- Erase ---

	public function testEraseRemovesModelMeta(): void {
		$this->seed_user();
		$this->seed_post( 10 );
		update_post_meta( 10, 'job_title', 'Engineer' );

		$result = $this->privacy()->erase( 'subject@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertSame( '', get_post_meta( 10, 'job_title', true ) );
	}

	/**
	 * The post survives. It is content the site owns and may be required to keep,
	 * and core's own erasers anonymize rather than delete. Deleting here would also
	 * cascade through relationships in ways the request never asked for.
	 */
	public function testErasePreservesThePostItself(): void {
		global $wp_posts;

		$this->seed_user();
		$this->seed_post( 10 );
		update_post_meta( 10, 'job_title', 'Engineer' );

		$this->privacy()->erase( 'subject@example.com' );

		$this->assertArrayHasKey( 10, $wp_posts, 'Erasure removes meta, not the post.' );
	}

	public function testEraseWithNothingToRemoveReportsNoRemoval(): void {
		$this->seed_user();
		$this->seed_post( 10 );

		$result = $this->privacy()->erase( 'subject@example.com' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
	}

	public function testEraseOfAnUnknownEmailIsDone(): void {
		$result = $this->privacy()->erase( 'nobody@example.com' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * The roadmap's cascade requirement. A cascade-declared relationship means the
	 * related post exists only to serve this one, so leaving its meta behind leaves
	 * the subject's data on an orphan the request cannot see.
	 */
	public function testEraseFollowsCascadeRelationshipsToDependentPosts(): void {
		$this->seed_user();
		$this->seed_post( 10 );
		// Authored by someone else, so the only way erasure reaches it is the
		// cascade rule under test — not because it is the subject's own post.
		$this->seed_post( 11, 99 );
		update_post_meta( 10, 'job_title', 'Engineer' );
		update_post_meta( 11, 'job_title', 'Dependent record' );

		$result = $this->privacy( $this->manager_with_cascade( true, [ 11 ] ) )->erase( 'subject@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertSame( '', get_post_meta( 11, 'job_title', true ), 'A cascade dependent must be erased too.' );
	}

	/**
	 * The defect: cascade discovery went through the read-filtered API, so a cascade
	 * relationship the erasing operator could not read was skipped in silence and the
	 * erasure reported completion having left the dependent content in place.
	 *
	 * `erase_others_personal_data` is authority over every relationship, so a read rule
	 * blocking one is a contradictory configuration. Bypassing it would hide the
	 * mistake; skipping silently hides the incomplete erasure. It is reported.
	 */
	public function testEraseReportsACascadeItCannotRead(): void {
		$this->seed_user();
		$this->seed_post( 10 );
		$this->seed_post( 11, 99 );
		update_post_meta( 10, 'job_title', 'Engineer' );
		update_post_meta( 11, 'job_title', 'Dependent record' );

		$manager = $this->manager_with_cascade( true, [ 11 ], [ 'read' => [ 'read_records' ] ], false );
		$result  = $this->privacy( $manager )->erase( 'subject@example.com' );

		$this->assertTrue( $result['items_retained'], 'An erasure that missed content is not complete.' );
		$this->assertNotSame( [], $result['messages'], 'The operator is told which relationship blocked it.' );
		$this->assertStringContainsString( 'records', $result['messages'][0] );
		$this->assertSame(
			'Dependent record',
			get_post_meta( 11, 'job_title', true ),
			'The policy is reported, not bypassed: the dependent is left for a capable account.'
		);
	}

	/**
	 * A cascade the operator *can* read must still erase silently. Without this the
	 * warning path could regress into warning on every erasure.
	 */
	public function testEraseOfAReadableCascadeReportsNothing(): void {
		$this->seed_user();
		$this->seed_post( 10 );
		$this->seed_post( 11, 99 );
		update_post_meta( 10, 'job_title', 'Engineer' );
		update_post_meta( 11, 'job_title', 'Dependent record' );

		$manager = $this->manager_with_cascade( true, [ 11 ], [ 'read' => [ 'read_records' ] ], true );
		$result  = $this->privacy( $manager )->erase( 'subject@example.com' );

		$this->assertSame( [], $result['messages'] );
		$this->assertSame( '', get_post_meta( 11, 'job_title', true ), 'A readable cascade dependent is erased.' );
	}

	/**
	 * A denied relationship that does not cascade is not something an erasure would
	 * ever have followed, so it must not produce a warning.
	 */
	public function testEraseDoesNotWarnForADeniedNonCascadeRelationship(): void {
		$this->seed_user();
		$this->seed_post( 10 );
		$this->seed_post( 11, 99 );
		update_post_meta( 10, 'job_title', 'Engineer' );

		$manager = $this->manager_with_cascade( false, [ 11 ], [ 'read' => [ 'read_records' ] ], false );
		$result  = $this->privacy( $manager )->erase( 'subject@example.com' );

		$this->assertSame( [], $result['messages'] );
	}

	/** A non-cascade relationship must be left alone: it is shared, not dependent. */
	public function testEraseDoesNotFollowNonCascadeRelationships(): void {
		$this->seed_user();
		$this->seed_post( 10 );
		// Authored by someone else: reachable only through the relationship, so this
		// isolates the cascade path from the "subject's own post" path.
		$this->seed_post( 11, 99 );
		update_post_meta( 10, 'job_title', 'Engineer' );
		update_post_meta( 11, 'job_title', 'Shared record' );

		$this->privacy( $this->manager_with_cascade( false, [ 11 ] ) )->erase( 'subject@example.com' );

		$this->assertSame( 'Shared record', get_post_meta( 11, 'job_title', true ), 'A shared post must survive erasure.' );
	}

	/** Relationships are optional, so the eraser must work without a manager. */
	public function testEraseWorksWithoutTheRelationshipsFeature(): void {
		$this->seed_user();
		$this->seed_post( 10 );
		update_post_meta( 10, 'job_title', 'Engineer' );

		$result = $this->privacy( null )->erase( 'subject@example.com' );

		$this->assertTrue( $result['items_removed'] );
	}
}
