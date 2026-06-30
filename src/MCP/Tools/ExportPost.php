<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\MCP\Client\WordPressClient;

class ExportPost implements ToolInterface {

	public function getName(): string {
		return 'export_post';
	}

	public function getDescription(): string {
		return 'Export a WordPress post as WXR (WordPress eXtended RSS) for import into another site';
	}

	/**
	* @return array<string, mixed>
	*/
	public function getParameters(): array {
		return [
			'post_id' => [
				'type'        => 'number',
				'description' => 'The ID of the post to export',
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

		$result = $client->get( "saltus-framework/v1/export/{$postId}" );

		if ( isset( $result['code'] ) ) {
			return $result;
		}

		return [
			'post_id'    => $result['post_id'] ?? 0,
			'post_type'  => $result['post_type'] ?? '',
			'title'      => $result['post_title'] ?? '',
			'wxr'        => $result['wxr'] ?? '',
		];
	}
}
