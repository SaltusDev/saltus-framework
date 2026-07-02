<?php
namespace Saltus\WP\Framework\MCP\Tools;

/**
 * MCP tool to list terms from a taxonomy.
 */
class ListTerms extends RestTool {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'list_terms';
	}

	/**
	 * Get the tool description for the AI.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'List terms from a taxonomy (categories, tags, or custom taxonomies)';
	}

	/**
	 * Get the JSON Schema for tool parameters.
	 *
	 * @return array<string, mixed>
	 */
	public function get_parameters(): array {
		return [
			'taxonomy'   => [
				'type'        => 'string',
				'description' => 'The taxonomy slug (e.g., "categories", "tags", or custom)',
				'required'    => true,
			],
			'per_page'   => [
				'type'        => 'number',
				'description' => 'Number of terms per page (max 100)',
				'default'     => 50,
			],
			'search'     => [
				'type'        => 'string',
				'description' => 'Search term',
			],
			'hide_empty' => [
				'type'        => 'boolean',
				'description' => 'Whether to hide terms with no posts',
				'default'     => false,
			],
		];
	}

	/**
	 * Build the WP_REST_Request for listing terms.
	 *
	 * @param array<string, mixed> $args
	 * @return \WP_REST_Request|null
	 */
	public function build_rest_request( array $args ): ?\WP_REST_Request {
		$query = $this->only_args( $args, [ 'per_page', 'search', 'hide_empty' ] );

		return $this->request( 'GET', '/wp/v2/' . rawurlencode( $this->taxonomy_rest_base( (string) ( $args['taxonomy'] ?? 'categories' ) ) ), $query );
	}

	/**
	 * Whether responses from this tool can be cached.
	 *
	 * @return bool
	 */
	public function is_cacheable(): bool {
		return true;
	}
}
