<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\MCP\Client\WordPressClient;

class GetModel implements ToolInterface {

	public function getName(): string {
		return 'get_model';
	}

	public function getDescription(): string {
		return 'Get details of a specific Custom Post Type or Taxonomy by slug';
	}

	/**
	* @return array<string, mixed>
	*/
	public function getParameters(): array {
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

		$postType = $client->get( "wp/v2/types/{$slug}" );

		if ( ! empty( $postType ) && ! isset( $postType['code'] ) ) {
			return [
				'type' => 'post_type',
				'data' => $postType,
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
