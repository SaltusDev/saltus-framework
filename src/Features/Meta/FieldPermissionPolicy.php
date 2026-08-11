<?php

namespace Saltus\WP\Framework\Features\Meta;

use Saltus\WP\Framework\Modeler;

/**
 * Resolves per-field read and write access for authenticated callers.
 *
 * Saltus gates at the surface boundary: `CapabilityPolicy` decides whether a
 * capability is reachable for a model, and each surface checks an edit
 * capability before it writes. Neither gates an *individual field*. Once a
 * caller clears `edit_posts` for a model, every field in it is readable and
 * writable through REST, MCP, WP-CLI, and WebMCP. This policy adds that axis.
 *
 * It resolves against the *normalized* field definitions `MetaFieldProvider`
 * produces rather than re-reading raw config, which is what lets all four
 * surfaces share one answer: they already hold normalized fields, so none of
 * them needs its own notion of what a permission rule means.
 *
 * Two rules govern resolution:
 *
 * 1. **No rule means no change.** A field without a `permissions` key stays
 *    exactly as accessible as it is today. Existing sites cannot break by
 *    upgrading, which is why this does not deny by omission — the opposite of
 *    what a security default usually wants, and deliberate here.
 * 2. **A rule on a parent binds its children.** A rule on `salary` also governs
 *    `salary.amount`. Without this, denying a serialized parent would leak
 *    through any nested field, and the caller could reconstruct the parent from
 *    its parts.
 *
 * @api
 */
final class FieldPermissionPolicy {

	/** Read access: the field may appear in a response. */
	public const OPERATION_READ = 'read';

	/** Write access: the field may be changed by a request. */
	public const OPERATION_WRITE = 'write';

	private MetaFieldProvider $meta_field_provider;

	/** @var callable(string): bool */
	private $capability_check;

	/**
	 * @param MetaFieldProvider|null       $meta_field_provider Shared normalizer.
	 * @param callable(string): bool|null  $capability_check    Capability resolver, defaulting to
	 *                                                          `current_user_can`. Injectable so a
	 *                                                          surface can resolve for a specific
	 *                                                          user rather than the current one.
	 */
	public function __construct( ?MetaFieldProvider $meta_field_provider = null, ?callable $capability_check = null ) {
		$this->meta_field_provider = $meta_field_provider ?? new MetaFieldProvider();
		$this->capability_check    = $capability_check ?? static function ( string $capability ): bool {
			// Absent WordPress there is nothing to check against. Allowing keeps
			// the policy inert outside a request rather than locking every field.
			return ! function_exists( 'current_user_can' ) || current_user_can( $capability );
		};
	}

	/**
	 * Whether one normalized field may be read.
	 *
	 * @param array<string, mixed>             $field  Normalized field definition.
	 * @param list<array<string, mixed>>       $fields All normalized fields, for parent lookup.
	 */
	public function can_read( array $field, array $fields = [] ): bool {
		return $this->is_allowed( $field, $fields, self::OPERATION_READ );
	}

	/**
	 * Whether one normalized field may be written.
	 *
	 * @param array<string, mixed>       $field  Normalized field definition.
	 * @param list<array<string, mixed>> $fields All normalized fields, for parent lookup.
	 */
	public function can_write( array $field, array $fields = [] ): bool {
		return $this->is_allowed( $field, $fields, self::OPERATION_WRITE );
	}

	/**
	 * Narrow a list of normalized fields to those the caller may read.
	 *
	 * @param list<array<string, mixed>> $fields Normalized field definitions.
	 * @return list<array<string, mixed>>
	 */
	public function filter_readable( array $fields ): array {
		$allowed = [];
		foreach ( $fields as $field ) {
			if ( $this->is_allowed( $field, $fields, self::OPERATION_READ ) ) {
				$allowed[] = $field;
			}
		}

		return $allowed;
	}

	/**
	 * Meta keys the caller may not write, for a post type.
	 *
	 * Surfaces apply updates keyed by meta key, so this collapses field-level
	 * rules onto the keys a write actually touches. A serialized metabox stores
	 * many fields under one key: if any field under that key is denied, the key
	 * is reported here, because a whole-key write cannot honor a rule on one of
	 * its parts.
	 *
	 * @return array<string, string> Meta key mapped to the path that denied it.
	 */
	public function denied_write_keys( Modeler $modeler, string $post_type ): array {
		$fields = $this->normalized_fields( $modeler, $post_type );
		$denied = [];

		foreach ( $fields as $field ) {
			if ( $this->is_allowed( $field, $fields, self::OPERATION_WRITE ) ) {
				continue;
			}

			$meta_key = (string) ( $field['meta_key'] ?? '' );
			$path     = (string) ( $field['path'] ?? '' );
			if ( $meta_key === '' || isset( $denied[ $meta_key ] ) ) {
				continue;
			}

			$denied[ $meta_key ] = $path;
		}

		return $denied;
	}

