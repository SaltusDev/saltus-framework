<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\MCP\Client\WordPressClient;

class UpdateSettings implements ToolInterface {

	public function get_name(): string {
		return 'update_settings';
	}

	public function get_description(): string {
		return 'Update the Saltus Framework settings for a specific post type';
	}

	/**
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
	* @param array<string, mixed> $args
	* @return array<string, mixed>
	*/
	public function handle( array $args, WordPressClient $client ): array {
		$post_type = $args['post_type'] ?? '';
		$settings  = $args['settings'] ?? [];

		if ( ! $post_type ) {
			return [
				'code'    => 'invalid_params',
				'message' => 'post_type is required',
			];
		}

		if ( empty( $settings ) || ! is_array( $settings ) ) {
			return [
				'code'    => 'invalid_params',
				'message' => 'settings must be a non-empty object',
			];
		}

		$result = $client->put( "saltus-framework/v1/settings/{$post_type}", $settings );

		if ( isset( $result['code'] ) ) {
			return $result;
		}

		return [
			'post_type' => $result['post_type'] ?? $post_type,
			'settings'  => $result['settings'] ?? [],
			'status'    => $result['status'] ?? 'unknown',
		];
	}
}
