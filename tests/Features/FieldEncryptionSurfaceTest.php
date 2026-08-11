<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Meta\FieldEncryptionKeys;
use Saltus\WP\Framework\Features\WebMcp\PublicFieldFilter;
use Saltus\WP\Framework\MCP\Tools\UpdateMetaFields;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Rest\MetaController;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * Encryption through the real write and read paths.
 *
 * The unit tests prove the cipher and the policy work. These prove the wiring
 * does: that a value written through a surface is ciphertext *in storage* and
 * plaintext coming back. A correct cipher wired to nothing protects nothing.
 *
 * @covers \Saltus\WP\Framework\Features\Meta\FieldEncryptionPolicy
 */
class FieldEncryptionSurfaceTest extends TestCase {

	private const TEST_KEY = 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

	protected function setUp(): void {
		global $wp_posts, $wp_post_meta, $wp_current_user_can, $wp_filter_values, $wp_filters_registered, $wp_post_type_objects;

		$wp_posts              = [];
		$wp_post_meta          = [];
		$wp_current_user_can   = true;
		$wp_filter_values      = [];
		$wp_filters_registered = [];
		$wp_post_type_objects  = [];

		add_filter( FieldEncryptionKeys::KEY_FILTER, static fn() => self::TEST_KEY );

		$post         = new \WP_Post( [ 'post_type' => 'book', 'post_title' => 'A Book' ] );
		$post->ID     = 10;
		$wp_posts[10] = $post;
	}

	protected function tearDown(): void {
		global $wp_posts, $wp_post_meta, $wp_current_user_can, $wp_filter_values, $wp_filters_registered, $wp_post_type_objects;

		$wp_posts              = [];
		$wp_post_meta          = [];
		$wp_current_user_can   = true;
		$wp_filter_values      = [];
		$wp_filters_registered = [];
		$wp_post_type_objects  = [];
	}

	private function modeler(): Modeler {
		$meta = [
			'employment' => [
				'register_rest_api' => true,
				'fields'            => [
					'title' => [ 'type' => 'text', 'title' => 'Job Title' ],
					'ssn'   => [ 'type' => 'text', 'title' => 'SSN', 'encrypted' => true ],
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

	/**
	 * The core claim: the database holds ciphertext, not the value. Asserted
	 * against raw storage rather than a getter, because a getter that decrypts
	 * would hide a plaintext write.
	 */
	public function testMcpWriteStoresCiphertextInTheDatabase(): void {
		( new UpdateMetaFields() )->update_meta_fields( $this->modeler(), null, 'book', 10, [ 'ssn' => '123-45-6789' ] );

		global $wp_post_meta;
		$stored = $wp_post_meta[10]['ssn'][0] ?? '';

		$this->assertStringStartsWith( 'saltus:v1', (string) $stored );
		$this->assertStringNotContainsString( '123-45-6789', (string) $stored, 'The plaintext must not be in storage.' );
	}

	public function testRestWriteStoresCiphertextInTheDatabase(): void {
		$controller = new MetaController( $this->modeler() );
		$request    = new \WP_REST_Request();
		$request->set_param( 'post_type', 'book' );
		$request->set_param( 'post_id', 10 );
		$request->set_body_params( [ 'meta' => [ 'ssn' => '123-45-6789' ] ] );

		$controller->update_item( $request );

		global $wp_post_meta;
		$stored = $wp_post_meta[10]['ssn'][0] ?? '';

		$this->assertStringStartsWith( 'saltus:v1', (string) $stored );
		$this->assertStringNotContainsString( '123-45-6789', (string) $stored );
	}

	/** An undeclared field stays plaintext — encryption is opt-in per field. */
	public function testUndeclaredFieldIsStoredAsPlaintext(): void {
		( new UpdateMetaFields() )->update_meta_fields( $this->modeler(), null, 'book', 10, [ 'title' => 'Engineer' ] );

		global $wp_post_meta;

		$this->assertSame( 'Engineer', $wp_post_meta[10]['title'][0] ?? '' );
	}

	/**
	 * The response must report what the caller set. Returning the envelope would
	 * be useless to a client and would put the stored form into logs and caches.
	 */
	public function testResponseReportsPlaintextNotTheEnvelope(): void {
		$result = ( new UpdateMetaFields() )->update_meta_fields( $this->modeler(), null, 'book', 10, [ 'ssn' => '123-45-6789' ] );

		$this->assertIsArray( $result );
		$this->assertSame( '123-45-6789', $result['meta']['ssn'] );
	}

	public function testRestResponseReportsPlaintextNotTheEnvelope(): void {
		$controller = new MetaController( $this->modeler() );
		$request    = new \WP_REST_Request();
		$request->set_param( 'post_type', 'book' );
		$request->set_param( 'post_id', 10 );
		$request->set_body_params( [ 'meta' => [ 'ssn' => '123-45-6789' ] ] );

		$data = rest_ensure_response( $controller->update_item( $request ) )->get_data();

		$this->assertSame( '123-45-6789', $data['meta']['ssn'] );
	}

	/**
	 * An encrypted field is never public. Decrypting it for an anonymous caller
	 * would defeat the reason for encrypting it at all.
	 */
	public function testEncryptedFieldIsNeverExposedToAnAnonymousWebMcpCaller(): void {
		global $wp_current_user_can;
		$wp_current_user_can = false;

		$fields = ( new PublicFieldFilter() )->fields( $this->modeler(), 'book' );
		$paths  = array_map( static fn( array $f ): string => (string) $f['path'], $fields );

		$this->assertContains( 'title', $paths );
		$this->assertNotContains( 'ssn', $paths, 'An encrypted field must not be publicly readable.' );
	}

	/** And its value must not appear either, ciphertext or otherwise. */
	public function testEncryptedValueIsAbsentFromPublicValues(): void {
		( new UpdateMetaFields() )->update_meta_fields( $this->modeler(), null, 'book', 10, [ 'ssn' => '123-45-6789', 'title' => 'Engineer' ] );

		global $wp_current_user_can;
		$wp_current_user_can = false;

		$values = ( new PublicFieldFilter() )->values( $this->modeler(), 10, 'book' );

		$this->assertArrayNotHasKey( 'ssn', $values );
		$this->assertStringNotContainsString( '123-45-6789', (string) wp_json_encode( $values ) );
		$this->assertStringNotContainsString( 'saltus:v1', (string) wp_json_encode( $values ), 'Not even the envelope should leak.' );
	}

	/**
	 * Failing closed at the surface. Without a key, a write to an encrypted field
	 * is refused rather than silently stored in plaintext.
	 */
	public function testWriteWithoutAKeyIsRefusedRatherThanStoredInPlaintext(): void {
		global $wp_filters_registered, $wp_post_meta;
		$wp_filters_registered = [];

		$result = ( new UpdateMetaFields() )->update_meta_fields( $this->modeler(), null, 'book', 10, [ 'ssn' => '123-45-6789' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'field_encryption_unavailable', $result->get_error_code() );
		$this->assertArrayNotHasKey( 'ssn', $wp_post_meta[10] ?? [], 'Nothing may be stored when encryption is unavailable.' );
	}
}
