<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\MCP\Client\WordPressClient;

class ListMetaFields implements ToolInterface {

	public function getName(): string {
		return 'list_meta_fields';
	}

	public function getDescription(): string {
		return 'List model-defined meta field definitions for all registered Saltus post types';
	}

	/**
	* @return array<string, mixed>
	*/
	public function getParameters(): array {
		return [];
	}

	/**
	* @param array<string, mixed> $args
	* @return array<string, mixed>
	*/
	public function handle( array $args, WordPressClient $client ): array {
		$result = $client->get( 'saltus-framework/v1/meta' );

		if ( isset( $result['code'] ) ) {
			return $result;
		}

		return [
			'post_types' => $result['post_types'] ?? [],
		];
	}
}
