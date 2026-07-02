<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\Rest\ModelRestPolicy;

/**
 * MCP tool to duplicate a WordPress post.
 */
class DuplicatePost extends RestTool {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'duplicate_post';
	}

	/**
	 * Get the tool description for the AI.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Duplicate a WordPress post, creating a copy with "(Copy)" appended to the title';
	}

	/**
	 * Get the JSON Schema for tool parameters.
	 *
	 * @return array<string, mixed>
	 */
	public function get_parameters(): array {
		return [
			'post_id' => [
				'type'        => 'number',
				'description' => 'The ID of the post to duplicate',
				'required'    => true,
			],
		];
	}

	/**
	 * Get the capability requirement for this tool.
	 *
	 * @return RestCapabilityRequirement|null
	 */
	public function get_rest_capability(): ?RestCapabilityRequirement {
		return new RestCapabilityRequirement( ModelRestPolicy::CAPABILITY_DUPLICATE, 'post_type' );
	}

	/**
	 * Build the WP_REST_Request for duplicating a post.
	 *
	 * @param array<string, mixed> $args
	 * @return \WP_REST_Request|null
	 */
	public function build_rest_request( array $args ): ?\WP_REST_Request {
		return $this->request( 'POST', '/saltus-framework/v1/duplicate/' . (int) ( $args['post_id'] ?? 0 ) );
	}
}
