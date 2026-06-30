<?php
namespace Saltus\WP\Framework\MCP\Resources;

use Saltus\WP\Framework\MCP\Client\WordPressClient;

class ResourceProvider {
	private WordPressClient $client;

	public function __construct( WordPressClient $client ) {
		$this->client = $client;
	}

	/**
	 * Get all resource definitions for MCP resources/list response.
	 *
	 * Resources are static data URIs the AI can read.
	 *
	 * @return list<array{uri: string, name: string, description: string, mimeType: string}>
	 */
	public function getDefinitions(): array {
		return [
			[
				'uri'         => 'saltus://models',
				'name'        => 'All Registered Models',
				'description' => 'List of all registered post types and taxonomies from the framework REST API',
				'mimeType'    => 'application/json',
			],
			[
				'uri'         => 'saltus://meta-fields',
				'name'        => 'CPT Meta Fields',
				'description' => 'Model-defined meta field definitions for all registered Saltus custom post types',
				'mimeType'    => 'application/json',
			],
			[
				'uri'         => 'saltus://features',
				'name'        => 'Framework Features',
				'description' => 'List of all available features/services in the framework',
				'mimeType'    => 'application/json',
			],
			[
				'uri'         => 'saltus://status',
				'name'        => 'Framework Status',
				'description' => 'Framework version, configuration status, and health information',
				'mimeType'    => 'application/json',
			],
		];
	}

	/**
	 * Resolve a resource URI to its content.
	 *
	 * @param array<string, mixed> $context
	 * @return array{contents: list<array{uri: string, mimeType: string, text: string}>}|null
	 */
	public function resolve( string $uri, array $context = [] ): ?array {
		switch ( $uri ) {
			case 'saltus://models':
				$models = $this->client->get( 'saltus-framework/v1/models' );
				return [
					'contents' => [
						[
							'uri'      => $uri,
							'mimeType' => 'application/json',
							'text'     => json_encode(
								isset( $models['code'] )
									? [ 'error' => $models['message'] ?? 'Failed to fetch models' ]
									: $models,
								JSON_PRETTY_PRINT
							) ?: '{}',
						],
					],
				];

			case 'saltus://meta-fields':
				return [
					'contents' => [
						[
							'uri'      => $uri,
							'mimeType' => 'application/json',
							'text'     => json_encode( $this->resolveMetaFields(), JSON_PRETTY_PRINT ) ?: '{}',
						],
					],
				];

			case 'saltus://features':
				return [
					'contents' => [
						[
							'uri'      => $uri,
							'mimeType' => 'application/json',
							'text'     => json_encode(
								[
									'available_features' => [
										'admin_cols'    => 'Custom admin list table columns',
										'admin_filters' => 'Admin list table filters',
										'drag_and_drop' => 'Drag-and-drop post reordering',
										'duplicate'     => 'Post cloning',
										'meta'          => 'Metaboxes via Codestar Framework',
										'mcp'           => 'Model Context Protocol tools through WordPress-native abilities',
										'quick_edit'    => 'Quick edit fields',
										'remember_tabs' => 'Admin tab state persistence',
										'settings'      => 'Settings pages via Codestar Framework',
										'single_export' => 'Single post XML export',
									],
								],
								JSON_PRETTY_PRINT
							) ?: '',
						],
					],
				];

			case 'saltus://status':
				return [
					'contents' => [
						[
							'uri'      => $uri,
							'mimeType' => 'application/json',
							'text'     => json_encode(
								[
									'framework'  => 'Saltus Framework',
									'version'    => '2.0.0',
									'mcp_abilities' => 'wordpress-native',
									'status'     => 'connected',
								],
								JSON_PRETTY_PRINT
							) ?: '',
						],
					],
				];

			default:
				return null;
		}
	}

	/**
	 * Resolve all model-defined meta fields for registered post type models.
	 *
	 * @return array<string, mixed>
	 */
	private function resolveMetaFields(): array {
		$meta = $this->client->get( 'saltus-framework/v1/meta' );

		if ( isset( $meta['code'] ) ) {
			return [ 'error' => $meta['message'] ?? 'Failed to fetch meta fields' ];
		}

		return [ 'post_types' => $meta['post_types'] ?? [] ];
	}
}
