<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\MCP\Client\WordPressClient;

class CreateTerm implements ToolInterface {

	public function getName(): string {
		return 'create_term';
	}

	public function getDescription(): string {
		return 'Create a new term in a taxonomy';
	}

	public function getParameters(): array {
		return [
			'taxonomy'    => [
				'type'        => 'string',
				'description' => 'The taxonomy slug (e.g., "categories", "tags")',
				'required'    => true,
			],
			'name'        => [
				'type'        => 'string',
				'description' => 'The term name',
				'required'    => true,
			],
			'slug'        => [
				'type'        => 'string',
				'description' => 'URL slug (auto-generated if not provided)',
			],
			'description' => [
				'type'        => 'string',
				'description' => 'Term description',
			],
			'parent'      => [
				'type'        => 'number',
				'description' => 'Parent term ID (for hierarchical taxonomies)',
			],
		];
	}

	public function handle( array $args, WordPressClient $client ): array {
		$taxonomy = $args['taxonomy'] ?? '';
		$name     = $args['name'] ?? '';

		if ( ! $taxonomy ) {
			return [
				'code'    => 'invalid_params',
				'message' => 'taxonomy is required',
			];
		}

		if ( ! $name ) {
			return [
				'code'    => 'invalid_params',
				'message' => 'name is required',
			];
		}

		$data = [
			'name' => $name,
		];

		if ( ! empty( $args['slug'] ) ) {
			$data['slug'] = $args['slug'];
		}

		if ( ! empty( $args['description'] ) ) {
			$data['description'] = $args['description'];
		}

		if ( ! empty( $args['parent'] ) ) {
			$data['parent'] = $args['parent'];
		}

		$result = $client->post( "wp/v2/{$taxonomy}", $data );

		if ( isset( $result['code'] ) ) {
			return $result;
		}

		return [
			'id'       => $result['id'] ?? 0,
			'name'     => $result['name'] ?? '',
			'slug'     => $result['slug'] ?? '',
			'taxonomy' => $result['taxonomy'] ?? '',
		];
	}
}
