<?php

namespace Saltus\WP\Framework\MCP\Tools\Workflow;

use Saltus\WP\Framework\MCP\Tools\RestCapabilityRequirement;
use Saltus\WP\Framework\MCP\Tools\RestTool;

/**
 * MCP tool moving a post to a new workflow state.
 *
 * Gated on editing the post; the transition's own declared capability is
 * enforced underneath, so an agent cannot approve what its user could not.
 */
class TransitionWorkflowState extends RestTool {

	public function get_name(): string {
		return 'transition_workflow_state';
	}

	public function get_description(): string {
		return 'Apply a workflow transition to a post, e.g. submit for review or approve';
	}

	/** @return array<string, mixed> */
	public function get_parameters(): array {
		return [
			'model'      => [
				'type'        => 'string',
				'description' => 'The model (post type) of the post',
			],
			'post_id'    => [
				'type'        => 'number',
				'description' => 'The post to transition',
			],
			'transition' => [
				'type'        => 'string',
				'description' => 'The transition to apply, e.g. "approve". Use get_workflow_states to see what is available',
			],
			'note'       => [
				'type'        => 'string',
				'description' => 'Optional note recorded in the transition history',
			],
		];
	}

	/** @param array<string, mixed> $args */
	public function build_rest_request( array $args ): ?\WP_REST_Request {
		$post_id = (int) ( $args['post_id'] ?? 0 );

		return $this->request(
			'POST',
			$this->mcp_route( '/workflow/' . $post_id . '/transition' ),
			[],
			$this->only_args( $args, [ 'model', 'transition', 'note' ] )
		);
	}

	public function get_rest_capability(): ?RestCapabilityRequirement {
		return new RestCapabilityRequirement( 'edit_posts', 'post_type' );
	}

	/** @param array<string, mixed> $args */
	public function has_permission( array $args ): bool {
		return $this->can_post( 'edit_post', $args );
	}
}
