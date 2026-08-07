<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\Features\Settings\SettingsManager;
use Saltus\WP\Framework\Rest\ModelRestPolicy;

/**
 * MCP tool to retrieve Saltus Framework settings for a post type.
 */
class GetSettings extends RestTool {

	private SettingsManager $settings_manager;

	/**
	 * @param SettingsManager|null $settings_manager Shared settings manager.
	 */
	public function __construct( ?SettingsManager $settings_manager = null ) {
		$this->settings_manager = $settings_manager ?? new SettingsManager();
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'get_settings';
	}

	/**
	 * Get the tool description for the AI.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Get the Saltus Framework settings for a specific post type';
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
				'description' => 'The post type slug to get settings for',
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
		return new RestCapabilityRequirement( ModelRestPolicy::CAPABILITY_SETTINGS, 'post_type' );
	}

	/**
	 * Build the WP_REST_Request for retrieving settings.
	 *
	 * @param array<string, mixed> $args
	 * @return \WP_REST_Request|null
	 */
	public function build_rest_request( array $args ): ?\WP_REST_Request {
		return $this->request( 'GET', $this->mcp_route( '/settings/' . rawurlencode( (string) ( $args['post_type'] ?? '' ) ) ) );
	}

	/**
	 * Retrieve settings directly through the shared feature manager.
	 *
	 * @param string $post_type The post type slug.
	 * @return array{post_type: string, settings: mixed}
	 */
	public function get_settings( string $post_type ): array {
		return $this->settings_manager->get_settings( $post_type );
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
	 * @param array<string, mixed> $args
	 * @return bool
	 */
	public function has_permission( array $args ): bool {
		$post_type  = (string) ( $args['post_type'] ?? '' );
		$capability = $post_type !== '' ? $this->post_type_capability( $post_type, 'edit_posts', 'edit_posts' ) : 'edit_posts';

		return current_user_can( $capability );
	}
}
