<?php

namespace Saltus\WP\Framework\MCP\Tools\Workflow;

use Saltus\WP\Framework\MCP\Tools\RestTool;

/**
 * MCP tool reporting a post's workflow state and its legal next moves.
 *
 * An agent should ask this before attempting a transition: the answer already
 * excludes moves the current user cannot make.
 */
class GetWorkflowStates extends RestTool {

	public function get_name(): string {
		return 'get_workflow_states';
	}

	public function get_description(): string {
		return "Get a post's current workflow state and the transitions available from it";
	}

	/** @return array<string, mixed> */
	public function get_parameters(): array {
		return [
			'model'   => [
				'type'        => 'string',
				'description' => 'The model (post type) of the post',
			],
			'post_id' => [
				'type'        => 'number',
				'description' => 'The post whose workflow state to read',
			],
		];
	}

	/** @param array<string, mixed> $args */
	public function build_rest_request( array $args ): ?\WP_REST_Request {
		$post_id = (int) ( $args['post_id'] ?? 0 );

		return $this->request(
			'GET',
			$this->mcp_route( '/workflow/' . $post_id ),
			$this->only_args( $args, [ 'model' ] )
		);
	}

	public function is_cacheable(): bool {
		return true;
	}

	public function cache_ttl(): int {
		// Workflow state changes are the point of the feature, so cache briefly.
		return 30;
	}
}