	/**
	 * Drop fields the caller may not read from a meta payload.
	 *
	 * Lives here rather than in each controller because three surfaces filter the
	 * same payload shape, and a per-surface copy is how one of them ends up a
	 * version behind. Only the normalized field list is filtered: the `meta` key
	 * is the author's raw config, and rewriting a nested Codestar structure to
	 * remove one field risks changing its meaning. A denied field's *definition*
	 * is not the sensitive part — its value is, and values are not in this
	 * payload. What this prevents is a denied field appearing in the list clients
	 * use to decide what to request and render.
	 *
	 * @param array<string, mixed> $payload Meta payload for one post type.
	 * @return array<string, mixed>
	 */
	public function filter_payload( array $payload ): array {
		if ( ! isset( $payload['normalized']['fields'] ) || ! is_array( $payload['normalized']['fields'] ) ) {
			return $payload;
		}

		// The payload arrives from a provider call or a REST filter, so its shape is
		// not guaranteed. Narrow to field definitions before resolving anything;
		// a non-array entry is not a field and cannot carry a permission rule.
		$fields = [];
		foreach ( $payload['normalized']['fields'] as $field ) {
			if ( is_array( $field ) ) {
				$fields[] = $field;
			}
		}

		$allowed = $this->filter_readable( $fields );
		if ( count( $allowed ) === count( $fields ) ) {
			return $payload;
		}

		$payload['normalized']['fields'] = $allowed;

		if ( isset( $payload['normalized']['rest_meta_keys'] ) && is_array( $payload['normalized']['rest_meta_keys'] ) ) {
			$payload['normalized']['rest_meta_keys'] = $this->prune_meta_keys(
				$payload['normalized']['rest_meta_keys'],
				$allowed
			);
		}

		return $payload;
	}

	/**
	 * Drop advertised meta keys that no longer have a readable field.
	 *
	 * A key whose every field was filtered out must stop being advertised as
	 * REST-writable, or a client would try to write a key the update path rejects.
	 *
	 * @param array<mixed>               $meta_keys Advertised meta key entries.
	 * @param list<array<string, mixed>> $allowed   Fields that survived filtering.
	 * @return list<mixed>
	 */
	private function prune_meta_keys( array $meta_keys, array $allowed ): array {
		$live = [];
		foreach ( $allowed as $field ) {
			$live[ (string) ( $field['meta_key'] ?? '' ) ] = true;
		}

		$kept = [];
		foreach ( $meta_keys as $meta_key_info ) {
			$key = is_array( $meta_key_info ) ? (string) ( $meta_key_info['meta_key'] ?? '' ) : '';
			if ( isset( $live[ $key ] ) ) {
				$kept[] = $meta_key_info;
			}
		}

		return $kept;
	}

	/**
	 * Reject a write touching any denied meta key.
	 *
	 * Returns a `WP_Error` naming the offending field, or null when every key is
	 * writable. Shared so REST, MCP, and WP-CLI return the same error code and
	 * hint for the same denial rather than three near-identical messages.
	 *
	 * Rejection rather than silent skipping is deliberate: dropping a denied key
	 * would return success with that key absent, which a client cannot tell from
	 * "written, value unchanged".
	 *
	 * @param array<string, mixed> $meta Submitted meta, keyed by meta key.
	 */
	public function reject_denied_write( Modeler $modeler, string $post_type, array $meta ): ?\WP_Error {
		$denied = $this->denied_write_keys( $modeler, $post_type );
		if ( $denied === [] ) {
			return null;
		}

		foreach ( array_keys( $meta ) as $key ) {
			if ( ! isset( $denied[ (string) $key ] ) ) {
				continue;
			}

			return new \WP_Error(
				'rest_field_forbidden',
				__( 'You do not have permission to write one of these fields.', 'saltus-framework' ),
				[
					'status' => 403,
					'field'  => $denied[ (string) $key ],
					'hint'   => sprintf(
						/* translators: 1: field path, 2: meta key */
						__( "The field '%1\$s' declares a 'permissions' rule your user does not satisfy. Remove '%2\$s' from the request, or grant your role the capability the field's write rule lists.", 'saltus-framework' ),
						$denied[ (string) $key ],
						(string) $key
					),
				]
			);
		}

		return null;
	}

