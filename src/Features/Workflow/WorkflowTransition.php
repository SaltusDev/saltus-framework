<?php

namespace Saltus\WP\Framework\Features\Workflow;

/**
 * One legal move between workflow states.
 *
 * Immutable. Built and validated by {@see WorkflowRegistry}.
 *
 * @api
 */
final class WorkflowTransition {

	/**
	 * @param string       $name        Transition key, e.g. 'approve'.
	 * @param list<string> $from        States this may be applied from.
	 * @param string       $to          Resulting state.
	 * @param string       $capability  Capability required to apply it.
	 * @param string       $label       Button label in the admin.
	 * @param bool         $action_hook Whether entering `to` fires a named action.
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $from,
		public readonly string $to,
		public readonly string $capability = 'edit_posts',
		public readonly string $label = '',
		public readonly bool $action_hook = false
	) {}

	/** Whether this transition may be applied from a given state. */
	public function allows_from( string $state ): bool {
		return in_array( $state, $this->from, true );
	}

	/** The label, falling back to the transition name. */
	public function label(): string {
		return $this->label !== '' ? $this->label : $this->name;
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return [
			'name'        => $this->name,
			'from'        => $this->from,
			'to'          => $this->to,
			'capability'  => $this->capability,
			'label'       => $this->label(),
			'action_hook' => $this->action_hook,
		];
	}
}
