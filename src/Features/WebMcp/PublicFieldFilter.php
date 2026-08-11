<?php

namespace Saltus\WP\Framework\Features\WebMcp;

use Saltus\WP\Framework\Features\Meta\FieldPermissionPolicy;
use Saltus\WP\Framework\Features\Meta\MetaFieldProvider;
use Saltus\WP\Framework\Modeler;

/**
 * Resolves which meta fields a public WebMCP tool may return.
 *
 * Meta fields are private by default. A field is only exposed when its
 * metabox opts into the REST API, which is the same signal WordPress itself
 * uses to decide whether a meta value is publicly readable. Field types that
 * commonly hold internal or credential-like values are denied outright,
 * regardless of that opt-in.
 * @api
 */
final class PublicFieldFilter {

	/**
	 * Codestar field types never exposed publicly.
	 *
	 * These either hold values that are not content (callbacks, raw markup)
	 * or are conventionally used for internal configuration.
	 */
	private const DENIED_TYPES = [
		'callback',
		'content',
		'notice',
		'subheading',
		'heading',
		'submessage',
		'backup',
		'code_editor',
	];

	/**
	 * Field id fragments that suggest a non-public value.
	 *
	 * Matched case-insensitively against the field path so a metabox that
	 * opts into REST cannot accidentally publish a token or internal note.
	 */
	private const DENIED_FRAGMENTS = [
		'secret',
		'token',
		'api_key',
		'apikey',
		'password',
		'passwd',
		'private',
		'internal',
		'credential',
		'_key',
		'nonce',
		'hash',
		'salt',
	];

	private MetaFieldProvider $meta_field_provider;
	private FieldPermissionPolicy $field_permissions;

	public function __construct( ?MetaFieldProvider $meta_field_provider = null, ?FieldPermissionPolicy $field_permissions = null ) {
		$this->meta_field_provider = $meta_field_provider ?? new MetaFieldProvider();
		$this->field_permissions   = $field_permissions ?? new FieldPermissionPolicy( $this->meta_field_provider );
	}

	/**
	 * Get the public meta field definitions for a post type.
	 *
	 * @param Modeler $modeler   Model registry.
	 * @param string  $post_type Post type slug.
	 * @return list<array<string, mixed>> Normalized field definitions.
	 */
	public function fields( Modeler $modeler, string $post_type ): array {
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

		$normalized = $this->meta_field_provider->normalize_meta_fields( $meta );
		$public     = [];

		foreach ( $normalized['fields'] as $field ) {
			if ( ! $this->is_public_field( $field ) ) {
				continue;
			}

			$public[] = $field;
		}

		/**
		 * Filter which meta fields a public WebMCP tool may return.
		 *
		 * @param list<array<string, mixed>> $public    Public field definitions.
		 * @param string                     $post_type Post type slug.
		 */
		$filtered = $this->accept_fields(
			apply_filters( 'saltus/framework/webmcp/public_fields', $public, $post_type ),
			$public
		);

		// Applied last, deliberately. This composes with the public-field rules
		// rather than duplicating them: a field must be *both* publicly exposable
		// and permitted for this caller. Running after the filter hook means a
		// third-party filter cannot re-add a field the policy denies — if this ran
		// before, the hook would be a bypass. For an anonymous caller every
		// capability check fails, so any field declaring a `permissions` rule is
		// never publicly readable, which is the intended reading of a rule.
		return $this->field_permissions->filter_readable( $filtered );
	}

	/**
	 * Narrow a filtered value back to a list of field definitions.
	 *
	 * @param mixed                      $filtered Filter return value.
	 * @param list<array<string, mixed>> $fallback Fields to use when unusable.
	 * @return list<array<string, mixed>>
	 */
	private function accept_fields( $filtered, array $fallback ): array {
		if ( ! is_array( $filtered ) ) {
			return $fallback;
		}

		$valid = [];
		foreach ( $filtered as $field ) {
			if ( is_array( $field ) ) {
				$valid[] = $field;
			}
		}

		return $valid;
	}

	/**
	 * Resolve public meta values for a single post.
	 *
	 * @param Modeler $modeler Model registry.
	 * @param int     $post_id Post id.
	 * @param string  $post_type Post type slug.
	 * @return array<string, mixed> Field path mapped to its value.
	 */
	public function values( Modeler $modeler, int $post_id, string $post_type ): array {
		$values = [];

		foreach ( $this->fields( $modeler, $post_type ) as $field ) {
			$path     = (string) ( $field['path'] ?? '' );
			$meta_key = (string) ( $field['meta_key'] ?? '' );
			if ( $path === '' || $meta_key === '' ) {
				continue;
			}

			$raw   = get_post_meta( $post_id, $meta_key, true );
			$value = $this->resolve_path_value( $raw, $path, $meta_key );
			if ( $value === null ) {
				continue;
			}

			$values[ $path ] = $value;
		}

		return $values;
	}

	/**
	 * Whether a normalized field definition is safe to expose publicly.
	 *
	 * @param array<string, mixed> $field Normalized field definition.
	 */
	private function is_public_field( array $field ): bool {
		// `writable_rest` carries the metabox's `register_rest_api` opt-in,
		// which is the only signal that the author intended the value to be
		// readable outside the admin. Absent it, the field stays private.
		if ( empty( $field['writable_rest'] ) ) {
			return false;
		}

		$type = strtolower( (string) ( $field['codestar_type'] ?? '' ) );
		if ( in_array( $type, self::DENIED_TYPES, true ) ) {
			return false;
		}

		$path = strtolower( (string) ( $field['path'] ?? '' ) );
		foreach ( self::DENIED_FRAGMENTS as $fragment ) {
			if ( $path !== '' && strpos( $path, $fragment ) !== false ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Extract a dotted-path value from a raw meta value.
	 *
	 * Serialized metaboxes store a nested array under one meta key, so the
	 * field path carries the remaining segments.
	 *
	 * @param mixed  $raw      Raw meta value.
	 * @param string $path     Normalized field path.
	 * @param string $meta_key Meta key the value was read from.
	 * @return mixed|null Resolved value, or null when absent.
	 */
	private function resolve_path_value( $raw, string $path, string $meta_key ) {
		if ( $path === $meta_key ) {
			return $raw === '' ? null : $raw;
		}

		if ( strpos( $path, $meta_key . '.' ) !== 0 ) {
			return $raw === '' ? null : $raw;
		}

		$segments = explode( '.', substr( $path, strlen( $meta_key ) + 1 ) );
		$value    = $raw;

		foreach ( $segments as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return null;
			}

			$value = $value[ $segment ];
		}

		return $value === '' ? null : $value;
	}
}
