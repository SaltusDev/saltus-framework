<?php
namespace Saltus\WP\Framework\MCP\Tools;

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
}
