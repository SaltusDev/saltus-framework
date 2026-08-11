<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Meta\FieldCipher;
use Saltus\WP\Framework\Features\Meta\FieldEncryptionKeys;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\Features\Meta\FieldCipher
 * @covers \Saltus\WP\Framework\Features\Meta\FieldEncryptionKeys
 */
class FieldCipherTest extends TestCase {

	/** A deterministic 32-byte key, base64 encoded as a site would configure it. */
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

	/**
	 * A cipher whose key comes from the filter.
	 *
	 * The constant cannot be defined per-test — `define()` leaks into every test
	 * that runs after it in the same process, the same trap the metabox autosave
	 * guard hit. The filter path is functionally equivalent for these assertions
	 * and resettable, so it is what the tests use.
	 */
	private function cipher( string $key = self::TEST_KEY ): FieldCipher {
		add_filter(
			FieldEncryptionKeys::KEY_FILTER,
			static function () use ( $key ) {
				return $key;
			}
		);

		return new FieldCipher( new FieldEncryptionKeys() );
	}

	public function testRoundTripsAString(): void {
		$cipher = $this->cipher();

		$envelope = $cipher->encrypt( 'sensitive value' );

		$this->assertIsString( $envelope );
		$this->assertSame( 'sensitive value', $cipher->decrypt( $envelope ) );
	}

	/** The stored form must not contain the plaintext anywhere. */
	public function testCiphertextDoesNotContainThePlaintext(): void {
		$envelope = $this->cipher()->encrypt( 'salary-190000' );

		$this->assertIsString( $envelope );
		$this->assertStringNotContainsString( 'salary-190000', $envelope );
		$this->assertStringNotContainsString( '190000', $envelope );
	}

	public function testEnvelopeCarriesAVersionPrefix(): void {
		$envelope = $this->cipher()->encrypt( 'x' );

		$this->assertIsString( $envelope );
		$this->assertStringStartsWith( 'saltus:v1', $envelope, 'Stored ciphertext must be self-describing.' );
	}

	/**
	 * Types must survive. A field holding a number or an array would otherwise
	 * come back as a JSON string and silently change shape.
	 */
	public function testRoundTripsNonStringTypes(): void {
		$cipher = $this->cipher();

		foreach ( [ 42, 1.5, true, false, [ 'a' => 1, 'b' => [ 2, 3 ] ], [], null ] as $value ) {
			$envelope = $cipher->encrypt( $value );
			$this->assertIsString( $envelope, 'Encrypting ' . var_export( $value, true ) );
			$this->assertSame( $value, $cipher->decrypt( $envelope ), 'Round trip of ' . var_export( $value, true ) );
		}
	}

	/** A random nonce per value: the same plaintext must not produce the same ciphertext. */
	public function testSamePlaintextEncryptsDifferentlyEachTime(): void {
		$cipher = $this->cipher();

		$first  = $cipher->encrypt( 'identical' );
		$second = $cipher->encrypt( 'identical' );

		$this->assertNotSame( $first, $second, 'A fixed nonce would leak that two fields hold the same value.' );
		$this->assertSame( 'identical', $cipher->decrypt( $first ) );
		$this->assertSame( 'identical', $cipher->decrypt( $second ) );
	}

