<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\MCP\Client\WordPressClient;

class UpdatePost implements ToolInterface {

	public function get_name(): string {
		return 'update_post';
	}

	public function get_description(): string {
		return 'Update an existing post\'s fields and meta data';
	}

	/**
	* @return array<string, mixed>
	*/
	public function get_parameters(): array {
		return [
			'post_id'   => [
				'type'        => 'number',
				'description' => 'The post ID to update',
				'required'    => true,
			],
			'post_type' => [
				'type'        => 'string',
				'description' => 'The post type slug',
				'default'     => 'posts',
			],
			'title'     => [
				'type'        => 'string',
				'description' => 'New post title',
			],
			'content'   => [
				'type'        => 'string',
				'description' => 'New post content (HTML or raw text)',
			],
			'excerpt'   => [
				'type'        => 'string',
				'description' => 'New post excerpt',
			],
			'slug'      => [
				'type'        => 'string',
				'description' => 'New URL slug',
			],
			'status'    => [
				'type'        => 'string',
				'enum'        => [ 'publish', 'draft', 'pending', 'private' ],
				'description' => 'New post status',
			],
			'meta'      => [
				'type'                 => 'object',
				'description'          => 'Meta fields to update as key-value pairs',
				'additionalProperties' => true,
			],
		];
	}

	/**
	* @param array<string, mixed> $args
	* @return array<string, mixed>
	*/
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- Optional post fields map directly to the REST payload.
	public function handle( array $args, WordPressClient $client ): array {
		$post_id   = $args['post_id'] ?? 0;
		$post_type = $args['post_type'] ?? 'posts';

		if ( ! $post_id ) {
			return [
				'code'    => 'invalid_params',
				'message' => 'post_id is required',
			];
		}

		$data = [];
		foreach ( [ 'title', 'content', 'excerpt', 'slug', 'status' ] as $field ) {
			if ( isset( $args[ $field ] ) ) {
				$data[ $field ] = $args[ $field ];
			}
		}

		if ( ! empty( $args['meta'] ) ) {
			$data['meta'] = $args['meta'];
		}

		if ( empty( $data ) ) {
			return [
				'code'    => 'invalid_params',
				'message' => 'No fields to update',
			];
		}

		$result = $client->put( "wp/v2/{$post_type}/{$post_id}", $data );

		if ( isset( $result['code'] ) ) {
			return $result;
		}

		return [
			'id'     => $result['id'] ?? 0,
			'title'  => $result['title']['rendered'] ?? '',
			'link'   => $result['link'] ?? '',
			'status' => $result['status'] ?? '',
		];
	}
}
