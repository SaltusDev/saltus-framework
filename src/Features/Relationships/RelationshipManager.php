<?php

namespace Saltus\WP\Framework\Features\Relationships;

/**
 * Reads and writes post relationships against their declared definitions.
 *
 * Every write is validated against both sides of the relationship before it
 * lands: a cardinality declared on one model is enforced even when the write
 * arrives through the reciprocal side, so a `has_one` cannot be given a second
 * target by approaching it from the far end.
 *
 * @api
 */
final class RelationshipManager {

	use PostIdListTrait;

	/** Upper bound on ids accepted by one sync call. */
	private const MAX_SYNC = 200;

	private RelationshipRegistry $registry;
	private RelationshipStore $store;
	private RelationshipPermissionPolicy $permissions;

	/**
	 * @param RelationshipPermissionPolicy|null $permissions Per-relationship access policy.
	 *                                                      Enforced here rather than per surface
	 *                                                      because every path — REST, MCP, WP-CLI,
	 *                                                      metabox, bulk actions — routes through
	 *                                                      this class, so a new caller inherits the
	 *                                                      gate instead of having to remember it.
	 */
	public function __construct(
		RelationshipRegistry $registry,
		?RelationshipStore $store = null,
		?RelationshipPermissionPolicy $permissions = null
	) {
		$this->registry    = $registry;
		$this->store       = $store ?? new RelationshipStore();
		$this->permissions = $permissions ?? new RelationshipPermissionPolicy();
	}

	/** The access policy, for surfaces that need to gate before they render. */
	public function permissions(): RelationshipPermissionPolicy {
		return $this->permissions;
	}

	/** Resolve one relationship declared by a post type. */
	public function get_definition( string $post_type, string $name ): ?RelationshipDefinition {
		return $this->registry->get( $post_type, $name );
	}

	/**
	 * Public description of every relationship on a post type.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function describe( string $post_type ): array {
		$described = [];
		// Filtered rather than refused: a caller listing relationships wants the ones
		// it can use, and a hard error would make one denied relationship hide every
		// other. A denied write still refuses loudly, because there a silent skip is
		// indistinguishable from a successful no-op.
		foreach ( $this->permissions->filter_readable( $this->registry->get_for_model( $post_type ) ) as $definition ) {
			$described[] = $definition->to_array();
		}

		return $described;
	}

	/**
	 * Related post ids for one post, in stored order.
	 *
	 * @return list<int>
	 */
	public function get_related_ids( int $post_id, string $post_type, string $name ): array {
		$definition = $this->registry->get( $post_type, $name );
		if ( ! $definition instanceof RelationshipDefinition || ! $this->permissions->can_read( $definition ) ) {
			return [];
		}

		return $this->stored_related_ids( $definition, $post_id );
	}

	/**
	 * Related ids straight from storage, with no capability check.
	 *
	 * Exists for cascade cleanup, which is not a user action. `delete_all_for_post()`
	 * runs on `before_delete_post` and must resolve the same targets regardless of who
	 * triggered the delete: routing it through the gated read would mean a user denied
	 * read silently strands every cascade target, leaving rows pointing at a post that
	 * no longer exists. Data integrity is not a permission question, and the deletion
	 * itself is already authorized by whatever let the post be deleted.
	 *
	 * @return list<int>
	 */
	private function stored_related_ids( RelationshipDefinition $definition, int $post_id ): array {
		$rows    = $this->store->get_by_posts( $definition->get_key(), $definition->own_column(), [ $post_id ] );
		$related = $definition->related_column();
		$ids     = [];
		foreach ( $rows as $row ) {
			$ids[] = (int) $row[ $related ];
		}

		return $ids;
	}

