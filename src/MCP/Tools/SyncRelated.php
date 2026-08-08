<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\Rest\ModelRestPolicy;

/**
 * MCP tool replacing a post's related set through a named relationship.
 */
class SyncRelated extends RestTool {

	public function get_name(): string {
		return 'sync_related';
	}

	public function get_description(): string {
		return 'Replace the complete set of posts related to a post through one of its declared relationships, in the order given';
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
			'related_ids'  => [
				'type'        => 'array',
				'description' => 'The complete ordered set of related post IDs; posts not listed are detached',
				'items'       => [ 'type' => 'number' ],
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
		$related_ids = is_array( $args['related_ids'] ?? null ) ? array_map( 'intval', $args['related_ids'] ) : [];

		return $this->request(
			'PUT',
			$this->mcp_route(
				'/posts/' . (int) ( $args['post_id'] ?? 0 ) . '/relationships/' . rawurlencode( (string) ( $args['relationship'] ?? '' ) )
			),
			[],
			[ 'related_ids' => array_values( $related_ids ) ]
		);
	}

	/** @param array<string, mixed> $args */
	public function has_permission( array $args ): bool {
		return $this->can_post( 'edit_post', $args );
	}
}
