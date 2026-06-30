<?php
namespace Saltus\WP\Framework\MCP\Prompts;

class PromptProvider {

	/**
	* @return list<array{name: string, description: string, arguments: list<array{name: string, description: string, required?: bool}>}>
	*/
	public function list(): array {
		return [
			[
				'name'        => 'create_content',
				'description' => 'Generate a new post with AI-optimized content for a specific post type',
				'arguments'   => [
					[
						'name'        => 'post_type',
						'description' => 'The post type slug (e.g., "posts", "page", "product")',
						'required'    => true,
					],
					[
						'name'        => 'topic',
						'description' => 'The topic or subject of the content to create',
						'required'    => true,
					],
					[
						'name'        => 'tone',
						'description' => 'The writing tone (e.g., "professional", "casual", "technical")',
					],
				],
			],
			[
				'name'        => 'analyze_content',
				'description' => 'Analyze an existing post\'s content and suggest improvements',
				'arguments'   => [
					[
						'name'        => 'post_id',
						'description' => 'The ID of the post to analyze',
						'required'    => true,
					],
				],
			],
			[
				'name'        => 'site_overview',
				'description' => 'Get a comprehensive overview of all content types and models on the site',
				'arguments'   => [],
			],
		];
	}

	/**
	* @param array<string, mixed> $arguments
	* @return array{description: string, messages: list<array{role: string, content: array{type: string, text: string}}>}|null
	*/
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- Prompt dispatch is intentionally explicit.
	public function get( string $name, array $arguments = [] ): ?array {
		switch ( $name ) {
			case 'create_content':
				$post_type = $arguments['post_type'] ?? 'posts';
				$topic     = $arguments['topic'] ?? '';
				$tone      = $arguments['tone'] ?? 'professional';

				return [
					'description' => 'Create content in the ' . $post_type . ' post type',
					'messages'    => [
						[
							'role'    => 'user',
							'content' => [
								'type' => 'text',
								'text' => 'Create a new ' . $post_type . ' post' . ( $topic ? ' about ' . $topic : '' ) . ' with a ' . $tone . ' tone. Use the create_post tool to publish it.',
							],
						],
					],
				];

			case 'analyze_content':
				$post_id = $arguments['post_id'] ?? null;

				return [
					'description' => 'Analyze post #' . ( $post_id ?? '?' ) . ' for content quality',
					'messages'    => [
						[
							'role'    => 'user',
							'content' => [
								'type' => 'text',
								'text' => 'Fetch post #' . ( $post_id ?? '?' ) . ' using the get_post tool, then analyze its title, content length, excerpt quality, and status. Suggest specific improvements for SEO and readability.',
							],
						],
					],
				];

			case 'site_overview':
				return [
					'description' => 'Overview of all registered content models and their status',
					'messages'    => [
						[
							'role'    => 'user',
							'content' => [
								'type' => 'text',
								'text' => 'Use the list_models tool to fetch all registered post types and taxonomies. For each post type, use list_posts to get recent entries. Summarize the site structure, active content types, and recent activity.',
							],
						],
					],
				];

			default:
				return null;
		}
	}
}