	/**
	 * Related rows for one post, including pivot payload and ordering.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function get_related( int $post_id, string $post_type, string $name ): array {
		$definition = $this->registry->get( $post_type, $name );
		if ( ! $definition instanceof RelationshipDefinition || ! $this->permissions->can_read( $definition ) ) {
			return [];
		}

		return $this->project( $definition, $this->store->get_by_posts( $definition->get_key(), $definition->own_column(), [ $post_id ] ) );
	}

	/**
	 * Related rows for many posts in a single query, keyed by post id.
	 *
	 * This is the eager-loading entry point: resolving a relationship for a
	 * whole result set costs one query here rather than one per post.
	 *
	 * Denial filters to an empty map rather than refusing, the same call the
	 * `describe()` above makes. A caller here is resolving one relationship across a
	 * page of posts it is already rendering, so a hard error would take down a list
	 * screen whose other relationships the caller may read. `RelationshipsController`
	 * refuses instead, because a single-relationship read has nothing else to hide.
	 *
	 * @param list<int> $post_ids Post ids to resolve.
	 * @return array<int, list<array<string, mixed>>>
	 */
	public function get_related_for_posts( array $post_ids, string $post_type, string $name ): array {
		$definition = $this->registry->get( $post_type, $name );
		if ( ! $definition instanceof RelationshipDefinition || ! $this->permissions->can_read( $definition ) ) {
			return [];
		}

		$post_ids = self::post_id_list( $post_ids );
		$grouped  = array_fill_keys( $post_ids, [] );
		if ( $post_ids === [] ) {
			return [];
		}

		$own = $definition->own_column();
		foreach ( $this->store->get_by_posts( $definition->get_key(), $own, $post_ids ) as $row ) {
			$owner = (int) $row[ $own ];
			if ( ! array_key_exists( $owner, $grouped ) ) {
				continue;
			}

			$grouped[ $owner ][] = $this->project_row( $definition, $row );
		}

		return $grouped;
	}

