<?php
namespace Saltus\WP\Framework\MCP\Tools;

/**
 * MCP tool to create a new term in a taxonomy.
 */
class CreateTerm extends RestTool {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'create_term';
	}

	/**
	 * Get the tool description for the AI.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Create a new term in a taxonomy';
	}

	/**
	 * Get the JSON Schema for tool parameters.
	 *
	 * @return array<string, mixed>
	 */
	public function get_parameters(): array {
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

	/**
	 * Build the WP_REST_Request for creating a term.
	 *
	 * @param array<string, mixed> $args
	 * @return \WP_REST_Request|null
	 */
	public function build_rest_request( array $args ): ?\WP_REST_Request {
		$body = $this->only_args( $args, [ 'name', 'slug', 'description', 'parent' ] );

		return $this->request( 'POST', '/wp/v2/' . rawurlencode( $this->taxonomy_rest_base( (string) ( $args['taxonomy'] ?? '' ) ) ), [], $body );
	}
}