	/**
	 * Authenticated encryption: a modified ciphertext must fail rather than
	 * decrypt to altered plaintext.
	 */
	public function testTamperedCiphertextFailsAuthentication(): void {
		$cipher   = $this->cipher();
		$envelope = $cipher->encrypt( 'trusted value' );
		$this->assertIsString( $envelope );

		// Flip a character inside the base64 payload.
		$prefix   = 'saltus:v1:';
		$payload  = substr( $envelope, strlen( $prefix ) );
		$mutated  = $prefix . ( $payload[0] === 'A' ? 'B' : 'A' ) . substr( $payload, 1 );

		$result = $cipher->decrypt( $mutated );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'field_decryption_failed', $result->get_error_code() );
	}

	public function testWrongKeyCannotDecrypt(): void {
		global $wp_filters_registered;

		$envelope = $this->cipher()->encrypt( 'secret' );
		$this->assertIsString( $envelope );

		$wp_filters_registered = [];
		$other                 = $this->cipher( base64_encode( str_repeat( 'z', 32 ) ) );

		$result = $other->decrypt( $envelope );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'field_decryption_failed', $result->get_error_code() );
	}

	public function testEncryptingWithoutAKeyIsAnActionableError(): void {
		$cipher = new FieldCipher( new FieldEncryptionKeys() );

		$result = $cipher->encrypt( 'value' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'field_encryption_unavailable', $result->get_error_code() );
		$this->assertStringContainsString(
			FieldEncryptionKeys::KEY_CONSTANT,
			(string) $result->get_error_data()['hint'],
			'The error must name the constant to define.'
		);
	}

	/**
	 * A field switched to `encrypted: true` after it already held plaintext must
	 * keep working. Refusing would make the field unreadable; treating plaintext
	 * as corruption would lose it.
	 */
	public function testPlaintextPassesThroughDecryptUnchanged(): void {
		$cipher = $this->cipher();

		$this->assertSame( 'legacy plaintext', $cipher->decrypt( 'legacy plaintext' ) );
		$this->assertSame( 42, $cipher->decrypt( 42 ) );
	}

	/** Double encryption would nest envelopes and break one decrypt pass. */
	public function testEncryptingAnEnvelopeReturnsItUnchanged(): void {
		$cipher = $this->cipher();

		$once  = $cipher->encrypt( 'value' );
		$this->assertIsString( $once );
		$twice = $cipher->encrypt( $once );

		$this->assertSame( $once, $twice );
		$this->assertSame( 'value', $cipher->decrypt( $twice ), 'One decrypt pass must return plaintext.' );
	}

	public function testIsEncryptedRecognizesOnlyOurEnvelopes(): void {
		$cipher = $this->cipher();

		$envelope = $cipher->encrypt( 'x' );
		$this->assertIsString( $envelope );

		$this->assertTrue( $cipher->is_encrypted( $envelope ) );
		$this->assertFalse( $cipher->is_encrypted( 'saltus:v1' ), 'A partial prefix is not an envelope.' );
		$this->assertFalse( $cipher->is_encrypted( 'plain text' ) );
		$this->assertFalse( $cipher->is_encrypted( 42 ) );
		$this->assertFalse( $cipher->is_encrypted( null ) );
		$this->assertFalse( $cipher->is_encrypted( [ 'a' ] ) );
	}

	public function testDecryptingWithoutAKeyExplainsTheKeyIsMissing(): void {
		global $wp_filters_registered;

		$envelope = $this->cipher()->encrypt( 'secret' );
		$this->assertIsString( $envelope );

		$wp_filters_registered = [];
		$keyless               = new FieldCipher( new FieldEncryptionKeys() );

		$result = $keyless->decrypt( $envelope );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'field_encryption_unavailable', $result->get_error_code() );
	}

	// --- Key resolution ---

	public function testKeyIsAcceptedAsBase64HexOrRawBytes(): void {
		$raw = str_repeat( 'k', 32 );

		foreach ( [ base64_encode( $raw ), bin2hex( $raw ), $raw ] as $encoding ) {
			global $wp_filters_registered;
			$wp_filters_registered = [];

			$cipher   = $this->cipher( $encoding );
			$envelope = $cipher->encrypt( 'value' );

			$this->assertIsString( $envelope, 'Key encoding ' . substr( $encoding, 0, 8 ) . ' must be usable.' );
			$this->assertSame( 'value', $cipher->decrypt( $envelope ) );
		}
	}

	/**
	 * A wrong-length key is refused rather than padded or truncated. Stretching it
	 * would silently weaken every value encrypted with it, invisibly.
	 */
	public function testWrongLengthKeyIsRefused(): void {
		foreach ( [ 'too-short', base64_encode( 'short' ), base64_encode( str_repeat( 'x', 64 ) ) ] as $bad ) {
			global $wp_filters_registered;
			$wp_filters_registered = [];

			$result = $this->cipher( $bad )->encrypt( 'value' );

			$this->assertInstanceOf( \WP_Error::class, $result, 'Key ' . $bad . ' must be refused.' );
			$this->assertSame( 'field_encryption_unavailable', $result->get_error_code() );
		}
	}

	public function testGeneratesAUsableKey(): void {
		$generated = FieldEncryptionKeys::generate();

		$decoded = base64_decode( $generated, true );
		$this->assertIsString( $decoded );
		$this->assertSame( 32, strlen( $decoded ), 'A generated key must be the right length for the cipher.' );

		$cipher   = $this->cipher( $generated );
		$envelope = $cipher->encrypt( 'value' );
		$this->assertIsString( $envelope );
		$this->assertSame( 'value', $cipher->decrypt( $envelope ) );
	}

	public function testGeneratedKeysAreDistinct(): void {
		$this->assertNotSame( FieldEncryptionKeys::generate(), FieldEncryptionKeys::generate() );
	}

	public function testIsConfiguredReflectsKeyAvailability(): void {
		$this->assertFalse( ( new FieldEncryptionKeys() )->is_configured() );

		$this->cipher();
		$this->assertTrue( ( new FieldEncryptionKeys() )->is_configured() );
	}

	// --- The OpenSSL fallback ---

	/**
	 * The fallback is exercised explicitly. On a machine with libsodium it would
	 * otherwise never run here, and a site without libsodium would be the first to
	 * execute it — an untested crypto path is worse than no path.
	 */
	public function testOpensslFallbackRoundTrips(): void {
		add_filter( FieldEncryptionKeys::KEY_FILTER, static fn() => self::TEST_KEY );
		$cipher = new FieldCipher( new FieldEncryptionKeys(), FieldCipher::BACKEND_OPENSSL );

		$envelope = $cipher->encrypt( [ 'nested' => [ 1, 2, 3 ] ] );

		$this->assertIsString( $envelope );
		$this->assertStringStartsWith( 'saltus:v1o:', $envelope, 'The fallback must use its own prefix.' );
		$this->assertSame( [ 'nested' => [ 1, 2, 3 ] ], $cipher->decrypt( $envelope ) );
	}

	public function testOpensslFallbackDetectsTampering(): void {
		add_filter( FieldEncryptionKeys::KEY_FILTER, static fn() => self::TEST_KEY );
		$cipher = new FieldCipher( new FieldEncryptionKeys(), FieldCipher::BACKEND_OPENSSL );

		$envelope = $cipher->encrypt( 'trusted' );
		$this->assertIsString( $envelope );

		$prefix  = 'saltus:v1o:';
		$payload = substr( $envelope, strlen( $prefix ) );
		$mutated = $prefix . ( $payload[0] === 'A' ? 'B' : 'A' ) . substr( $payload, 1 );

		$result = $cipher->decrypt( $mutated );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'field_decryption_failed', $result->get_error_code() );
	}

	/**
	 * The version prefix earns its keep here: a value encrypted before a backend
	 * change stays readable, because decryption follows the envelope rather than
	 * the current preference.
	 */
	public function testEnvelopeIsDecryptedByItsOwnBackendRegardlessOfPreference(): void {
		add_filter( FieldEncryptionKeys::KEY_FILTER, static fn() => self::TEST_KEY );

		$openssl = new FieldCipher( new FieldEncryptionKeys(), FieldCipher::BACKEND_OPENSSL );
		$sodium  = new FieldCipher( new FieldEncryptionKeys(), FieldCipher::BACKEND_SODIUM );

		$from_openssl = $openssl->encrypt( 'written by openssl' );
		$from_sodium  = $sodium->encrypt( 'written by sodium' );
		$this->assertIsString( $from_openssl );
		$this->assertIsString( $from_sodium );

		// Each cipher reads the other's output: the prefix decides, not the setting.
		$this->assertSame( 'written by openssl', $sodium->decrypt( $from_openssl ) );
		$this->assertSame( 'written by sodium', $openssl->decrypt( $from_sodium ) );
	}
}
