<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\Rest\ModelRestPolicy;

/**
 * MCP tool attaching one related post through a named relationship.
 */
class AttachRelated extends RestTool {

	public function get_name(): string {
		return 'attach_related';
	}

	public function get_description(): string {
		return 'Attach a post to another post through one of its declared relationships';
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
				'description' => 'The post ID to attach',
				'required'    => true,
			],
			'pivot'        => [
				'type'                 => 'object',
				'description'          => 'Values for the pivot fields the relationship declares; undeclared keys are ignored',
				'additionalProperties' => true,
			],
			'order'        => [
				'type'        => 'number',
				'description' => 'Explicit position of the related post within the set',
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
		$body = [ 'related_id' => (int) ( $args['related_id'] ?? 0 ) ];
		if ( is_array( $args['pivot'] ?? null ) ) {
			$body['pivot'] = $args['pivot'];
		}
		if ( isset( $args['order'] ) ) {
			$body['order'] = (int) $args['order'];
		}

		return $this->request(
			'POST',
			$this->mcp_route(
				'/posts/' . (int) ( $args['post_id'] ?? 0 ) . '/relationships/' . rawurlencode( (string) ( $args['relationship'] ?? '' ) )
			),
			[],
			$body
		);
	}

	/** @param array<string, mixed> $args */
	public function has_permission( array $args ): bool {
		return $this->can_post( 'edit_post', $args );
	}
}
