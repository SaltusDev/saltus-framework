<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\Rest\ModelRestPolicy;

/**
 * MCP tool to list all registered Custom Post Types and Taxonomies.
 */
class ListModels extends RestTool {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'list_models';
	}

	/**
	 * Get the tool description for the AI.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'List all registered Custom Post Types and Taxonomies on the WordPress site';
	}

	/**
	 * Get the JSON Schema for tool parameters.
	 *
	 * @return array<string, mixed>
	 */
	public function get_parameters(): array {
		return [
			'type' => [
				'type'        => 'string',
				'enum'        => [ 'post_types', 'taxonomies', 'all' ],
				'description' => 'Filter by type: post_types, taxonomies, or all (default)',
				'default'     => 'all',
			],
		];
	}

	/**
	 * Get the capability requirement for this tool.
	 *
	 * @return RestCapabilityRequirement|null
	 */
	public function get_rest_capability(): ?RestCapabilityRequirement {
		return new RestCapabilityRequirement( ModelRestPolicy::CAPABILITY_MODELS );
	}

	/**
	 * Build the WP_REST_Request for listing models.
	 *
	 * @param array<string, mixed> $args
	 * @return \WP_REST_Request|null
	 */
	public function build_rest_request( array $args ): ?\WP_REST_Request {
		return $this->request( 'GET', '/saltus-framework/v1/models', $args );
	}

	/**
	 * Whether responses from this tool can be cached.
	 *
	 * @return bool
	 */
	public function is_cacheable(): bool {
		return true;
	}

	/**
	 * Cache time-to-live in seconds.
	 *
	 * @return int
	 */
	public function cache_ttl(): int {
		return 600;
	}
}
