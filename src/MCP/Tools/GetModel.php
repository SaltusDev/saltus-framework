<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\Rest\ModelRestPolicy;

/**
 * MCP tool to retrieve details of a specific Custom Post Type or Taxonomy.
 */
class GetModel extends RestTool {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'get_model';
	}

	/**
	 * Get the tool description for the AI.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Get details of a specific Custom Post Type or Taxonomy by slug';
	}

	/**
	 * Get the JSON Schema for tool parameters.
	 *
	 * @return array<string, mixed>
	 */
	public function get_parameters(): array {
		return [
			'slug' => [
				'type'        => 'string',
				'description' => 'The slug of the post type or taxonomy (e.g., "post", "page", "product")',
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
		return new RestCapabilityRequirement( ModelRestPolicy::CAPABILITY_MODELS );
	}

	/**
	 * Build the WP_REST_Request for retrieving a model.
	 *
	 * @param array<string, mixed> $args
	 * @return \WP_REST_Request|null
	 */
	public function build_rest_request( array $args ): ?\WP_REST_Request {
		return $this->request( 'GET', '/saltus-framework/v1/models/' . rawurlencode( (string) ( $args['slug'] ?? '' ) ) );
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
