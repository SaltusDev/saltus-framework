<?php
namespace Saltus\WP\Framework\MCP\Tools;

class ExportPost implements ToolInterface {

	public function get_name(): string {
		return 'export_post';
	}

	public function get_description(): string {
		return 'Export a WordPress post as WXR (WordPress eXtended RSS) for import into another site';
	}

	/**
	* @return array<string, mixed>
	*/
	public function get_parameters(): array {
		return [
			'post_id' => [
				'type'        => 'number',
				'description' => 'The ID of the post to export',
				'required'    => true,
			],
		];
	}
}
