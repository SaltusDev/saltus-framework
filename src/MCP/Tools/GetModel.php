<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\MCP\Client\WordPressClient;

class GetModel implements ToolInterface {

	public function get_name(): string {
		return 'get_model';
	}

	public function get_description(): string {
		return 'Get details of a specific Custom Post Type or Taxonomy by slug';
	}

	/**
	* @return array<string, mixed>
	*/
	public function get_parameters(): array {
		return [
			'slug' => [
				'type'        => 'string',
				'description' => 'The slug of the post type or taxonomy (e.g., "post", "page", "product")',
				'required'    => true,
			],
		];
	}

	/**
	* @param array<string, mixed> $args
	* @return array<string, mixed>
	*/
	public function handle( array $args, WordPressClient $client ): array {
		$slug = $args['slug'] ?? '';

		if ( ! $slug ) {
			return [
				'code'    => 'invalid_params',
				'message' => 'Model slug is required',
			];
		}

		$post_type = $client->get( "wp/v2/types/{$slug}" );

		if ( ! empty( $post_type ) && ! isset( $post_type['code'] ) ) {
			return [
				'type' => 'post_type',
				'data' => $post_type,
			];
		}

		$taxonomy = $client->get( "wp/v2/taxonomies/{$slug}" );

		if ( ! empty( $taxonomy ) && ! isset( $taxonomy['code'] ) ) {
			return [
				'type' => 'taxonomy',
				'data' => $taxonomy,
			];
		}

		return [ 'error' => "Model '{$slug}' not found" ];
	}
}
