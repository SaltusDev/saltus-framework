<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\MCP\Client\WordPressClient;

class GetSettings implements ToolInterface {

	public function get_name(): string {
		return 'get_settings';
	}

	public function get_description(): string {
		return 'Get the Saltus Framework settings for a specific post type';
	}

	/**
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
	* @param array<string, mixed> $args
	* @return array<string, mixed>
	*/
	public function handle( array $args, WordPressClient $client ): array {
		$post_type = $args['post_type'] ?? '';

		if ( ! $post_type ) {
			return [
				'code'    => 'invalid_params',
				'message' => 'post_type is required',
			];
		}

		$result = $client->get( "saltus-framework/v1/settings/{$post_type}" );

		if ( isset( $result['code'] ) ) {
			return $result;
		}

		return [
			'post_type' => $result['post_type'] ?? $post_type,
			'settings'  => $result['settings'] ?? [],
		];
	}
}