	/**
	 * Whether a post type declares a relationship the caller may read.
	 *
	 * Read-gated rather than a bare declaration count, because every caller uses this
	 * to decide whether to put a surface on screen — a metabox, a list column, the
	 * picker's assets. A post type whose every relationship is read-denied would
	 * otherwise register a metabox that renders no fields, which is the registration
	 * gate disagreeing with the render gate one layer down.
	 */
	public function has_readable_relationships( string $post_type ): bool {
		foreach ( $this->registry->get_for_model( $post_type ) as $definition ) {
			if ( $this->permissions->can_read( $definition ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Attach one related post.
	 *
	 * @param array<string, mixed> $pivot Optional pivot payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function attach( int $post_id, string $post_type, string $name, int $related_id, array $pivot = [], ?int $order = null ) {
		$definition = $this->registry->get( $post_type, $name );
		$invalid    = $this->validate_pair( $definition, $post_id, $post_type, $name, $related_id );
		if ( $invalid instanceof \WP_Error ) {
			return $invalid;
		}

		/** @var RelationshipDefinition $definition Validated above. */
		$denied = $this->permissions->reject_denied_write( $definition );
		if ( $denied instanceof \WP_Error ) {
			return $denied;
		}

		$capacity = $this->assert_capacity( $definition, $post_id, $related_id );
		if ( $capacity instanceof \WP_Error ) {
			return $capacity;
		}

		$row = $this->row_for( $definition, $post_id, $related_id );
		$id  = $this->store->upsert(
			array_merge(
				$row,
				[
					'pivot_data'  => $this->filter_pivot( $definition, $pivot ),
					'order_index' => $order ?? $this->next_order( $definition, $post_id ),
				]
			)
		);

		if ( $id <= 0 ) {
			return new \WP_Error(
				'saltus_relationship_write_failed',
				__( 'The relationship could not be stored.', 'saltus-framework' ),
				[
					'status' => 500,
					'hint'   => 'The relationships table may be unavailable. Check database write permissions.',
				]
			);
		}

		$this->emit( 'attached', $definition, $post_id, $related_id );

		return [
			'id'           => $id,
			'relationship' => $name,
			'post_id'      => $post_id,
			'related_id'   => $related_id,
			'attached'     => true,
		];
	}

	/**
	 * Detach one related post.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function detach( int $post_id, string $post_type, string $name, int $related_id ) {
		$definition = $this->registry->get( $post_type, $name );
		$invalid    = $this->validate_pair( $definition, $post_id, $post_type, $name, $related_id );
		if ( $invalid instanceof \WP_Error ) {
			return $invalid;
		}

		/** @var RelationshipDefinition $definition Validated above. */
		$denied = $this->permissions->reject_denied_write( $definition );
		if ( $denied instanceof \WP_Error ) {
			return $denied;
		}

		$row     = $this->row_for( $definition, $post_id, $related_id );
		$removed = $this->store->delete( (string) $row['relationship_key'], (int) $row['from_post_id'], (int) $row['to_post_id'] );

		if ( $removed > 0 ) {
			$this->emit( 'detached', $definition, $post_id, $related_id );
		}

		return [
			'relationship' => $name,
			'post_id'      => $post_id,
			'related_id'   => $related_id,
			'detached'     => $removed > 0,
		];
	}

	/**
	 * Replace a post's related set with exactly the given ids, in order.
	 *
	 * @param list<int> $related_ids Ids to keep, in the order given.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function sync( int $post_id, string $post_type, string $name, array $related_ids ) {
		$definition = $this->registry->get( $post_type, $name );
		if ( ! $definition instanceof RelationshipDefinition ) {
			return $this->unknown_relationship( $post_type, $name );
		}

		if ( $post_id <= 0 ) {
			return $this->invalid_post( $post_id );
		}

		// Before any clearing, like every other sync check: a refused call must leave
		// the stored set exactly as it was rather than half-applied.
		$denied = $this->permissions->reject_denied_write( $definition );
		if ( $denied instanceof \WP_Error ) {
			return $denied;
		}

		$ids = self::post_id_list( $related_ids );
		if ( count( $ids ) > self::MAX_SYNC ) {
			return new \WP_Error(
				'saltus_relationship_too_many',
				__( 'Too many related posts were given for one sync.', 'saltus-framework' ),
				[
					'status' => 400,
					'hint'   => 'Send at most ' . self::MAX_SYNC . ' ids per sync call.',
				]
			);
		}

		if ( ! $definition->allows_multiple() && count( $ids ) > 1 ) {
			return $this->cardinality_error( $definition );
		}

		foreach ( $ids as $related_id ) {
			$valid = $this->assert_related_post( $definition, $related_id );
			if ( $valid instanceof \WP_Error ) {
				return $valid;
			}
		}

		$failure = $this->apply_sync( $definition, $post_id, $ids );
		if ( $failure instanceof \WP_Error ) {
			return $failure;
		}

		$this->emit( 'synced', $definition, $post_id, 0 );

		return [
			'relationship' => $name,
			'post_id'      => $post_id,
			'related_ids'  => $ids,
			'synced'       => true,
		];
	}

	/**
	 * Clear and rewrite a post's related set inside one atomic boundary.
	 *
	 * The clear and every rewrite land together or not at all. Both sides of a
	 * relationship share one row, so a sequence that stopped halfway would leave
	 * the forward and reciprocal views disagreeing about the same pair — a state
	 * no later read can tell from a legitimate one.
	 *
	 * Capacity is still checked per id inside the boundary rather than up front,
	 * because the clear is what frees the capacity a replacement id needs.
	 *
	 * @param list<int> $ids Related ids to keep, in order.
	 * @return \WP_Error|null The error that rolled the sequence back, if any.
	 */
	private function apply_sync( RelationshipDefinition $definition, int $post_id, array $ids ): ?\WP_Error {
		/** @var \WP_Error|null $failure Set by the closure below through its reference. */
		$failure = null;

		$this->store->transact(
			function () use ( $definition, $post_id, $ids, &$failure ): bool {
				$this->store->delete_for_post( $definition->get_key(), $definition->own_column(), $post_id, $ids );

				$order = 0;
				foreach ( $ids as $related_id ) {
					$exceeded = $this->assert_capacity( $definition, $post_id, $related_id );
					if ( $exceeded instanceof \WP_Error ) {
						$failure = $exceeded;

						return false;
					}

					$this->store->upsert(
						array_merge(
							$this->row_for( $definition, $post_id, $related_id ),
							[ 'order_index' => $order++ ]
						)
					);
				}

				return true;
			}
		);

		return $failure;
	}

	/**
	 * Remove every relationship referencing a post, and cascade where declared.
	 *
	 * Returns the ids of posts deleted by a cascade so a caller can report them.
	 *
	 * @return list<int>
	 */
	public function delete_all_for_post( int $post_id, string $post_type ): array {
		if ( $post_id <= 0 ) {
			return [];
		}

		$cascaded = $this->collect_cascade_targets( $post_id, $post_type );
		$this->store->delete_all_for_post( $post_id );

		$deleted = [];
		foreach ( $cascaded as $target_id ) {
			// Clear the target's own rows first so a cascade cannot strand
			// relationships pointing at a post that no longer exists.
			$this->store->delete_all_for_post( $target_id );
			if ( function_exists( 'wp_delete_post' ) && wp_delete_post( $target_id, true ) ) {
				$deleted[] = $target_id;
			}
		}

		return $deleted;
	}

	/**
	 * Cascade dependents for a privacy erasure, naming what could not be read.
	 *
	 * Erasure discovery cannot go through `describe()` and `get_related_ids()`: both
	 * filter on the current user's read permission, so a cascade would skip dependents
	 * silently and the erasure would report success having missed them.
	 *
	 * It must not quietly ignore the rule either. `erase_others_personal_data` is
	 * authority over every relationship, so a read capability the erasing operator lacks
	 * is a contradictory configuration rather than a boundary to honour - and resolving
	 * it silently in either direction hides the mistake. So both halves come back: the
	 * dependents that were reachable, and the cascade relationships that were not.
	 * Reporting the second half is the caller's job; this method does not decide to
	 * bypass the policy.
	 *
	 * Only cascade-declaring relationships are considered, so a denied relationship that
	 * erasure would never have followed raises no warning.
	 *
	 * @return array{targets: list<int>, unreadable: list<string>}
	 */
	public function cascade_targets_for_erasure( int $post_id, string $post_type ): array {
		$targets    = [];
		$unreadable = [];

		foreach ( $this->registry->get_for_model( $post_type ) as $name => $definition ) {
			if ( ! $definition->cascades_delete() ) {
				continue;
			}

			if ( ! $this->permissions->can_read( $definition ) ) {
				$unreadable[] = (string) $name;
				continue;
			}

			foreach ( $this->stored_related_ids( $definition, $post_id ) as $related_id ) {
				$targets[] = $related_id;
			}
		}

		return [
			'targets'    => array_values( array_unique( $targets ) ),
			'unreadable' => $unreadable,
		];
	}

	/**
	 * Post ids that should be deleted along with a post.
	 *
	 * Only definitions that declare cascade on their owning side qualify, and a
	 * target still referenced by another post is kept.
	 *
	 * @return list<int>
	 */
	private function collect_cascade_targets( int $post_id, string $post_type ): array {
		$targets = [];
		foreach ( $this->registry->get_for_model( $post_type ) as $definition ) {
			if ( ! $definition->cascades_delete() ) {
				continue;
			}

			// Deliberately the ungated read: see stored_related_ids(). A cascade must
			// resolve the same targets no matter who deleted the post.
			foreach ( $this->stored_related_ids( $definition, $post_id ) as $related_id ) {
				if ( $this->is_only_referenced_by( $definition, $related_id, $post_id ) ) {
					$targets[] = $related_id;
				}
			}
		}

		return array_values( array_unique( $targets ) );
	}

	/** Whether a related post is referenced solely by the post being deleted. */
	private function is_only_referenced_by( RelationshipDefinition $definition, int $related_id, int $post_id ): bool {
		$rows  = $this->store->get_by_posts( $definition->get_key(), $definition->related_column(), [ $related_id ] );
		$own   = $definition->own_column();
		$other = 0;
		foreach ( $rows as $row ) {
			if ( (int) $row[ $own ] !== $post_id ) {
				++$other;
			}
		}

		return $other === 0;
	}

	/**
	 * Storage row coordinates for a pair, oriented to the declaring side.
	 *
	 * @return array<string, mixed>
	 */
	private function row_for( RelationshipDefinition $definition, int $post_id, int $related_id ): array {
		return [
			'relationship_key' => $definition->get_key(),
			'from_post_id'     => $definition->is_inverse() ? $related_id : $post_id,
			'to_post_id'       => $definition->is_inverse() ? $post_id : $related_id,
		];
	}

	/**
	 * Shape stored rows for output.
	 *
	 * @param list<array<string, mixed>> $rows Stored rows.
	 * @return list<array<string, mixed>>
	 */
	private function project( RelationshipDefinition $definition, array $rows ): array {
		$projected = [];
		foreach ( $rows as $row ) {
			$projected[] = $this->project_row( $definition, $row );
		}

		return $projected;
	}

	/**
	 * Shape one stored row for output.
	 *
	 * @param array<string, mixed> $row Stored row.
	 * @return array<string, mixed>
	 */
	private function project_row( RelationshipDefinition $definition, array $row ): array {
		$related_id = (int) $row[ $definition->related_column() ];

		return [
			'id'          => (int) ( $row['id'] ?? 0 ),
			'post_id'     => $related_id,
			'post_type'   => $definition->get_to(),
			'title'       => $this->post_title( $related_id ),
			'order_index' => (int) ( $row['order_index'] ?? 0 ),
			'pivot'       => is_array( $row['pivot_data'] ?? null ) ? $row['pivot_data'] : [],
		];
	}

	/** Resolve a post title without assuming WordPress is loaded. */
	private function post_title( int $post_id ): string {
		if ( ! function_exists( 'get_post' ) ) {
			return '';
		}

		$post = get_post( $post_id );

		return $post instanceof \WP_Post ? (string) $post->post_title : '';
	}

	/**
	 * Drop pivot values that the definition does not declare.
	 *
	 * Undeclared keys are discarded rather than stored so the pivot payload
	 * cannot become an unbounded, unvalidated bucket written through the API.
	 *
	 * @param array<string, mixed> $pivot Submitted pivot payload.
	 * @return array<string, mixed>
	 */
	private function filter_pivot( RelationshipDefinition $definition, array $pivot ): array {
		$allowed = $definition->get_pivot_fields();
		if ( $allowed === [] ) {
			return [];
		}

		return array_intersect_key( $pivot, $allowed );
	}

	/** Next ordering value for a post's related set. */
	private function next_order( RelationshipDefinition $definition, int $post_id ): int {
		$rows  = $this->store->get_by_posts( $definition->get_key(), $definition->own_column(), [ $post_id ] );
		$order = -1;
		foreach ( $rows as $row ) {
			$order = max( $order, (int) ( $row['order_index'] ?? 0 ) );
		}

		return $order + 1;
	}

	/**
	 * Validate a relationship name and both post ids.
	 *
	 * @return \WP_Error|null
	 */
	private function validate_pair( ?RelationshipDefinition $definition, int $post_id, string $post_type, string $name, int $related_id ): ?\WP_Error {
		if ( ! $definition instanceof RelationshipDefinition ) {
			return $this->unknown_relationship( $post_type, $name );
		}

		if ( $post_id <= 0 ) {
			return $this->invalid_post( $post_id );
		}

		if ( $post_id === $related_id ) {
			return new \WP_Error(
				'saltus_relationship_self_reference',
				__( 'A post cannot be related to itself.', 'saltus-framework' ),
				[
					'status' => 400,
					'hint'   => 'Choose a different related post.',
				]
			);
		}

		return $this->assert_related_post( $definition, $related_id );
	}

	/**
	 * Confirm the related post exists and is of the declared type.
	 *
	 * @return \WP_Error|null
	 */
	private function assert_related_post( RelationshipDefinition $definition, int $related_id ): ?\WP_Error {
		if ( $related_id <= 0 ) {
			return $this->invalid_post( $related_id );
		}

		if ( ! function_exists( 'get_post' ) ) {
			return null;
		}

		$post = get_post( $related_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'saltus_relationship_target_missing',
				__( 'The related post does not exist.', 'saltus-framework' ),
				[
					'status' => 404,
					'hint'   => 'Verify the related post id exists and is not trashed.',
				]
			);
		}

		$expected = $definition->get_to();
		$actual   = (string) $post->post_type;
		if ( $actual !== '' && $actual !== $expected ) {
			return new \WP_Error(
				'saltus_relationship_type_mismatch',
				__( 'The related post is not of the expected post type.', 'saltus-framework' ),
				[
					'status'   => 400,
					'expected' => $expected,
					'actual'   => $actual,
					'hint'     => 'This relationship only accepts posts of type "' . $expected . '".',
				]
			);
		}

		return null;
	}

	/**
	 * Enforce cardinality on both sides before a write.
	 *
	 * The far side is checked as well as the near one, so approaching a
	 * `has_one` through its reciprocal cannot exceed the declared limit.
	 *
	 * @return \WP_Error|null
	 */
	private function assert_capacity( RelationshipDefinition $definition, int $post_id, int $related_id ): ?\WP_Error {
		if ( $this->exceeds_capacity( $definition, $post_id, $related_id ) ) {
			return $this->cardinality_error( $definition );
		}

		$reciprocal = $this->reciprocal_of( $definition );
		if ( $reciprocal instanceof RelationshipDefinition
			&& $this->exceeds_capacity( $reciprocal, $related_id, $post_id ) ) {
			return $this->cardinality_error( $reciprocal );
		}

		return null;
	}

	/**
	 * Whether adding a pair would exceed a single-target cardinality.
	 *
	 * Re-attaching the same pair is an update, so it never exceeds capacity.
	 */
	private function exceeds_capacity( RelationshipDefinition $definition, int $anchor_id, int $other_id ): bool {
		if ( $definition->allows_multiple() ) {
			return false;
		}

		$related = $definition->related_column();
		foreach ( $this->store->get_by_posts( $definition->get_key(), $definition->own_column(), [ $anchor_id ] ) as $row ) {
			if ( (int) $row[ $related ] !== $other_id ) {
				return true;
			}
		}

		return false;
	}

	/** Resolve the definition on the far side of a relationship, if declared. */
	private function reciprocal_of( RelationshipDefinition $definition ): ?RelationshipDefinition {
		$name = $definition->get_reciprocal();
		if ( $name === null || $name === '' ) {
			return null;
		}

		return $this->registry->get( $definition->get_to(), $name );
	}

	private function cardinality_error( RelationshipDefinition $definition ): \WP_Error {
		return new \WP_Error(
			'saltus_relationship_cardinality',
			__( 'This relationship accepts only one related post.', 'saltus-framework' ),
			[
				'status'       => 409,
				'relationship' => $definition->get_name(),
				'type'         => $definition->get_type(),
				'hint'         => 'Detach the existing related post first, or use sync to replace it.',
			]
		);
	}

	private function unknown_relationship( string $post_type, string $name ): \WP_Error {
		return new \WP_Error(
			'saltus_relationship_not_found',
			__( 'The relationship is not defined for this post type.', 'saltus-framework' ),
			[
				'status'       => 404,
				'post_type'    => $post_type,
				'relationship' => $name,
				'hint'         => 'Call GET /relationships/' . $post_type . ' to list defined relationships.',
			]
		);
	}

	private function invalid_post( int $post_id ): \WP_Error {
		return new \WP_Error(
			'saltus_relationship_invalid_post',
			__( 'A valid post id is required.', 'saltus-framework' ),
			[
				'status' => 400,
				'given'  => $post_id,
				'hint'   => 'Pass a positive integer post id.',
			]
		);
	}

	/** Fire an action so sites can react to relationship changes. */
	private function emit( string $event, RelationshipDefinition $definition, int $post_id, int $related_id ): void {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}

		do_action( 'saltus/framework/relationships/' . $event, $definition->get_name(), $post_id, $related_id, $definition->get_key() );
	}
}
