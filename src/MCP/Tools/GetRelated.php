<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\Rest\ModelRestPolicy;

/**
 * MCP tool reading the posts related to one post through a named relationship.
 */
class GetRelated extends RestTool {

	public function get_name(): string {
		return 'get_related';
	}

	public function get_description(): string {
		return 'Get the posts related to a specific post through one of its declared relationships';
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
			'GET',
			$this->mcp_route(
				'/posts/' . (int) ( $args['post_id'] ?? 0 ) . '/relationships/' . rawurlencode( (string) ( $args['relationship'] ?? '' ) )
			)
		);
	}

	public function is_cacheable(): bool {
		return true;
	}

	/** @param array<string, mixed> $args */
	public function has_permission( array $args ): bool {
		return $this->can_post( 'read_post', $args );
	}
}
