<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\Features\SingleExport\SaltusSingleExport;
use Saltus\WP\Framework\Rest\ModelRestPolicy;

/**
 * MCP tool to export a WordPress post as WXR.
 */
class ExportPost extends RestTool {

	private SaltusSingleExport $exporter;

	/**
	 * @param SaltusSingleExport|null $exporter  Export feature implementation shared with REST.
	 */
	public function __construct( ?SaltusSingleExport $exporter = null ) {
		$this->exporter = $exporter ?? new SaltusSingleExport( '', [] );
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'export_post';
	}

	/**
	 * Get the tool description for the AI.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Export a WordPress post as WXR (WordPress eXtended RSS) for import into another site';
	}

	/**
	 * Get the JSON Schema for tool parameters.
	 *
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

	/**
	 * Get the capability requirement for this tool.
	 *
	 * @return RestCapabilityRequirement|null
	 */
	public function get_rest_capability(): ?RestCapabilityRequirement {
		return new RestCapabilityRequirement( ModelRestPolicy::CAPABILITY_EXPORT, 'post_type' );
	}

	/**
	 * Build the WP_REST_Request for exporting a post.
	 *
	 * @param array<string, mixed> $args
	 * @return \WP_REST_Request|null
	 */
	public function build_rest_request( array $args ): ?\WP_REST_Request {
		return $this->request( 'GET', '/saltus-framework/v1/export/' . (int) ( $args['post_id'] ?? 0 ) );
	}

	/**
	 * Export a post directly through the shared feature implementation.
	 *
	 * @param int $post_id The post ID to export.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function export_post( int $post_id ) {
		return $this->exporter->export_post( $post_id );
	}
}
