<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Meta\FieldCipher;
use Saltus\WP\Framework\Features\Meta\FieldEncryptionKeys;
use Saltus\WP\Framework\Features\Meta\FieldEncryptionPolicy;
use Saltus\WP\Framework\Features\Meta\MetaFieldProvider;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\Features\Meta\FieldEncryptionPolicy
 */
class FieldEncryptionPolicyTest extends TestCase {

	private const TEST_KEY = 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

	protected function setUp(): void {
		global $wp_filter_values, $wp_filters_registered;
		$wp_filter_values      = [];
		$wp_filters_registered = [];
	}

	protected function tearDown(): void {
		global $wp_filter_values, $wp_filters_registered;
		$wp_filter_values      = [];
		$wp_filters_registered = [];
	}

	private function policy(): FieldEncryptionPolicy {
		add_filter( FieldEncryptionKeys::KEY_FILTER, static fn() => self::TEST_KEY );

		return new FieldEncryptionPolicy( new MetaFieldProvider(), new FieldCipher( new FieldEncryptionKeys() ) );
	}

	/** A policy with no key configured, to test the fail-closed path. */
	private function keyless_policy(): FieldEncryptionPolicy {
		return new FieldEncryptionPolicy( new MetaFieldProvider(), new FieldCipher( new FieldEncryptionKeys() ) );
	}

	/**
	 * @param array<string, mixed> $meta
	 */
	private function modeler( array $meta ): Modeler {
		$model = $this->createStub( Model::class );
		$model->method( 'get_config' )->willReturn( [ 'meta' => $meta ] );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_args' )->willReturn( [ 'meta' => $meta ] );

		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( [ 'book' => $model ] );

		return $modeler;
	}

	/** An unserialized metabox: each field owns its own meta key. */
	private function flat_meta(): array {
		return [
			'employment' => [
				'register_rest_api' => true,
				'fields'            => [
					'title'  => [ 'type' => 'text', 'title' => 'Job Title' ],
					'ssn'    => [ 'type' => 'text', 'title' => 'SSN', 'encrypted' => true ],
				],
			],
		];
	}

	/** A serialized metabox: many fields under one meta key. */
	private function serialized_meta(): array {
		return [
			'records' => [
				'data_type'         => 'serialize',
				'register_rest_api' => true,
				'fields'            => [
					'public_note' => [ 'type' => 'text', 'title' => 'Note' ],
					'private'     => [
						'type'   => 'fieldset',
						'title'  => 'Private',
						'fields' => [
							'ssn'  => [ 'type' => 'text', 'title' => 'SSN', 'encrypted' => true ],
							'memo' => [ 'type' => 'text', 'title' => 'Memo' ],
						],
					],
				],
			],
		];
	}

	public function testEncryptedPathsListsOnlyDeclaredFields(): void {
		$policy = $this->policy();

		$this->assertSame( [ 'ssn' ], $policy->encrypted_paths( $this->modeler( $this->flat_meta() ), 'book' ) );
		$this->assertSame( [ 'records.private.ssn' ], $policy->encrypted_paths( $this->modeler( $this->serialized_meta() ), 'book' ) );
	}

	public function testHasEncryptedFieldsDistinguishesConfiguredPostTypes(): void {
		$policy = $this->policy();

		$this->assertTrue( $policy->has_encrypted_fields( $this->modeler( $this->flat_meta() ), 'book' ) );
		$this->assertFalse(
			$policy->has_encrypted_fields(
				$this->modeler( [ 'employment' => [ 'fields' => [ 'title' => [ 'type' => 'text' ] ] ] ] ),
				'book'
			)
		);
	}

	// --- Write path ---

