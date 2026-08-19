<?php

namespace Saltus\WP\Framework\Features\Relationships;

/**
 * Resolves per-relationship read and write access for authenticated callers.
 *
 * The gap this closes is the mirror of the one `FieldPermissionPolicy` closed for
 * meta fields, and it was hiding behind a key that looked like it had already been
 * closed. A relationship could declare `capability`, and three admin surfaces
 * honoured it — `RelationshipMetabox`, `RelationshipColumn`, `RelationshipBulkActions`
 * — while `RelationshipsController`, the five MCP tools, `RelationshipCommand`, and
 * `RelationshipManager` itself never read it. A relationship hidden from an editor in
 * the post editor was fully writable by that same editor over REST. `capability` was
 * an admin-UI affordance wearing a security name.
 *
 * Rather than change what `capability` means — sites use it today and enforcing it
 * retroactively would lock out callers that work — this adds `capabilities`, with a
 * `read`/`write` split, enforced on **every** surface including the manager. The old
 * key keeps its exact UI-only behaviour and the new key is the security boundary.
 *
 * Four rules govern resolution:
 *
 * 1. **No rule means no change.** A relationship without `capabilities` stays as
 *    accessible as it is today, matching `FieldPermissionPolicy`. Adding this feature
 *    must not change what an existing site exposes, which is why it does not deny by
 *    omission.
 * 2. **A rule binds both sides.** A reciprocal shares one storage key with its
 *    declaring side, so a rule on `movie.actors` governs `person.acted_in` too.
 *    Without this, denying a write on the declared side is bypassed by writing
 *    through the reciprocal — the same hole at the far end that 10A's two-directional
 *    cardinality check closed. Inheritance happens in `RelationshipRegistry` when the
 *    reciprocal is synthesized, so it cannot be forgotten at a call site.
 * 3. **Read and write are independent.** Denying write does not imply granting read,
 *    and granting write does not imply read. A caller who may reorder but not list is
 *    unusual and not this policy's business to forbid.
 * 4. **A malformed rule is no rule.** An unparseable `capabilities` value resolves to
 *    null rather than denying everything or granting everything, so a config typo
 *    cannot lock a site out of its own data. It is reported instead by
 *    `RelationshipConfigRules` at config time, where it is actionable.
 *
 * Unlike meta fields, WP-CLI is gated too. `MetaCommand` is not gated by
 * `FieldPermissionPolicy`, treating the CLI as a trusted operator surface; that
 * exemption is not carried here, because a policy with a documented bypass is a
 * suggestion. The practical cost is that `wp saltus relationship` against a
 * relationship declaring `capabilities` needs `--user=`, since WP-CLI bootstraps with
 * no current user and `current_user_can()` is therefore false. Only relationships that
 * opt in are affected, so no existing command breaks.
 *
 * @api
 */
final class RelationshipPermissionPolicy {

	/** Read access: the relationship may be listed and its rows returned. */
	public const OPERATION_READ = 'read';

	/** Write access: the relationship's rows may be changed. */
	public const OPERATION_WRITE = 'write';

	/** Operations a `capabilities` declaration may name. */
	public const OPERATIONS = [ self::OPERATION_READ, self::OPERATION_WRITE ];

	/** @var callable(string): bool */
	private $capability_check;

	/**
	 * @param callable(string): bool|null $capability_check Capability resolver, defaulting to
	 *                                                     `current_user_can`. Injectable so a
	 *                                                     surface can resolve for a specific user
	 *                                                     rather than the current one, and so tests
	 *                                                     need no WordPress.
	 */
	public function __construct( ?callable $capability_check = null ) {
		$this->capability_check = $capability_check ?? static function ( string $capability ): bool {
			// Absent WordPress there is nothing to check against. Allowing keeps the
			// policy inert outside a request rather than denying every relationship,
			// matching FieldPermissionPolicy so the two cannot diverge on this.
			return ! function_exists( 'current_user_can' ) || current_user_can( $capability );
		};
	}

	/** Whether the caller may read one relationship. */
	public function can_read( RelationshipDefinition $definition ): bool {
		return $this->is_allowed( $definition, self::OPERATION_READ );
	}

	/** Whether the caller may write one relationship. */
	public function can_write( RelationshipDefinition $definition ): bool {
		return $this->is_allowed( $definition, self::OPERATION_WRITE );
	}

	/**
	 * Narrow a set of definitions to those the caller may read.
	 *
	 * Keys are preserved, because callers index definitions by relationship name.
	 *
	 * @param array<string, RelationshipDefinition> $definitions
	 * @return array<string, RelationshipDefinition>
	 */
	public function filter_readable( array $definitions ): array {
		$allowed = [];
		foreach ( $definitions as $name => $definition ) {
			if ( $this->can_read( $definition ) ) {
				$allowed[ $name ] = $definition;
			}
		}

		return $allowed;
	}

