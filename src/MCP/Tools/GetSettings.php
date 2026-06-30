<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\MCP\Client\WordPressClient;

class GetSettings implements ToolInterface {

	public function getName(): string {
		return 'get_settings';
	}

	public function getDescription(): string {
		return 'Get the Saltus Framework settings for a specific post type';
	}

	/**
	* @return array<string, mixed>
	*/
	public function getParameters(): array {
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
		$postType = $args['post_type'] ?? '';

		if ( ! $postType ) {
			return [
				'code'    => 'invalid_params',
				'message' => 'post_type is required',
			];
		}

		$result = $client->get( "saltus-framework/v1/settings/{$postType}" );

		if ( isset( $result['code'] ) ) {
			return $result;
		}

		return [
			'post_type' => $result['post_type'] ?? $postType,
			'settings'  => $result['settings'] ?? [],
		];
	}
}