	public function testEncryptPayloadReplacesOnlyTheDeclaredField(): void {
		$policy  = $this->policy();
		$modeler = $this->modeler( $this->flat_meta() );

		$result = $policy->encrypt_payload( $modeler, 'book', [ 'title' => 'Engineer', 'ssn' => '123-45-6789' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'Engineer', $result['title'], 'An undeclared field must be stored as-is.' );
		$this->assertStringStartsWith( 'saltus:v1', (string) $result['ssn'] );
		$this->assertStringNotContainsString( '123-45-6789', (string) $result['ssn'] );
	}

	public function testEncryptPayloadLeavesSurroundingSerializedStructureIntact(): void {
		$policy  = $this->policy();
		$modeler = $this->modeler( $this->serialized_meta() );

		$result = $policy->encrypt_payload(
			$modeler,
			'book',
			[
				'records' => [
					'public_note' => 'visible',
					'private'     => [ 'ssn' => '123-45-6789', 'memo' => 'also visible' ],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'visible', $result['records']['public_note'] );
		$this->assertSame( 'also visible', $result['records']['private']['memo'], 'A sibling leaf must not be encrypted.' );
		$this->assertStringStartsWith( 'saltus:v1', (string) $result['records']['private']['ssn'] );
	}

	/**
	 * Failing closed. Writing plaintext to a field the author marked encrypted
	 * would defeat the declaration silently and leave the value unprotected.
	 */
	public function testEncryptPayloadFailsClosedWhenNoKeyIsConfigured(): void {
		$result = $this->keyless_policy()->encrypt_payload( $this->modeler( $this->flat_meta() ), 'book', [ 'ssn' => '123-45-6789' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'field_encryption_unavailable', $result->get_error_code() );
	}

	public function testEncryptPayloadIsAPassThroughWhenNothingIsDeclared(): void {
		$meta    = [ 'employment' => [ 'fields' => [ 'title' => [ 'type' => 'text' ] ] ] ];
		$payload = [ 'title' => 'Engineer' ];

		$this->assertSame( $payload, $this->keyless_policy()->encrypt_payload( $this->modeler( $meta ), 'book', $payload ) );
	}

	// --- Read path ---

	public function testStoredValueRoundTripsThroughDecryptStored(): void {
		$policy  = $this->policy();
		$modeler = $this->modeler( $this->flat_meta() );

		$stored = $policy->encrypt_payload( $modeler, 'book', [ 'ssn' => '123-45-6789' ] );
		$this->assertIsArray( $stored );

		$this->assertSame( '123-45-6789', $policy->decrypt_stored( $modeler, 'book', 'ssn', $stored['ssn'] ) );
	}

	public function testSerializedValueRoundTripsAndKeepsItsShape(): void {
		$policy  = $this->policy();
		$modeler = $this->modeler( $this->serialized_meta() );

		$original = [
			'records' => [
				'public_note' => 'visible',
				'private'     => [ 'ssn' => '123-45-6789', 'memo' => 'memo' ],
			],
		];

		$stored = $policy->encrypt_payload( $modeler, 'book', $original );
		$this->assertIsArray( $stored );

		$this->assertSame(
			$original['records'],
			$policy->decrypt_stored( $modeler, 'book', 'records', $stored['records'] )
		);
	}

	/** One unreadable field must not make the whole post unreadable. */
	public function testUnreadableValueYieldsNullRatherThanFailingTheRead(): void {
		$policy  = $this->policy();
		$modeler = $this->modeler( $this->flat_meta() );

		$stored = $policy->encrypt_payload( $modeler, 'book', [ 'ssn' => '123-45-6789' ] );
		$this->assertIsArray( $stored );

		// Corrupt the envelope.
		$envelope = (string) $stored['ssn'];
		$mutated  = 'saltus:v1:' . ( $envelope[10] === 'A' ? 'B' : 'A' ) . substr( $envelope, 11 );

		$this->assertNull( $policy->decrypt_stored( $modeler, 'book', 'ssn', $mutated ) );
	}

	public function testDecryptValueSurfacesTheErrorWhenACallerWantsIt(): void {
		$policy = $this->policy();

		$envelope = $policy->encrypt_payload( $this->modeler( $this->flat_meta() ), 'book', [ 'ssn' => 'x' ] );
		$this->assertIsArray( $envelope );

		$stored  = (string) $envelope['ssn'];
		$mutated = 'saltus:v1:' . ( $stored[10] === 'A' ? 'B' : 'A' ) . substr( $stored, 11 );

		$result = $policy->decrypt_value( $mutated );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'field_decryption_failed', $result->get_error_code() );
	}

	public function testUndeclaredKeyIsReturnedUntouchedOnRead(): void {
		$policy  = $this->policy();
		$modeler = $this->modeler( $this->flat_meta() );

		$this->assertSame( 'Engineer', $policy->decrypt_stored( $modeler, 'book', 'title', 'Engineer' ) );
	}

	// --- The trade-off: unqueryable ---

	/**
	 * The roadmap's requirement: an encrypted field rejected from a query with an
	 * actionable hint. Ciphertext does not compare, so the query would silently
	 * return nothing — the error is what turns that into a fixable message.
	 */
	public function testQueryOnAnEncryptedFieldIsRejectedWithAnActionableHint(): void {
		$policy = $this->policy();

		$result = $policy->reject_query_arguments( $this->modeler( $this->flat_meta() ), 'book', [ 'ssn' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'field_not_queryable', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 'ssn', $data['field'], 'The error must name the offending field.' );
		$this->assertStringContainsString( 'encrypted', (string) $data['hint'] );
		$this->assertStringContainsString( 'sorted', (string) $data['hint'], 'The hint must state what is not possible.' );
	}

	public function testQueryOnAnUnencryptedFieldIsAllowed(): void {
		$policy = $this->policy();

		$this->assertNull( $policy->reject_query_arguments( $this->modeler( $this->flat_meta() ), 'book', [ 'title' ] ) );
	}

	public function testQueryRejectionCoversTheMetaKeyAsWellAsThePath(): void {
		$policy = $this->policy();

		// A serialized encrypted field is reachable by its storing key too, and a
		// query on that key is equally unanswerable.
		$result = $policy->reject_query_arguments( $this->modeler( $this->serialized_meta() ), 'book', [ 'records' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'field_not_queryable', $result->get_error_code() );
	}

	public function testQueryRejectionIsInertWhenNothingIsEncrypted(): void {
		$meta = [ 'employment' => [ 'fields' => [ 'title' => [ 'type' => 'text' ] ] ] ];

		$this->assertNull(
			$this->keyless_policy()->reject_query_arguments( $this->modeler( $meta ), 'book', [ 'title', 'anything' ] )
		);
	}

	public function testMixedQueryRejectsWhenAnyReferenceIsEncrypted(): void {
		$policy = $this->policy();

		$result = $policy->reject_query_arguments( $this->modeler( $this->flat_meta() ), 'book', [ 'title', 'ssn' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ssn', $result->get_error_data()['field'] );
	}
}
