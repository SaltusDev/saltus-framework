<?php

namespace Saltus\WP\Framework\Features\Meta;

use Saltus\WP\Framework\Modeler;

/**
 * Applies `encrypted: true` to meta values on the way in and out.
 *
 * Encryption is opt-in per field and never covers a whole table. The trade-off
 * it forces is the point of this class: a value stored as ciphertext cannot be
 * queried by value, sorted on, or filtered. `reject_query_arguments()` makes
 * that visible at the call site rather than at query time, where the symptom
 * would be an empty result set with no explanation.
 *
 * Values are encrypted per field path, so a serialized metabox holding one
 * encrypted field keeps the rest of its structure readable.
 *
 * @api
 */
final class FieldEncryptionPolicy {

	private MetaFieldProvider $meta_field_provider;
	private FieldCipher $cipher;

	public function __construct( ?MetaFieldProvider $meta_field_provider = null, ?FieldCipher $cipher = null ) {
		$this->meta_field_provider = $meta_field_provider ?? new MetaFieldProvider();
		$this->cipher              = $cipher ?? new FieldCipher();
	}

	/**
	 * Field paths declaring `encrypted: true` for a post type.
	 *
	 * @return list<string>
	 */
	public function encrypted_paths( Modeler $modeler, string $post_type ): array {
		$paths = [];

		foreach ( $this->normalized_fields( $modeler, $post_type ) as $field ) {
			if ( ! $this->declares_encryption( $field ) ) {
				continue;
			}

			$path = (string) ( $field['path'] ?? '' );
			if ( $path !== '' ) {
				$paths[] = $path;
			}
		}

		return $paths;
	}

	/** Whether any field on this post type is encrypted. */
	public function has_encrypted_fields( Modeler $modeler, string $post_type ): bool {
		return $this->encrypted_paths( $modeler, $post_type ) !== [];
	}

