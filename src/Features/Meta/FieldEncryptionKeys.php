<?php

namespace Saltus\WP\Framework\Features\Meta;

/**
 * Resolves the key material used to encrypt designated meta fields.
 *
 * A key stored in `wp_options` beside the ciphertext is not encryption — anyone
 * who can read the database can read both. So keys come from exactly two places,
 * in this order:
 *
 * 1. `SALTUS_FIELD_ENCRYPTION_KEY` in `wp-config.php`, which lives on the
 *    filesystem rather than in the database.
 * 2. The `saltus/framework/field_encryption_key` filter, for sites resolving
 *    from an external store — KMS, Vault, a secrets mount.
 *
 * The filter wins when both are set, because a site wiring an external store has
 * made the more deliberate choice. Nothing here ever writes a key anywhere.
 *
 * @api
 */
final class FieldEncryptionKeys {

	/** Constant read from `wp-config.php`. */
	public const KEY_CONSTANT = 'SALTUS_FIELD_ENCRYPTION_KEY';

	/** Filter for resolving from an external store. */
	public const KEY_FILTER = 'saltus/framework/field_encryption_key';

	/** Bytes required by XChaCha20-Poly1305. */
	private const KEY_BYTES = 32;

	/**
	 * The active encryption key, or null when none is configured.
	 *
	 * Returns raw bytes. A configured value may be base64 or hex for
	 * `wp-config.php` readability; both are decoded, and a raw 32-byte string is
	 * accepted as-is.
	 */
	public function resolve(): ?string {
		$candidate = null;

		if ( defined( self::KEY_CONSTANT ) ) {
			$value = constant( self::KEY_CONSTANT );
			if ( is_string( $value ) && $value !== '' ) {
				$candidate = $value;
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the field encryption key.
			 *
			 * Return raw bytes, base64, or hex. Returning null leaves the constant
			 * in effect. This is the hook for resolving from a KMS or secrets store.
			 *
			 * @param string|null $candidate Key from the constant, if any.
			 */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The hook is a fixed, prefixed constant.
			$filtered = apply_filters( self::KEY_FILTER, $candidate );
			if ( is_string( $filtered ) && $filtered !== '' ) {
				$candidate = $filtered;
			}
		}

		if ( $candidate === null ) {
			return null;
		}

		return $this->normalize( $candidate );
	}

	/** Whether a usable key is configured. */
	public function is_configured(): bool {
		return $this->resolve() !== null;
	}

	/**
	 * Decode a configured key to raw bytes, or null when it cannot be used.
	 *
	 * A wrong-length key is rejected rather than stretched or truncated. Padding a
	 * short key would silently weaken every value encrypted with it, and the
	 * failure would be invisible — much worse than refusing to start.
	 */
	private function normalize( string $candidate ): ?string {
		if ( strlen( $candidate ) === self::KEY_BYTES ) {
			return $candidate;
		}

		if ( preg_match( '/^[0-9a-fA-F]{64}$/', $candidate ) === 1 ) {
			$decoded = hex2bin( $candidate );

			return is_string( $decoded ) ? $decoded : null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a configured binary key.
		$decoded = base64_decode( $candidate, true );
		if ( is_string( $decoded ) && strlen( $decoded ) === self::KEY_BYTES ) {
			return $decoded;
		}

		return null;
	}

	/**
	 * A freshly generated key, base64 encoded for pasting into `wp-config.php`.
	 *
	 * Provided so the documented setup step does not send anyone to an online key
	 * generator. Never called during normal operation.
	 */
	public static function generate(): string {
		if ( function_exists( 'random_bytes' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Presenting binary key material for wp-config.php.
			return base64_encode( random_bytes( self::KEY_BYTES ) );
		}

		// Only reachable on a PHP build without a CSPRNG, where encryption should
		// not be used at all. Signalled rather than silently weakened.
		throw new \RuntimeException( 'No cryptographically secure random source is available.' );
	}
}
