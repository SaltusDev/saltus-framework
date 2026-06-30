<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\MCP\Client\WordPressClient;

class CreatePost implements ToolInterface {

	public function get_name(): string {
		return 'create_post';
	}

	public function get_description(): string {
		return 'Create a new post in any registered Custom Post Type';
	}

	/**
	* @return array<string, mixed>
	*/
	public function get_parameters(): array {
		return [
			'post_type' => [
				'type'        => 'string',
				'description' => 'The post type slug (e.g., "posts", "page", "product")',
				'default'     => 'posts',
			],
			'title'     => [
				'type'        => 'string',
				'description' => 'The post title',
				'required'    => true,
			],
			'content'   => [
				'type'        => 'string',
				'description' => 'The post content (HTML or raw text)',
			],
			'excerpt'   => [
				'type'        => 'string',
				'description' => 'The post excerpt',
			],
			'slug'      => [
				'type'        => 'string',
				'description' => 'URL slug',
			],
			'status'    => [
				'type'        => 'string',
				'enum'        => [ 'publish', 'draft', 'pending', 'private' ],
				'description' => 'Post status',
				'default'     => 'draft',
			],
			'meta'      => [
				'type'                 => 'object',
				'description'          => 'Meta fields as key-value pairs',
				'additionalProperties' => true,
			],
			'terms'     => [
				'type'                 => 'object',
				'description'          => 'Taxonomy terms as {taxonomy: [term_id, ...]}',
				'additionalProperties' => [
					'type'  => 'array',
					'items' => [ 'type' => 'number' ],
				],
			],
		];
	}

	/**
	* @param array<string, mixed> $args
	* @return array<string, mixed>
	*/
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- Optional post fields map directly to the REST payload.
	public function handle( array $args, WordPressClient $client ): array {
		$post_type = $args['post_type'] ?? 'posts';

		$data = [
			'title'  => $args['title'] ?? '',
			'status' => $args['status'] ?? 'draft',
		];

		if ( ! empty( $args['content'] ) ) {
			$data['content'] = $args['content'];
		}

		if ( ! empty( $args['excerpt'] ) ) {
			$data['excerpt'] = $args['excerpt'];
		}

		if ( ! empty( $args['slug'] ) ) {
			$data['slug'] = $args['slug'];
		}

		if ( ! empty( $args['meta'] ) ) {
			$data['meta'] = $args['meta'];
		}

		if ( ! empty( $args['terms'] ) ) {
			foreach ( $args['terms'] as $taxonomy => $term_ids ) {
				$data[ $taxonomy ] = $term_ids;
			}
		}

		$result = $client->post( "wp/v2/{$post_type}", $data );

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