	/**
	 * Meta keys that hold at least one encrypted field.
	 *
	 * @return list<string>
	 */
	public function encrypted_meta_keys( Modeler $modeler, string $post_type ): array {
		$keys = [];

		foreach ( $this->normalized_fields( $modeler, $post_type ) as $field ) {
			if ( ! $this->declares_encryption( $field ) ) {
				continue;
			}

			$key = (string) ( $field['meta_key'] ?? '' );
			if ( $key !== '' && ! in_array( $key, $keys, true ) ) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	/**
	 * Reject a query that references an encrypted field.
	 *
	 * Ciphertext does not compare, order, or match, so a query naming an encrypted
	 * field cannot be answered — it would return nothing and look like "no results"
	 * rather than "not possible". Returning an error with the field named is the
	 * difference between a five-minute fix and an afternoon.
	 *
	 * @param list<string> $referenced Field paths or meta keys the query uses.
	 */
	public function reject_query_arguments( Modeler $modeler, string $post_type, array $referenced ): ?\WP_Error {
		$encrypted = array_merge(
			$this->encrypted_paths( $modeler, $post_type ),
			$this->encrypted_meta_keys( $modeler, $post_type )
		);
		if ( $encrypted === [] ) {
			return null;
		}

		foreach ( $referenced as $reference ) {
			$name = (string) $reference;
			if ( ! in_array( $name, $encrypted, true ) ) {
				continue;
			}

			return new \WP_Error(
				'field_not_queryable',
				__( 'This field is encrypted and cannot be used in a query.', 'saltus-framework' ),
				[
					'status' => 400,
					'field'  => $name,
					'hint'   => sprintf(
						/* translators: %s: field path */
						__( "The field '%s' declares 'encrypted' => true, so it is stored as ciphertext and cannot be searched, sorted, or filtered. Query on an unencrypted field instead, or remove the encryption declaration if the field needs to be queryable.", 'saltus-framework' ),
						$name
					),
				]
			);
		}

		return null;
	}

	/**
	 * Encrypt the encrypted-declared parts of a meta payload before storage.
	 *
	 * Returns the payload with those values replaced by envelopes, or a `WP_Error`
	 * when encryption is declared but not possible. Failing closed matters here:
	 * writing plaintext to a field the author marked encrypted would defeat the
	 * declaration silently, and the value would sit unprotected in the database.
	 *
	 * @param array<string, mixed> $meta Submitted meta, keyed by meta key.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function encrypt_payload( Modeler $modeler, string $post_type, array $meta ) {
		$fields = $this->encrypted_fields_by_key( $modeler, $post_type );
		if ( $fields === [] ) {
			return $meta;
		}

		foreach ( $meta as $key => $value ) {
			$paths = $fields[ (string) $key ] ?? null;
			if ( $paths === null ) {
				continue;
			}

			$result = $this->transform( $value, (string) $key, $paths, true );
			if ( $result instanceof \WP_Error ) {
				return $result;
			}

			$meta[ $key ] = $result;
		}

		return $meta;
	}

	/**
	 * Decrypt the encrypted-declared parts of a stored meta value.
	 *
	 * A failed decrypt yields null for that value rather than an error for the
	 * whole read: one unreadable field should not make a post unreadable. The
	 * error is available through `decrypt_value()` when a caller wants it.
	 *
	 * @param mixed $value Stored meta value.
	 * @return mixed
	 */
	public function decrypt_stored( Modeler $modeler, string $post_type, string $meta_key, $value ) {
		$fields = $this->encrypted_fields_by_key( $modeler, $post_type );
		$paths  = $fields[ $meta_key ] ?? null;
		if ( $paths === null ) {
			return $value;
		}

		$result = $this->transform( $value, $meta_key, $paths, false );

		return $result instanceof \WP_Error ? null : $result;
	}

	/**
	 * Decrypt a single value, surfacing any error.
	 *
	 * @param mixed $value
	 * @return mixed|\WP_Error
	 */
	public function decrypt_value( $value ) {
		return $this->cipher->decrypt( $value );
	}

	/** The cipher, for callers needing to check availability before declaring. */
	public function cipher(): FieldCipher {
		return $this->cipher;
	}

	/**
	 * Apply the cipher to the declared paths within one meta value.
	 *
	 * A field owning its own meta key transforms the value directly. A serialized
	 * metabox stores a nested array under one key, so only the declared leaves are
	 * transformed and the surrounding structure is preserved.
	 *
	 * @param mixed        $value Stored or submitted value.
	 * @param list<string> $paths Encrypted field paths under this key.
	 * @return mixed|\WP_Error
	 */
	private function transform( $value, string $meta_key, array $paths, bool $encrypting ) {
		foreach ( $paths as $path ) {
			if ( $path === $meta_key ) {
				return $encrypting ? $this->cipher->encrypt( $value ) : $this->cipher->decrypt( $value );
			}
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $paths as $path ) {
			if ( strpos( $path, $meta_key . '.' ) !== 0 ) {
				continue;
			}

			$segments = explode( '.', substr( $path, strlen( $meta_key ) + 1 ) );
			$result   = $this->transform_at( $value, $segments, $encrypting );
			if ( $result instanceof \WP_Error ) {
				return $result;
			}

			$value = $result;
		}

		return $value;
	}

	/**
	 * Transform one nested leaf, leaving the rest of the structure alone.
	 *
	 * @param array<string, mixed> $subject
	 * @param list<string>         $segments
	 * @return array<string, mixed>|\WP_Error
	 */
	private function transform_at( array $subject, array $segments, bool $encrypting ) {
		$segment = array_shift( $segments );
		if ( $segment === null || ! array_key_exists( $segment, $subject ) ) {
			return $subject;
		}

		if ( $segments === [] ) {
			$transformed = $encrypting
				? $this->cipher->encrypt( $subject[ $segment ] )
				: $this->cipher->decrypt( $subject[ $segment ] );

			if ( $transformed instanceof \WP_Error ) {
				return $transformed;
			}

			$subject[ $segment ] = $transformed;

			return $subject;
		}

		if ( ! is_array( $subject[ $segment ] ) ) {
			return $subject;
		}

		$nested = $this->transform_at( $subject[ $segment ], $segments, $encrypting );
		if ( $nested instanceof \WP_Error ) {
			return $nested;
		}

		$subject[ $segment ] = $nested;

		return $subject;
	}

	/**
	 * Encrypted field paths grouped by the meta key that stores them.
	 *
	 * @return array<string, list<string>>
	 */
	private function encrypted_fields_by_key( Modeler $modeler, string $post_type ): array {
		$grouped = [];

		foreach ( $this->normalized_fields( $modeler, $post_type ) as $field ) {
			if ( ! $this->declares_encryption( $field ) ) {
				continue;
			}

			$key  = (string) ( $field['meta_key'] ?? '' );
			$path = (string) ( $field['path'] ?? '' );
			if ( $key === '' || $path === '' ) {
				continue;
			}

			$grouped[ $key ][] = $path;
		}

		return $grouped;
	}

	/**
	 * Whether a normalized field declares encryption.
	 *
	 * @param array<string, mixed> $field
	 */
	private function declares_encryption( array $field ): bool {
		$raw = $field['raw'] ?? null;

		return is_array( $raw ) && ! empty( $raw['encrypted'] );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function normalized_fields( Modeler $modeler, string $post_type ): array {
		$models = $modeler->get_models();
		$model  = $models[ $post_type ] ?? null;
		if ( $model === null ) {
			return [];
		}

		$config = $model->get_config();
		$meta   = $config['meta'] ?? null;
		if ( ! is_array( $meta ) ) {
			return [];
		}

		return $this->meta_field_provider->normalize_meta_fields( $meta )['fields'];
	}
}
