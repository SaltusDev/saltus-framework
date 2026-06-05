<?php
namespace Saltus\WP\Framework\MCP\Resources;

class ResourceProvider
{
	/**
  	* Get all resource definitions for MCP resources/list response.
  	*
  	* Resources are static data URIs the AI can read.
  	*
  	* @return list<array{uri: string, name: string, description: string, mimeType: string}>
  	*/
	public function getDefinitions(): array
	{
		return [
			[
				'uri' => 'saltus://models',
				'name' => 'All Registered Models',
				'description' => 'List of all registered post types and taxonomies',
				'mimeType' => 'application/json',
			],
			[
				'uri' => 'saltus://features',
				'name' => 'Framework Features',
				'description' => 'List of all available features/services in the framework',
				'mimeType' => 'application/json',
			],
			[
				'uri' => 'saltus://status',
				'name' => 'Framework Status',
				'description' => 'Framework version, configuration status, and health information',
				'mimeType' => 'application/json',
			],
		];
	}

	/**
  	* Resolve a resource URI to its content.
  	*
	 * @param array<string, mixed> $context
  	 * @return array{contents: list<array{uri: string, mimeType: string, text: string}>}|null
  	*/
	public function resolve(string $uri, array $context = []): ?array
	{
		switch ($uri) {
			case 'saltus://models':
				return [
					'contents' => [
						[
							'uri' => $uri,
							'mimeType' => 'application/json',
							'text' => json_encode([ 
								'description' => 'Use list_models or get_model tool to interact with registered models',
								'hint' => 'Call tools/list_models() to fetch live data from your WordPress site',
							], JSON_PRETTY_PRINT) ?: '',
						],
					],
				];

			case 'saltus://features':
				return [
					'contents' => [
						[
							'uri' => $uri,
							'mimeType' => 'application/json',
							'text' => json_encode([ 
								'available_features' => [
									'admin_cols' => 'Custom admin list table columns',
									'admin_filters' => 'Admin list table filters',
									'drag_and_drop' => 'Drag-and-drop post reordering',
									'duplicate' => 'Post cloning',
									'meta' => 'Metaboxes via Codestar Framework',
									'quick_edit' => 'Quick edit fields',
									'remember_tabs' => 'Admin tab state persistence',
									'settings' => 'Settings pages via Codestar Framework',
									'single_export' => 'Single post XML export',
								],
							], JSON_PRETTY_PRINT) ?: '',
						],
					],
				];

			case 'saltus://status':
				return [
					'contents' => [
						[
							'uri' => $uri,
							'mimeType' => 'application/json',
							'text' => json_encode([ 
								'framework' => 'Saltus Framework',
								'version' => '1.3.4',
								'mcp_server' => 'v0.1.0',
								'status' => 'connected',
							], JSON_PRETTY_PRINT) ?: '',
						],
					],
				];

			default:
				return null;
		}
	}
}
