<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\MCP\Client\WordPressClient;

class ReorderPosts implements ToolInterface {

	public function get_name(): string {
		return 'reorder_posts';
	}

	public function get_description(): string {
		return 'Reorder multiple posts by updating their menu_order values in a single batch operation';
	}

	/**
	* @return array<string, mixed>
	*/
	public function get_parameters(): array {
		return [
			'items' => [
				'type'        => 'array',
				'description' => 'Array of objects with "id" (post ID) and "menu_order" (integer position)',
				'required'    => true,
				'items'       => [
					'type'       => 'object',
					'properties' => [
						'id'         => [
							'type'        => 'number',
							'description' => 'The post ID',
						],
						'menu_order' => [
							'type'        => 'number',
							'description' => 'The new menu order position',
						],
					],
				],
			],
		];
	}

	/**
	* @param array<string, mixed> $args
	* @return array<string, mixed>
	*/
	public function handle( array $args, WordPressClient $client ): array {
		$items = $args['items'] ?? [];

		if ( empty( $items ) || ! is_array( $items ) ) {
			return [
				'code'    => 'invalid_params',
				'message' => 'items must be a non-empty array of {id, menu_order} objects',
			];
		}

		$result = $client->post( 'saltus-framework/v1/reorder', [ 'items' => $items ] );

		if ( isset( $result['code'] ) ) {
			return $result;
		}

		return [
			'results' => $result['results'] ?? [],
			'total'   => $result['total'] ?? 0,
			'updated' => $result['updated'] ?? 0,
		];
	}
}
