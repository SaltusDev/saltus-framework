<?php

namespace Saltus\WP\Framework\Features\Meta;

/**
 * Encrypts and decrypts individual meta values.
 *
 * XChaCha20-Poly1305 through libsodium: authenticated, so a tampered ciphertext
 * fails to decrypt rather than yielding altered plaintext, and a 24-byte nonce
 * that is safe to generate randomly per value. OpenSSL's AES-256-GCM is the
 * fallback where libsodium is absent, with the same authenticated-encryption
 * property.
 *
 * Ciphertext carries a version prefix (`saltus:v1:`). Without it, a stored value
 * would have to be guessed at decrypt time, and changing the algorithm later
 * would be indistinguishable from corruption. The prefix is also how
 * `is_encrypted()` recognizes an already-encrypted value, which keeps a
 * double-encrypt from silently nesting envelopes.
 *
 * @api
 */
final class FieldCipher {

	/** Envelope prefix for the sodium format. */
	private const PREFIX_SODIUM = 'saltus:v1:';

	/** Envelope prefix for the OpenSSL fallback. */
	private const PREFIX_OPENSSL = 'saltus:v1o:';

	/** Cipher used by the OpenSSL fallback. */
	private const OPENSSL_CIPHER = 'aes-256-gcm';

	/** Force the sodium backend. */
	public const BACKEND_SODIUM = 'sodium';

	/** Force the OpenSSL backend. */
	public const BACKEND_OPENSSL = 'openssl';

	private FieldEncryptionKeys $keys;

	/**
	 * Backend to encrypt with, or null to prefer sodium and fall back.
	 *
	 * Selectable so the fallback path is reachable in tests on a machine that has
	 * libsodium. An untested crypto path is worse than no path — a site without
	 * libsodium would otherwise be the first to run this code. Decryption always
	 * follows the envelope prefix regardless of this setting, so a value encrypted
	 * with one backend stays readable when the preference changes.
	 */
	private ?string $backend;

	public function __construct( ?FieldEncryptionKeys $keys = null, ?string $backend = null ) {
		$this->keys    = $keys ?? new FieldEncryptionKeys();
		$this->backend = $backend;
	}

	/** Whether encryption can be performed at all. */
	public function is_available(): bool {
		return $this->keys->is_configured() && ( $this->use_sodium() || $this->use_openssl() );
	}

	/**
	 * Whether a stored value is one of our envelopes.
	 *
	 * @param mixed $value Stored meta value.
	 */
	public function is_encrypted( $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}