	/**
	 * Whether a relationship declares any rule at all.
	 *
	 * Lets a surface skip resolution entirely when nothing opted in.
	 */
	public function has_rules( RelationshipDefinition $definition ): bool {
		foreach ( self::OPERATIONS as $operation ) {
			if ( $definition->get_capabilities_for( $operation ) !== null ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Refuse a denied read, or null when the caller may proceed.
	 *
	 * Shared so REST, MCP, and WP-CLI return one error code and hint for the same
	 * denial rather than three near-identical messages.
	 */
	public function reject_denied_read( RelationshipDefinition $definition ): ?\WP_Error {
		return $this->can_read( $definition )
			? null
			: $this->denial( $definition, self::OPERATION_READ );
	}

	/** Refuse a denied write, or null when the caller may proceed. */
	public function reject_denied_write( RelationshipDefinition $definition ): ?\WP_Error {
		return $this->can_write( $definition )
			? null
			: $this->denial( $definition, self::OPERATION_WRITE );
	}

	/**
	 * The refusal for one denied operation.
	 *
	 * The hint names the relationship and the operation rather than the capability
	 * list: telling an unauthorized caller which capability would grant access
	 * describes the site's permission structure to someone who just failed a check.
	 * An author debugging their own config has the config in front of them.
	 */
	private function denial( RelationshipDefinition $definition, string $operation ): \WP_Error {
		return new \WP_Error(
			'rest_relationship_forbidden',
			$operation === self::OPERATION_READ
				? __( 'You do not have permission to view this relationship.', 'saltus-framework' )
				: __( 'You do not have permission to change this relationship.', 'saltus-framework' ),
			[
				'status'       => 403,
				'relationship' => $definition->get_name(),
				'operation'    => $operation,
				'hint'         => sprintf(
					/* translators: 1: relationship name, 2: operation, either read or write */
					__( "The relationship '%1\$s' declares a 'capabilities' rule for '%2\$s' that your user does not satisfy. Grant your role one of the capabilities that rule lists, or run as a user who has one.", 'saltus-framework' ),
					$definition->get_name(),
					$operation
				),
			]
		);
	}

	/**
	 * Resolve one operation against one relationship.
	 *
	 * The verdict is filterable so an addon can participate. Consuming plugins vendor
	 * the framework through strauss with `class_alias: false`, so a sibling plugin
	 * cannot import `RelationshipDefinition` or this class — the framework lives at a
	 * different FQCN in every consumer. Both filters therefore pass **primitives
	 * only**: name, model, operation, and a capability list. That is what makes them
	 * usable from outside, and it is why the definition object is not passed.
	 */
	private function is_allowed( RelationshipDefinition $definition, string $operation ): bool {
		$capabilities = $definition->get_capabilities_for( $operation );

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the capability list governing one relationship operation.
			 *
			 * Returning null clears the rule, making the operation unrestricted.
			 *
			 * @param list<string>|null $capabilities Capabilities that grant access.
			 * @param string            $name         Relationship name.
			 * @param string            $model        Model the relationship is read from.
			 * @param string            $operation    Either `read` or `write`.
			 */
			$filtered = apply_filters(
				'saltus/framework/relationships/capabilities',
				$capabilities,
				$definition->get_name(),
				$definition->get_from(),
				$operation
			);

			$capabilities = $this->normalize_capabilities( $filtered );
		}

		// No rule means no change: the relationship is exactly as accessible as it
		// was before this policy existed.
		$allowed = $capabilities === null ? true : $this->satisfies( $capabilities );

		if ( ! function_exists( 'apply_filters' ) ) {
			return $allowed;
		}

		/**
		 * Filters the resolved verdict for one relationship operation.
		 *
		 * Runs after the capability list is resolved, so an addon can allow or deny
		 * on grounds the capability system cannot express — ownership, a workflow
		 * state, a per-post rule.
		 *
		 * @param bool         $allowed      Whether the operation is permitted.
		 * @param string       $name         Relationship name.
		 * @param string       $model        Model the relationship is read from.
		 * @param string       $operation    Either `read` or `write`.
		 * @param list<string> $capabilities Capability list that produced the verdict.
		 */
		return (bool) apply_filters(
			'saltus/framework/relationships/can',
			$allowed,
			$definition->get_name(),
			$definition->get_from(),
			$operation,
			$capabilities ?? []
		);
	}

	/**
	 * Reduce a filtered value to a usable capability list, or null for "no rule".
	 *
	 * A filter is third-party code and may return anything. Narrowing here rather
	 * than trusting the return keeps a careless addon from turning a rule into a
	 * denial of everything.
	 *
	 * @param mixed $capabilities
	 * @return list<string>|null
	 */
	private function normalize_capabilities( $capabilities ): ?array {
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
	 * `['editor_cap', 'admin_cap']` means either is enough. Matches
	 * `FieldPermissionPolicy::satisfies()`.
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
