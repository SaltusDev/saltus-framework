<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\MCP\Client\WordPressClient;

class DeletePost implements ToolInterface {

	public function get_name(): string {
		return 'delete_post';
	}

	public function get_description(): string {
		return 'Delete (trash or force delete) a post by ID';
	}

	/**
	* @return array<string, mixed>
	*/
	public function get_parameters(): array {
		return [
			'post_id'   => [
				'type'        => 'number',
				'description' => 'The post ID to delete',
				'required'    => true,
			],
			'post_type' => [
				'type'        => 'string',
				'description' => 'The post type slug',
				'default'     => 'posts',
			],
			'force'     => [
				'type'        => 'boolean',
				'description' => 'Whether to force delete (skip trash)',
				'default'     => false,
			],
		];
	}

	/**
	* @param array<string, mixed> $args
	* @return array<string, mixed>
	*/
	public function handle( array $args, WordPressClient $client ): array {
		$post_id   = $args['post_id'] ?? 0;
		$post_type = $args['post_type'] ?? 'posts';
		$force     = ! empty( $args['force'] );

		if ( ! $post_id ) {
			return [
				'code'    => 'invalid_params',
				'message' => 'post_id is required',
			];
		}

		$query = [ 'force' => $force ];

		$result = $client->delete( "wp/v2/{$post_type}/{$post_id}", $query );

		if ( isset( $result['code'] ) ) {
			return $result;
		}

		return [
			'deleted'     => true,
			'previous_id' => $result['previous']['id'] ?? $post_id,
			'status'      => $result['status'] ?? 'trash',
		];
	}
}
