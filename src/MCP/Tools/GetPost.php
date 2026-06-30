<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\MCP\Client\WordPressClient;

class GetPost implements ToolInterface {

	public function get_name(): string {
		return 'get_post';
	}

	public function get_description(): string {
		return 'Get a single post by ID with all fields and meta data';
	}

	/**
	* @return array<string, mixed>
	*/
	public function get_parameters(): array {
		return [
			'post_id'   => [
				'type'        => 'number',
				'description' => 'The post ID',
				'required'    => true,
			],
			'post_type' => [
				'type'        => 'string',
				'description' => 'The post type slug (defaults to "posts")',
				'default'     => 'posts',
			],
		];
	}

	/**
	* @param array<string, mixed> $args
	* @return array<string, mixed>
	*/
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.MaxExceeded -- Response formatting mirrors WordPress REST post shape.
	public function handle( array $args, WordPressClient $client ): array {
		$post_id   = $args['post_id'] ?? 0;
		$post_type = $args['post_type'] ?? 'posts';

		if ( ! $post_id ) {
			return [
				'code'    => 'invalid_params',
				'message' => 'post_id is required',
			];
		}

		$endpoint = "wp/v2/{$post_type}/{$post_id}";

		$post = $client->get( $endpoint );

		if ( isset( $post['code'] ) ) {
			return $post;
		}

		$result = [
			'id'         => $post['id'] ?? 0,
			'title'      => $post['title']['rendered'] ?? $post['title']['raw'] ?? '',
			'content'    => $post['content']['rendered'] ?? $post['content']['raw'] ?? '',
			'excerpt'    => $post['excerpt']['rendered'] ?? '',
			'slug'       => $post['slug'] ?? '',
			'status'     => $post['status'] ?? '',
			'date'       => $post['date'] ?? '',
			'modified'   => $post['modified'] ?? '',
			'type'       => $post['type'] ?? '',
			'author'     => $post['author'] ?? 0,
			'parent'     => $post['parent'] ?? 0,
			'menu_order' => $post['menu_order'] ?? 0,
			'permalink'  => $post['link'] ?? '',
		];

		if ( ! empty( $post['meta'] ) ) {
			$result['meta'] = $post['meta'];
		}

		if ( ! empty( $post['featured_media'] ) ) {
			$result['featured_media'] = $post['featured_media'];
		}

		if ( ! empty( $post['_embedded']['wp:term'] ) ) {
			$terms = [];
			foreach ( $post['_embedded']['wp:term'] as $term_group ) {
				foreach ( $term_group as $term ) {
					$terms[] = [
						'id'       => $term['id'],
						'name'     => $term['name'],
						'slug'     => $term['slug'],
						'taxonomy' => $term['taxonomy'],
					];
				}
			}
			$result['terms'] = $terms;
		}

		return $result;
	}
}
