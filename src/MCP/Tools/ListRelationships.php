<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\Rest\ModelRestPolicy;

/**
 * MCP tool listing the relationships a post type declares.
 */
class ListRelationships extends RestTool {

	public function get_name(): string {
		return 'list_relationships';
	}

	public function get_description(): string {
		return 'List the relationships declared by a Saltus post type, including their target model, cardinality, and pivot fields';
	}

	/** @return array<string, mixed> */
	public function get_parameters(): array {
		return [
			'post_type' => [
				'type'        => 'string',
				'description' => 'The post type slug to list relationship definitions for',
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
		return $this->request( 'GET', $this->mcp_route( '/relationships/' . rawurlencode( (string) ( $args['post_type'] ?? '' ) ) ) );
	}

	public function is_cacheable(): bool {
		return true;
	}

	/** @param array<string, mixed> $args */
	public function has_permission( array $args ): bool {
		return current_user_can( $this->post_type_capability( (string) ( $args['post_type'] ?? '' ), 'edit_posts', 'edit_posts' ) );
	}
}
