<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\Rest\ModelRestPolicy;

/**
 * MCP tool detaching one related post from a named relationship.
 */
class DetachRelated extends RestTool {

	public function get_name(): string {
		return 'detach_related';
	}

	public function get_description(): string {
		return 'Detach a related post from one of a post\'s declared relationships';
	}

	/** @return array<string, mixed> */
	public function get_parameters(): array {
		return [
			'post_id'      => [
				'type'        => 'number',
				'description' => 'The post ID that owns the relationship',
				'required'    => true,
			],
			'relationship' => [
				'type'        => 'string',
				'description' => 'The relationship name as declared in the model config',
				'required'    => true,
			],
			'related_id'   => [
				'type'        => 'number',
				'description' => 'The post ID to detach',
				'required'    => true,
			],
		];
	}

	public function get_rest_capability(): ?RestCapabilityRequirement {
		return new RestCapabilityRequirement( ModelRestPolicy::CAPABILITY_RELATIONSHIPS, 'post_type' );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return \WP_REST_Request|null
	 */
	public function build_rest_request( array $args ): ?\WP_REST_Request {
		return $this->request(
			'DELETE',
			$this->mcp_route(
				'/posts/' . (int) ( $args['post_id'] ?? 0 )
				. '/relationships/' . rawurlencode( (string) ( $args['relationship'] ?? '' ) )
				. '/' . (int) ( $args['related_id'] ?? 0 )
			)
		);
	}

	/** @param array<string, mixed> $args */
	public function has_permission( array $args ): bool {
		return $this->can_post( 'edit_post', $args );
	}
}
