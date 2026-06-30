<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\MCP\Client\WordPressClient;

class GetMetaFields implements ToolInterface {

	public function get_name(): string {
		return 'get_meta_fields';
	}

	public function get_description(): string {
		return 'Get the meta field definitions for a post type as configured in the Saltus Framework model';
	}

	/**
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

		$result = $client->get( "saltus-framework/v1/meta/{$post_type}" );

		if ( isset( $result['code'] ) ) {
			return $result;
		}

		return [
			'post_type'  => $result['post_type'] ?? $post_type,
			'meta'       => $result['meta'] ?? [],
			'normalized' => $result['normalized'] ?? [
				'fields'         => [],
				'rest_meta_keys' => [],
			],
		];
	}
}
