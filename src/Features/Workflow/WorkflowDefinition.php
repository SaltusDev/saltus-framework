<?php

namespace Saltus\WP\Framework\Features\Workflow;

/**
 * One model's complete workflow: its states and the legal moves between them.
 *
 * @api
 */
final class WorkflowDefinition {

	/**
	 * @param string                          $model       Model this workflow belongs to.
	 * @param array<string, WorkflowState>      $states      Keyed by slug, in declaration order.
	 * @param array<string, WorkflowTransition> $transitions Keyed by transition name.
	 */
	public function __construct(
		public readonly string $model,
		public readonly array $states,
		public readonly array $transitions
	) {}

	/** The first declared state, which new content starts in. */
	public function initial_state(): string {
		$slugs = array_keys( $this->states );

		return (string) ( $slugs[0] ?? '' );
	}

	public function has_state( string $slug ): bool {
		return isset( $this->states[ $slug ] );
	}

	public function state( string $slug ): ?WorkflowState {
		return $this->states[ $slug ] ?? null;
	}

	public function transition( string $name ): ?WorkflowTransition {
		return $this->transitions[ $name ] ?? null;
	}

	/**
	 * Transitions legal from a given state.
	 *
	 * This is what the admin renders as buttons: only moves that are actually
	 * available, rather than the whole transition list greyed out.
	 *
	 * @return array<string, WorkflowTransition>
	 */
	public function transitions_from( string $state ): array {
		return array_filter(
			$this->transitions,
			static fn( WorkflowTransition $transition ): bool => $transition->allows_from( $state )
		);
	}

	/**
	 * Slugs of states whose content is publicly visible.
	 *
	 * @return list<string>
	 */
	public function public_states(): array {
		$public = [];
		foreach ( $this->states as $slug => $state ) {
			if ( $state->is_public ) {
				$public[] = (string) $slug;
			}
		}

		return $public;
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return [
			'model'         => $this->model,
			'initial_state' => $this->initial_state(),
			'states'        => array_map( static fn( WorkflowState $state ): array => $state->to_array(), $this->states ),
			'transitions'   => array_map( static fn( WorkflowTransition $transition ): array => $transition->to_array(), $this->transitions ),
		];
	}
}