		return strpos( $value, self::PREFIX_SODIUM ) === 0
			|| strpos( $value, self::PREFIX_OPENSSL ) === 0;
	}

	/**
	 * Encrypt a value into a versioned envelope.
	 *
	 * Non-string values are JSON encoded first so a field holding an array or a
	 * number round-trips; the envelope records that so `decrypt()` restores the
	 * original type rather than returning a JSON string.
	 *
	 * @param mixed $value Plaintext value.
	 * @return string|\WP_Error Envelope, or an error explaining what is missing.
	 */
	public function encrypt( $value ) {
		$key = $this->keys->resolve();
		if ( $key === null ) {
			return new \WP_Error(
				'field_encryption_unavailable',
				__( 'No field encryption key is configured.', 'saltus-framework' ),
				[
					'hint' => sprintf(
						/* translators: 1: constant name, 2: filter name */
						__( "Define %1\$s in wp-config.php, or return a key from the '%2\$s' filter. Generate one with FieldEncryptionKeys::generate().", 'saltus-framework' ),
						FieldEncryptionKeys::KEY_CONSTANT,
						FieldEncryptionKeys::KEY_FILTER
					),
				]
			);
		}

		// An already-encrypted value is returned untouched. Re-encrypting would
		// nest envelopes, and one decrypt pass would then return ciphertext.
		if ( $this->is_encrypted( $value ) ) {
			return (string) $value;
		}

		$encoded = $this->encode_plaintext( $value );
		if ( $encoded === null ) {
			return new \WP_Error(
				'field_encryption_failed',
				__( 'This value cannot be encrypted.', 'saltus-framework' ),
				[ 'hint' => __( 'Encrypted fields must hold values that can be JSON encoded.', 'saltus-framework' ) ]
			);
		}

		if ( $this->use_sodium() ) {
			$nonce      = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
			$ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $encoded, '', $nonce, $key );

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext needs a text-safe encoding for meta storage.
			return self::PREFIX_SODIUM . base64_encode( $nonce . $ciphertext );
		}

		if ( $this->use_openssl() ) {
			$iv_length = (int) openssl_cipher_iv_length( self::OPENSSL_CIPHER );
			$iv        = random_bytes( $iv_length > 0 ? $iv_length : 12 );
			$tag       = '';
			$encrypted = openssl_encrypt( $encoded, self::OPENSSL_CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );

			if ( ! is_string( $encrypted ) ) {
				return new \WP_Error(
					'field_encryption_failed',
					__( 'Encryption failed.', 'saltus-framework' ),
					[ 'hint' => __( 'The OpenSSL extension rejected the operation. Check that aes-256-gcm is available.', 'saltus-framework' ) ]
				);
			}

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext needs a text-safe encoding for meta storage.
			return self::PREFIX_OPENSSL . base64_encode( $iv . $tag . $encrypted );
		}

		return new \WP_Error(
			'field_encryption_unavailable',
			__( 'No encryption backend is available.', 'saltus-framework' ),
			[ 'hint' => __( 'Enable the sodium or openssl PHP extension to use encrypted fields.', 'saltus-framework' ) ]
		);
	}

	/**
	 * Decrypt an envelope back to its original value.
	 *
	 * A value that is not an envelope is returned unchanged, so a field switched
	 * to `encrypted: true` after it already held plaintext keeps working. That is
	 * a deliberate trade: refusing would make the field unreadable, and the
	 * alternative — treating plaintext as corruption — loses data.
	 *
	 * @param mixed $value Stored value.
	 * @return mixed|\WP_Error Original value, or an error when authentication fails.
	 */
	public function decrypt( $value ) {
		if ( ! $this->is_encrypted( $value ) ) {
			return $value;
		}

		$key = $this->keys->resolve();
		if ( $key === null ) {
			return new \WP_Error(
				'field_encryption_unavailable',
				__( 'No field encryption key is configured.', 'saltus-framework' ),
				[
					'hint' => sprintf(
						/* translators: %s: constant name */
						__( 'This value is encrypted but no key is available to read it. Restore %s in wp-config.php.', 'saltus-framework' ),
						FieldEncryptionKeys::KEY_CONSTANT
					),
				]
			);
		}

		$envelope = (string) $value;

		if ( strpos( $envelope, self::PREFIX_SODIUM ) === 0 ) {
			return $this->decrypt_sodium( substr( $envelope, strlen( self::PREFIX_SODIUM ) ), $key );
		}

		return $this->decrypt_openssl( substr( $envelope, strlen( self::PREFIX_OPENSSL ) ), $key );
	}

	/**
	 * @return mixed|\WP_Error
	 */
	private function decrypt_sodium( string $payload, string $key ) {
		if ( ! $this->has_sodium() ) {
			return $this->backend_missing( 'sodium' );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding our own stored ciphertext envelope.
		$raw = base64_decode( $payload, true );
		$min = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
		if ( ! is_string( $raw ) || strlen( $raw ) <= $min ) {
			return $this->tampered();
		}

		$nonce      = substr( $raw, 0, $min );
		$ciphertext = substr( $raw, $min );
		$plaintext  = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $ciphertext, '', $nonce, $key );

		if ( ! is_string( $plaintext ) ) {
			return $this->tampered();
		}

		return $this->decode_plaintext( $plaintext );
	}

	/**
	 * @return mixed|\WP_Error
	 */
	private function decrypt_openssl( string $payload, string $key ) {
		if ( ! $this->has_openssl() ) {
			return $this->backend_missing( 'openssl' );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding our own stored ciphertext envelope.
		$raw       = base64_decode( $payload, true );
		$iv_length = (int) openssl_cipher_iv_length( self::OPENSSL_CIPHER );
		$iv_length = $iv_length > 0 ? $iv_length : 12;
		if ( ! is_string( $raw ) || strlen( $raw ) <= $iv_length + 16 ) {
			return $this->tampered();
		}

		$iv         = substr( $raw, 0, $iv_length );
		$tag        = substr( $raw, $iv_length, 16 );
		$ciphertext = substr( $raw, $iv_length + 16 );
		$plaintext  = openssl_decrypt( $ciphertext, self::OPENSSL_CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );

		if ( ! is_string( $plaintext ) ) {
			return $this->tampered();
		}

		return $this->decode_plaintext( $plaintext );
	}

	/** An authentication failure, which is tampering or a wrong key. */
	private function tampered(): \WP_Error {
		return new \WP_Error(
			'field_decryption_failed',
			__( 'This encrypted value could not be read.', 'saltus-framework' ),
			[
				'hint' => __( 'The value failed authentication: it was altered in storage, or the encryption key changed. Encrypted values cannot be recovered with a different key.', 'saltus-framework' ),
			]
		);
	}

	private function backend_missing( string $extension ): \WP_Error {
		return new \WP_Error(
			'field_encryption_unavailable',
			__( 'The extension this value was encrypted with is not available.', 'saltus-framework' ),
			[
				'hint' => sprintf(
					/* translators: %s: PHP extension name */
					__( 'This value was encrypted using the %s extension, which is not loaded. Enable it to read the value.', 'saltus-framework' ),
					$extension
				),
			]
		);
	}

	/**
	 * Wrap a value so its type survives the round trip.
	 *
	 * @param mixed $value
	 */
	private function encode_plaintext( $value ): ?string {
		// `wp_json_encode` where available; the raw call is the fallback so the
		// cipher still works outside a WordPress request, as its tests run.
		$encoded = function_exists( 'wp_json_encode' )
			? wp_json_encode( [ 'v' => $value ] )
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			: json_encode( [ 'v' => $value ] );

		return is_string( $encoded ) ? $encoded : null;
	}

	/**
	 * Unwrap a decrypted payload back to its original type.
	 *
	 * @return mixed
	 */
	private function decode_plaintext( string $plaintext ) {
		$decoded = json_decode( $plaintext, true );

		// A payload that decrypted but does not carry the wrapper is returned as-is
		// rather than as null: authentication already proved it is ours, so losing
		// it to a shape mismatch would be worse than handing back the raw string.
		if ( ! is_array( $decoded ) || ! array_key_exists( 'v', $decoded ) ) {
			return $plaintext;
		}

		return $decoded['v'];
	}

	/** Whether sodium is both available and selected for encryption. */
	private function use_sodium(): bool {
		return $this->backend !== self::BACKEND_OPENSSL && $this->has_sodium();
	}

	/** Whether OpenSSL is both available and selected for encryption. */
	private function use_openssl(): bool {
		return $this->backend !== self::BACKEND_SODIUM && $this->has_openssl();
	}

	private function has_sodium(): bool {
		return function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' )
			&& defined( 'SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES' );
	}

	private function has_openssl(): bool {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_cipher_iv_length' );
	}
}
