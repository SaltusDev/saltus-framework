<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\MCP\Client\WordPressClient;

class DeletePost implements ToolInterface {

	public function getName(): string {
		return 'delete_post';
	}

	public function getDescription(): string {
		return 'Delete (trash or force delete) a post by ID';
	}

	/**
	* @return array<string, mixed>
	*/
	public function getParameters(): array {
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
		$postId   = $args['post_id'] ?? 0;
		$postType = $args['post_type'] ?? 'posts';
		$force    = ! empty( $args['force'] );

		if ( ! $postId ) {
			return [
				'code'    => 'invalid_params',
				'message' => 'post_id is required',
			];
		}

		$query = [ 'force' => $force ];

		$result = $client->delete( "wp/v2/{$postType}/{$postId}", $query );

		if ( isset( $result['code'] ) ) {
			return $result;
		}

		return [
			'deleted'     => true,
			'previous_id' => $result['previous']['id'] ?? $postId,
			'status'      => $result['status'] ?? 'trash',
		];
	}
}
