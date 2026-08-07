<?php

namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\Rest\ModelRestPolicy;

/** MCP tool exposing normalized model AI governance context. */
final class GetContext extends RestTool {

	public function get_name(): string {
		return 'get_context';
	}

	public function get_description(): string {
		return 'Get the AI governance context for a Saltus model';
	}

	/** @return array<string, mixed> */
	public function get_parameters(): array {
		return [
			'post_type' => [
				'type'        => 'string',
				'required'    => true,
				'description' => 'The model slug',
			],
		];
	}

	public function get_rest_capability(): RestCapabilityRequirement {
		return new RestCapabilityRequirement( ModelRestPolicy::CAPABILITY_MODELS, 'post_type' );
	}

	public function build_rest_request( array $args ): ?\WP_REST_Request {
		return $this->request( 'GET', $this->mcp_route( '/context/' . rawurlencode( (string) ( $args['post_type'] ?? '' ) ) ) );
	}

	public function is_cacheable(): bool {
		return true;
	}

	public function has_permission( array $args ): bool {
		return current_user_can( 'edit_posts' );
	}
}
