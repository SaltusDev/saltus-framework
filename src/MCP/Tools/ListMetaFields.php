<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\Features\Meta\MetaFieldProvider;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Rest\ModelRestPolicy;

/**
 * MCP tool to list meta field definitions for all registered Saltus post types.
 */
class ListMetaFields extends RestTool {

	private MetaFieldProvider $meta_field_provider;

	/**
	 * @param MetaFieldProvider|null $meta_field_provider Shared meta field provider.
	 */
	public function __construct( ?MetaFieldProvider $meta_field_provider = null ) {
		$this->meta_field_provider = $meta_field_provider ?? new MetaFieldProvider();
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'list_meta_fields';
	}

	/**
	 * Get the tool description for the AI.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'List model-defined meta field definitions for all registered Saltus post types';
	}

	/**
	 * Get the JSON Schema for tool parameters.
	 *
	 * @return array<string, mixed>
	 */
	public function get_parameters(): array {
		return [];
	}

	/**
	 * Get the capability requirement for this tool.
	 *
	 * @return RestCapabilityRequirement|null
	 */
	public function get_rest_capability(): ?RestCapabilityRequirement {
		return new RestCapabilityRequirement( ModelRestPolicy::CAPABILITY_META, 'post_type' );
	}

	/**
	 * Build the WP_REST_Request for listing meta field definitions.
	 *
	 * @param array<string, mixed> $args
	 * @return \WP_REST_Request|null
	 */
	public function build_rest_request( array $args ): ?\WP_REST_Request {
		return $this->request( 'GET', '/saltus-framework/v1/meta' );
	}

	/**
	 * List meta fields directly through the shared feature provider.
	 *
	 * @param Modeler $modeler The model registry.
	 * @param ModelRestPolicy|null $policy Optional REST policy.
	 * @param callable(string): bool $can_view_post_type Callback for post-type visibility.
	 * @return list<array<string, mixed>>
	 */
	public function list_meta_fields( Modeler $modeler, ?ModelRestPolicy $policy, callable $can_view_post_type ): array {
		return $this->meta_field_provider->all_post_type_meta( $modeler, $policy, $can_view_post_type );
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

	/**
	 * @param array<string, mixed> $args
	 * @return bool
	 */
	public function has_permission( array $args ): bool {
		return current_user_can( 'edit_posts' );
	}
}
