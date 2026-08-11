<?php

namespace Saltus\WP\Framework\Features\Workflow;

/**
 * One state in a model's workflow.
 *
 * Immutable. Built and validated by {@see WorkflowRegistry}.
 *
 * @api
 */
final class WorkflowState {

	/**
	 * @param string $slug         Storage value, e.g. 'in_legal_review'.
	 * @param string $label        Human label shown in the admin.
	 * @param bool   $is_public    Whether content in this state is publicly visible.
	 * @param string $notification Optional address notified on entry.
	 */
	public function __construct(
		public readonly string $slug,
		public readonly string $label,
		public readonly bool $is_public = false,
		public readonly string $notification = ''
	) {}

	public function notifies(): bool {
		return $this->notification !== '';
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return [
			'slug'         => $this->slug,
			'label'        => $this->label,
			'public'       => $this->is_public,
			'notification' => $this->notification,
		];
	}
}
