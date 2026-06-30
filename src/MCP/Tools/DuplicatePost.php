<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\MCP\Client\WordPressClient;

class DuplicatePost implements ToolInterface {

	public function getName(): string {
		return 'duplicate_post';
	}

	public function getDescription(): string {
		return 'Duplicate a WordPress post, creating a copy with "(Copy)" appended to the title';
	}

	/**
	* @return array<string, mixed>
	*/
	public function getParameters(): array {
		return [
			'post_id' => [
				'type'        => 'number',
				'description' => 'The ID of the post to duplicate',
				'required'    => true,
			],
		];
	}

	/**
	* @param array<string, mixed> $args
	* @return array<string, mixed>
	*/
	public function handle( array $args, WordPressClient $client ): array {
		$postId = $args['post_id'] ?? 0;

		if ( ! $postId ) {
			return [
				'code'    => 'invalid_params',
				'message' => 'post_id is required',
			];
		}

		$result = $client->post( "saltus-framework/v1/duplicate/{$postId}" );

		if ( isset( $result['code'] ) ) {
			return $result;
		}

		return [
			'id'         => $result['id'] ?? 0,
			'post_type'  => $result['post_type'] ?? '',
			'title'      => $result['post_title'] ?? '',
			'status'     => $result['post_status'] ?? '',
			'edit_link'  => $result['edit_link'] ?? '',
		];
	}
}
