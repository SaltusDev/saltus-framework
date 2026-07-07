<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\Features\Meta\MetaFieldProvider;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Rest\ModelRestPolicy;

/**
 * MCP tool to retrieve meta field definitions for a post type.
 */
class GetMetaFields extends RestTool {

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
		return 'get_meta_fields';
	}

	/**
	 * Get the tool description for the AI.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Get the meta field definitions for a post type as configured in the Saltus Framework model';
	}

	/**
	 * Get the JSON Schema for tool parameters.
	 *
	 * @return array<string, mixed>
	 */
	public function get_parameters(): array {
		return [
			'post_type' => [
				'type'        => 'string',
				'description' => 'The post type slug to get meta fields for',
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
		return new RestCapabilityRequirement( ModelRestPolicy::CAPABILITY_META, 'post_type' );
	}

	/**
	 * Build the WP_REST_Request for retrieving meta field definitions.
	 *
	 * @param array<string, mixed> $args
	 * @return \WP_REST_Request|null
	 */
	public function build_rest_request( array $args ): ?\WP_REST_Request {
		return $this->request( 'GET', '/saltus-framework/v1/meta/' . rawurlencode( (string) ( $args['post_type'] ?? '' ) ) );
	}

	/**
	 * Retrieve meta fields directly through the shared feature provider.
	 *
	 * @param Modeler $modeler The model registry.
	 * @param ModelRestPolicy|null $policy Optional REST policy.
	 * @param string $post_type Post type slug.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_meta_fields( Modeler $modeler, ?ModelRestPolicy $policy, string $post_type ) {
		return $this->meta_field_provider->post_type_meta( $modeler, $policy, $post_type );
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
		$post_type  = (string) ( $args['post_type'] ?? '' );
		$capability = $post_type !== '' ? $this->post_type_capability( $post_type, 'edit_posts', 'edit_posts' ) : 'edit_posts';

		return current_user_can( $capability );
	}
}
