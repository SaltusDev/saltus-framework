<?php
namespace Saltus\WP\Framework\MCP\Abilities;

use Saltus\WP\Framework\MCP\Tools\ToolInterface;
use Saltus\WP\Framework\MCP\Tools\ToolProvider;

/**
 * Converts ToolInterface instances into wp_register_ability-compatible definition arrays.
 *
 * @phpstan-type AbilityDefinition array{
 *     name: lowercase-string&non-falsy-string,
 *     label: string,
 *     description: string,
 *     category: string,
 *     input_schema: array<string, mixed>,
 *     inputSchema: array<string, mixed>,
 *     execute_callback: callable,
 *     permission_callback: callable,
 *     callback: callable,
 *     meta: array<string, mixed>
 * }
 */
class AbilityDefinitionFactory {

	private AbilityRuntime $runtime;

	/**
	 * @param AbilityRuntime|null $runtime  Optional runtime override.
	 */
	public function __construct( ?AbilityRuntime $runtime = null ) {
		$this->runtime = $runtime ?? new AbilityRuntime();
	}

	/**
	 * Generate ability definitions for all tools in a provider.
	 *
	 * @param ToolProvider $provider  The tool provider to generate abilities for.
	 * @return list<AbilityDefinition>
	 */
	public function from_tool_provider( ToolProvider $provider ): array {
		$definitions = [];

		foreach ( $provider->all() as $tool ) {
			$definitions[] = $this->from_tool( $tool );
		}

		return $definitions;
	}

	/**
	 * Generate an ability definition for a single tool.
	 *
	 * @param ToolInterface $tool  The tool to generate an ability for.
	 * @return AbilityDefinition
	 */
	public function from_tool( ToolInterface $tool ): array {
		$schema = $tool->get_parameters();

		return [
			'name'                => $this->ability_name( $tool->get_name() ),
			'label'               => $this->label_from_tool_name( $tool->get_name() ),
			'description'         => $tool->get_description(),
			'category'            => 'saltus-framework',
			'input_schema'        => $schema,
			'inputSchema'         => $schema,
			'execute_callback'    => function ( array $args = [] ) use ( $tool ) {
				return $this->runtime->execute( $tool, $args );
			},
			'permission_callback' => function ( mixed $args = [] ) use ( $tool ): bool {
				return $this->can_use_saltus_abilities( $tool, $args );
			},
			'callback'            => function ( array $args = [] ) use ( $tool ) {
				return $this->runtime->execute( $tool, $args );
			},
			'meta'                => [
				'mcp_tool'     => $tool->get_name(),
				'namespace'    => 'saltus-framework/v1',
				'transport'    => 'wordpress-rest',
				'show_in_rest' => true,
			],
		];
	}

	/**
	 * Permission callback checking whether the current user can use Saltus abilities.
	 *
	 * @param ToolInterface|null $tool  Tool being executed, when available.
	 * @param mixed $args  Ability arguments, when supplied by the native API.
	 * @return bool
	 */
	public function can_use_saltus_abilities( ?ToolInterface $tool = null, mixed $args = [] ): bool {
		if ( ! function_exists( 'current_user_can' ) ) {
			return false;
		}

		$args = $this->normalize_args( $args );
		if ( $tool === null ) {
			return current_user_can( 'read' );
		}

		return $this->can_use_tool( $tool->get_name(), $args );
	}

	/**
	 * Check whether the current user can use a specific tool with known arguments.
	 *
	 * @param string $tool_name  Tool name.
	 * @param array<string, mixed> $args  Ability arguments.
	 * @return bool
	 */
	private function can_use_tool( string $tool_name, array $args ): bool {
		$checks = [
			'create_post'     => fn(): bool => $this->can_create_post( $args ),
			'get_post'        => fn(): bool => $this->can_post( 'read_post', $args ),
			'update_post'     => fn(): bool => $this->can_post( 'edit_post', $args ),
			'delete_post'     => fn(): bool => $this->can_post( 'delete_post', $args ),
			'duplicate_post'  => fn(): bool => $this->can_post( 'edit_post', $args ),
			'export_post'     => fn(): bool => current_user_can( 'export' ),
			'create_term'     => fn(): bool => $this->can_create_term( $args ),
			'update_settings' => fn(): bool => current_user_can( 'manage_options' ),
		];

		return isset( $checks[ $tool_name ] ) ? $checks[ $tool_name ]() : current_user_can( 'read' );
	}

	/**
	 * Normalize native ability callback arguments to an array.
	 *
	 * @param mixed $args  Ability arguments.
	 * @return array<string, mixed>
	 */
	private function normalize_args( mixed $args ): array {
		if ( $args instanceof \WP_REST_Request ) {
			$args = $args->get_params();
		}

		return is_array( $args ) ? $args : [];
	}

	/**
	 * Check whether the current user can create posts for the requested post type.
	 *
	 * @param array<string, mixed> $args  Ability arguments.
	 * @return bool
	 */
	private function can_create_post( array $args ): bool {
		$post_type  = (string) ( $args['post_type'] ?? 'posts' );
		$capability = $this->post_type_capability( $post_type, 'create_posts', 'edit_posts' );

		return current_user_can( $capability );
	}

	/**
	 * Check a post-specific WordPress capability.
	 *
	 * @param string $capability  Capability to check.
	 * @param array<string, mixed> $args  Ability arguments.
	 * @return bool
	 */
	private function can_post( string $capability, array $args ): bool {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return false;
		}

		return current_user_can( $capability, $post_id );
	}

	/**
	 * Check whether the current user can create terms for the requested taxonomy.
	 *
	 * @param array<string, mixed> $args  Ability arguments.
	 * @return bool
	 */
	private function can_create_term( array $args ): bool {
		$taxonomy = (string) ( $args['taxonomy'] ?? '' );
		if ( $taxonomy === '' || ! function_exists( 'get_taxonomy' ) ) {
			return false;
		}

		$taxonomy_object = get_taxonomy( $taxonomy );
		$capability      = 'manage_categories';
		if ( is_object( $taxonomy_object ) && isset( $taxonomy_object->cap->edit_terms ) && is_string( $taxonomy_object->cap->edit_terms ) ) {
			$capability = $taxonomy_object->cap->edit_terms;
		}

		return current_user_can( $capability );
	}

	/**
	 * Resolve a post type capability from its registered object.
	 *
	 * @param string $post_type  Post type slug.
	 * @param string $capability  Capability property to read.
	 * @param string $fallback  Fallback capability.
	 * @return string
	 */
	private function post_type_capability( string $post_type, string $capability, string $fallback ): string {
		if ( ! function_exists( 'get_post_type_object' ) ) {
			return $fallback;
		}

		$post_type_object = get_post_type_object( $post_type );
		if ( is_object( $post_type_object ) && isset( $post_type_object->cap->{$capability} ) && is_string( $post_type_object->cap->{$capability} ) ) {
			return $post_type_object->cap->{$capability};
		}

		return $fallback;
	}

	/**
	 * Convert a tool name to a namespaced ability name.
	 *
	 * @param string $tool_name  The raw tool name.
	 * @return lowercase-string&non-falsy-string
	 */
	private function ability_name( string $tool_name ): string {
		return strtolower( 'saltus/' . str_replace( '_', '-', $tool_name ) );
	}

	/**
	 * Convert a tool name to a human-readable label.
	 *
	 * @param string $tool_name  The raw tool name.
	 * @return string
	 */
	private function label_from_tool_name( string $tool_name ): string {
		return ucwords( str_replace( '_', ' ', $tool_name ) );
	}
}