	/**
	 * Field paths the caller may not read, for a post type.
	 *
	 * @return list<string>
	 */
	public function denied_read_paths( Modeler $modeler, string $post_type ): array {
		$fields = $this->normalized_fields( $modeler, $post_type );
		$denied = [];

		foreach ( $fields as $field ) {
			if ( $this->is_allowed( $field, $fields, self::OPERATION_READ ) ) {
				continue;
			}

			$path = (string) ( $field['path'] ?? '' );
			if ( $path !== '' ) {
				$denied[] = $path;
			}
		}

		return $denied;
	}

	/**
	 * Whether any field on this post type declares a permission rule.
	 *
	 * Lets a surface skip the whole resolution when nothing opted in, so the
	 * common case costs one config read rather than a walk per field.
	 */
	public function has_rules( Modeler $modeler, string $post_type ): bool {
		foreach ( $this->normalized_fields( $modeler, $post_type ) as $field ) {
			if ( $this->rule_for( $field, self::OPERATION_READ ) !== null
				|| $this->rule_for( $field, self::OPERATION_WRITE ) !== null ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalized fields for a post type, or an empty list when it has none.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function normalized_fields( Modeler $modeler, string $post_type ): array {
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

		return $normalized['fields'];
	}

	/**
	 * Resolve one operation against one field, honoring parent rules.
	 *
	 * @param array<string, mixed>       $field  Normalized field definition.
	 * @param list<array<string, mixed>> $fields All normalized fields.
	 */
	private function is_allowed( array $field, array $fields, string $operation ): bool {
		foreach ( $this->applicable_rules( $field, $fields, $operation ) as $capabilities ) {
			if ( ! $this->satisfies( $capabilities ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Every rule governing a field: its own, plus each ancestor's.
	 *
	 * A rule on `salary` governs `salary.amount` too. Collecting ancestors here
	 * rather than at the call site is what makes the inheritance impossible to
	 * forget on a new surface.
	 *
	 * @param array<string, mixed>       $field  Normalized field definition.
	 * @param list<array<string, mixed>> $fields All normalized fields.
	 * @return list<list<string>>
	 */
	private function applicable_rules( array $field, array $fields, string $operation ): array {
		$rules = [];

		$own = $this->rule_for( $field, $operation );
		if ( $own !== null ) {
			$rules[] = $own;
		}

		$path = (string) ( $field['path'] ?? '' );
		if ( $path === '' || $fields === [] ) {
			return $rules;
		}

		foreach ( $this->ancestor_paths( $path ) as $ancestor ) {
			foreach ( $fields as $candidate ) {
				if ( (string) ( $candidate['path'] ?? '' ) !== $ancestor ) {
					continue;
				}

				$rule = $this->rule_for( $candidate, $operation );
				if ( $rule !== null ) {
					$rules[] = $rule;
				}
			}
		}

		return $rules;
	}

	/**
	 * Dotted ancestor paths of a field path, nearest first.
	 *
	 * `a.b.c` yields `a.b` then `a`.
	 *
	 * @return list<string>
	 */
	private function ancestor_paths( string $path ): array {
		$segments  = explode( '.', $path );
		$ancestors = [];

		array_pop( $segments );
		while ( $segments !== [] ) {
			$ancestors[] = implode( '.', $segments );
			array_pop( $segments );
		}

		return $ancestors;
	}

	/**
	 * The capability list a field declares for one operation, or null.
	 *
	 * Returns null — meaning "no rule" — for anything that is not a usable list
	 * of capability strings. An unparseable rule must not silently become a
	 * denial of everything, nor an accidental grant: null preserves today's
	 * behavior and a malformed rule is a config bug for Phase 12's validator.
	 *
	 * @param array<string, mixed> $field Normalized field definition.
	 * @return list<string>|null
	 */
	private function rule_for( array $field, string $operation ): ?array {
		$raw = $field['raw'] ?? null;
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$permissions = $raw['permissions'] ?? null;
		if ( ! is_array( $permissions ) || ! array_key_exists( $operation, $permissions ) ) {
			return null;
		}

		$capabilities = $permissions[ $operation ];
		if ( is_string( $capabilities ) && $capabilities !== '' ) {
			return [ $capabilities ];
		}

		if ( ! is_array( $capabilities ) ) {
			return null;
		}

		$list = [];
		foreach ( $capabilities as $capability ) {
			if ( is_string( $capability ) && $capability !== '' ) {
				$list[] = $capability;
			}
		}

		return $list === [] ? null : $list;
	}

	/**
	 * Whether the caller holds any capability in a rule.
	 *
	 * Any rather than all: a rule lists the capabilities that grant access, so
	 * `['editor_cap', 'admin_cap']` means either is enough.
	 *
	 * @param list<string> $capabilities
	 */
	private function satisfies( array $capabilities ): bool {
		foreach ( $capabilities as $capability ) {
			if ( ( $this->capability_check )( $capability ) ) {
				return true;
			}
		}

		return false;
	}
}
