<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\Rest\ModelRestPolicy;

/** MCP tool for discovering model-driven blocks. */
final class ListBlockModels extends RestTool {
	public function get_name(): string {
		return 'list_block_models';
	}

	public function get_description(): string {
		return 'List Saltus post type models with their registered list and single blocks';
	}

	/** @return array<string, mixed> */
	public function get_parameters(): array {
		return [];
	}

	public function get_rest_capability(): RestCapabilityRequirement {
		return new RestCapabilityRequirement( ModelRestPolicy::CAPABILITY_BLOCKS, 'post_type' );
	}

	public function build_rest_request( array $args ): ?\WP_REST_Request {
		return $this->request( 'GET', $this->mcp_route( '/blocks' ) );
	}

	public function is_cacheable(): bool {
		return true;
	}
}
