<?php

namespace Saltus\WP\Framework\Features\Workflow;

/**
 * Applies workflow transitions to posts.
 *
 * Custom states live in `_saltus_workflow_state` meta rather than `post_status`,
 * so WordPress core keeps working: a post in a custom state still has a real
 * core status underneath, and code that never heard of workflows still reads
 * something sensible.
 *
 * @api
 */
final class StateTransitioner {

	public const META_KEY     = '_saltus_workflow_state';
	public const META_HISTORY = '_saltus_workflow_history';

	/** Core statuses a public workflow state maps onto. */
	private const PUBLIC_STATUS  = 'publish';
	private const PRIVATE_STATUS = 'draft';

	private WorkflowRegistry $registry;

	public function __construct( WorkflowRegistry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * The current workflow state of a post.
	 *
	 * Falls back to the workflow's initial state when the post has never been
	 * transitioned, so callers never have to special-case "not yet started".
	 *
	 * @throws InvalidWorkflow If the model has no workflow.
	 */
	public function current_state( string $model, int $post_id ): string {
		$definition = $this->registry->require( $model );

		$stored = '';
		if ( function_exists( 'get_post_meta' ) ) {
			$stored = (string) get_post_meta( $post_id, self::META_KEY, true );
		}

		if ( $stored !== '' && $definition->has_state( $stored ) ) {
			return $stored;
		}

		return $definition->initial_state();
	}

	/**
	 * Transitions available to the current user from a post's current state.
	 *
	 * @return array<string, WorkflowTransition>
	 *
	 * @throws InvalidWorkflow If the model has no workflow.
	 */
	public function available_transitions( string $model, int $post_id ): array {
		$definition = $this->registry->require( $model );

		return array_filter(
			$definition->transitions_from( $this->current_state( $model, $post_id ) ),
			fn( WorkflowTransition $transition ): bool => $this->can_apply( $transition, $post_id )
		);
	}

	/**
	 * Whether a named transition can be applied to a post right now.
	 *
	 * @throws InvalidWorkflow If the model or transition is unknown.
	 */
	public function can_transition( string $model, int $post_id, string $transition_name ): bool {
		$definition = $this->registry->require( $model );
		$transition = $definition->transition( $transition_name );
		if ( ! $transition instanceof WorkflowTransition ) {
			return false;
		}

		return $transition->allows_from( $this->current_state( $model, $post_id ) )
			&& $this->can_apply( $transition, $post_id );
	}

	/**
	 * Apply a transition.
	 *
	 * @return array<string, mixed> The resulting state, or an `error` key explaining the refusal.
	 *
	 * @throws InvalidWorkflow If the model has no workflow.
	 */
	public function transition( string $model, int $post_id, string $transition_name, string $note = '' ): array {
		$definition = $this->registry->require( $model );
		$transition = $definition->transition( $transition_name );

		if ( ! $transition instanceof WorkflowTransition ) {
			return $this->refusal(
				sprintf( 'Model "%s" has no transition named "%s".', $model, $transition_name )
			);
		}

		$from = $this->current_state( $model, $post_id );

		if ( ! $transition->allows_from( $from ) ) {
			return $this->refusal(
				sprintf(
					'Transition "%s" cannot be applied from state "%s"; it allows: %s.',
					$transition_name,
					$from,
					implode( ', ', $transition->from )
				)
			);
		}

		if ( ! $this->can_apply( $transition, $post_id ) ) {
			return $this->refusal(
				sprintf( 'You do not have permission to apply transition "%s".', $transition_name )
			);
		}

		$this->persist( $model, $post_id, $definition, $transition, $from, $note );

		return [
			'transitioned' => true,
			'post_id'      => $post_id,
			'model'        => $model,
			'transition'   => $transition_name,
			'from'         => $from,
			'to'           => $transition->to,
		];
	}

	/**
	 * Set a post's state directly, bypassing transition rules.
	 *
	 * For migrations and seeding, not for editorial flow — it skips both the
	 * legality check and the capability check, so it is deliberately not part
	 * of the REST surface.
	 *
	 * @throws InvalidWorkflow If the model has no workflow or the state is unknown.
	 */
	public function force_state( string $model, int $post_id, string $state ): bool {
		$definition = $this->registry->require( $model );
		if ( ! $definition->has_state( $state ) ) {
			return false;
		}

		if ( function_exists( 'update_post_meta' ) ) {
			update_post_meta( $post_id, self::META_KEY, $state );
		}
		$this->sync_post_status( $post_id, $definition, $state );

		return true;
	}

	/**
	 * Write the new state, record history, sync core status, fire hooks.
	 */
	private function persist(
		string $model,
		int $post_id,
		WorkflowDefinition $definition,
		WorkflowTransition $transition,
		string $from,
		string $note
	): void {
		if ( function_exists( 'update_post_meta' ) ) {
			update_post_meta( $post_id, self::META_KEY, $transition->to );
			update_post_meta( $post_id, self::META_HISTORY, $this->appended_history( $post_id, $transition, $from, $note ) );
		}

		$this->sync_post_status( $post_id, $definition, $transition->to );

		if ( ! function_exists( 'do_action' ) ) {
			return;
		}

		$payload = [
			'post_id'    => $post_id,
			'model'      => $model,
			'transition' => $transition->name,
			'from'       => $from,
			'to'         => $transition->to,
			'note'       => $note,
		];

		// A general hook every state change fires, so listeners need not
		// enumerate transitions.
		do_action( 'saltus/framework/workflow/transitioned', $payload );

		// Plus the per-state hook the config opted into.
		if ( $transition->action_hook ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The hook is prefixed with the framework's own namespace.
			do_action( sprintf( 'saltus/workflow/%s/%s', $model, $transition->to ), $payload );
		}

		$state = $definition->state( $transition->to );
		if ( $state instanceof WorkflowState && $state->notifies() ) {
			do_action( 'saltus/framework/workflow/notify', $state->notification, $payload );
		}
	}

	/**
	 * Keep `post_status` consistent with the workflow state.
	 *
	 * Without this a post could sit in a non-public workflow state while still
	 * being publicly readable, which is the one failure mode a compliance
	 * workflow cannot have.
	 */
	private function sync_post_status( int $post_id, WorkflowDefinition $definition, string $state_slug ): void {
		$state = $definition->state( $state_slug );
		if ( ! $state instanceof WorkflowState || ! function_exists( 'wp_update_post' ) ) {
			return;
		}

		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => $state->is_public ? self::PUBLIC_STATUS : self::PRIVATE_STATUS,
			]
		);
	}

	/**
	 * The post's transition history with this move appended.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function appended_history( int $post_id, WorkflowTransition $transition, string $from, string $note ): array {
		$history = [];
		if ( function_exists( 'get_post_meta' ) ) {
			$stored  = get_post_meta( $post_id, self::META_HISTORY, true );
			$history = is_array( $stored ) ? array_values( array_filter( $stored, 'is_array' ) ) : [];
		}

		$history[] = [
			'transition' => $transition->name,
			'from'       => $from,
			'to'         => $transition->to,
			'note'       => $note,
			'user_id'    => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'at'         => gmdate( 'Y-m-d H:i:s' ),
		];

		return $history;
	}

	/**
	 * A post's transition history, oldest first.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function history( int $post_id ): array {
		if ( ! function_exists( 'get_post_meta' ) ) {
			return [];
		}

		$stored = get_post_meta( $post_id, self::META_HISTORY, true );

		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_array' ) ) : [];
	}

	private function can_apply( WorkflowTransition $transition, int $post_id ): bool {
		if ( ! function_exists( 'current_user_can' ) ) {
			return true;
		}

		return current_user_can( $transition->capability, $post_id );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function refusal( string $message ): array {
		return [
			'transitioned' => false,
			'error'        => $message,
		];
	}
}
