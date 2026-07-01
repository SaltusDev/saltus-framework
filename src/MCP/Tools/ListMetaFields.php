<?php
namespace Saltus\WP\Framework\MCP\Tools;

class ListMetaFields implements ToolInterface {

	public function get_name(): string {
		return 'list_meta_fields';
	}

	public function get_description(): string {
		return 'List model-defined meta field definitions for all registered Saltus post types';
	}

	/**
	* @return array<string, mixed>
	*/
	public function get_parameters(): array {
		return [];
	}
}
