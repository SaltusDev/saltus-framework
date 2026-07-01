<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\Rest\ModelRestPolicy;

/**
 * MCP tool to update Saltus Framework settings for a post type.
 */
class UpdateSettings extends RestTool {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'update_settings';
	}

	/**
	 * Get the tool description for the AI.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Update the Saltus Framework settings for a specific post type';
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
				'description' => 'The post type slug to update settings for',
				'required'    => true,
			],
			'settings'  => [
				'type'        => 'object',
				'description' => 'The settings data to update (key-value pairs)',
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
	 * Build the WP_REST_Request for updating settings.
	 *
	 * @param array<string, mixed> $args
	 * @return \WP_REST_Request|null
	 */
	public function build_rest_request( array $args ): ?\WP_REST_Request {
		$body = is_array( $args['settings'] ?? null ) ? $args['settings'] : [];

		return $this->request( 'PUT', '/saltus-framework/v1/settings/' . rawurlencode( (string) ( $args['post_type'] ?? '' ) ), [], $body );
	}
}
