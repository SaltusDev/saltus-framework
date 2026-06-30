<?php
namespace Saltus\WP\Framework\MCP\Abilities;

use Saltus\WP\Framework\MCP\Tools\ToolInterface;
use Saltus\WP\Framework\MCP\Tools\ToolProvider;
use Saltus\WP\Framework\MCP\Validation\Validator;

class AbilityDefinitionFactory {

	/**
	 * @return list<array{name: lowercase-string&non-falsy-string, label: string, description: string, category: string, input_schema: array<string, mixed>, inputSchema: array<string, mixed>, execute_callback: callable, permission_callback: callable, callback: callable, meta: array<string, mixed>}>
	 */
	public function from_tool_provider( ToolProvider $provider ): array {
		$definitions = [];

		foreach ( $provider->all() as $tool ) {
			$definitions[] = $this->from_tool( $tool );
		}

		return $definitions;
	}

	/**
	 * @return array{name: lowercase-string&non-falsy-string, label: string, description: string, category: string, input_schema: array<string, mixed>, inputSchema: array<string, mixed>, execute_callback: callable, permission_callback: callable, callback: callable, meta: array<string, mixed>}
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
				return $this->dispatch_tool_to_rest( $tool, $args );
			},
			'permission_callback' => [ $this, 'can_use_saltus_abilities' ],
			'callback'            => function ( array $args = [] ) use ( $tool ) {
				return $this->dispatch_tool_to_rest( $tool, $args );
			},
			'meta'                => [
				'mcp_tool'     => $tool->get_name(),
				'namespace'    => 'saltus-framework/v1',
				'transport'    => 'wordpress-rest',
				'show_in_rest' => true,
			],
		];
	}

	public function can_use_saltus_abilities(): bool {
		return function_exists( 'current_user_can' ) && current_user_can( 'edit_posts' );
	}

	/**
	 * @return lowercase-string&non-falsy-string
	 */
	private function ability_name( string $tool_name ): string {
		return strtolower( 'saltus/' . str_replace( '_', '-', $tool_name ) );
	}

	private function label_from_tool_name( string $tool_name ): string {
		return ucwords( str_replace( '_', ' ', $tool_name ) );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	private function dispatch_tool_to_rest( ToolInterface $tool, array $args ): array|\WP_Error {
		$valid = Validator::validate( $args, $tool->get_parameters() );
		if ( ! $valid['valid'] ) {
			return $this->error( 'invalid_params', implode( '; ', $valid['errors'] ), 400 );
		}

		$request = $this->build_rest_request( $tool->get_name(), $args );
		if ( $request === null ) {
			return $this->error( 'unsupported_ability', 'This Saltus ability is registered for discovery only until a native dispatcher is available.', 501 );
		}

		if ( ! function_exists( 'rest_do_request' ) ) {
			return $this->error( 'rest_unavailable', 'WordPress REST dispatch is not available.', 501 );
		}

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		return is_array( $data ) ? $data : [ 'result' => $data ];
	}

	/**
	 * @param array<string, mixed> $args
	 */
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.MaxExceeded -- Tool-to-REST routing is intentionally centralized.
	private function build_rest_request( string $tool_name, array $args ): ?\WP_REST_Request {
		if ( ! class_exists( '\WP_REST_Request' ) ) {
			return null;
		}

		$method = 'GET';
		$route  = '';
		$body   = [];
		$query  = [];

		switch ( $tool_name ) {
			case 'list_models':
				$route = '/saltus-framework/v1/models';
				$query = $args;
				break;
			case 'get_model':
				$route = '/saltus-framework/v1/models/' . rawurlencode( (string) ( $args['slug'] ?? '' ) );
				break;
			case 'list_posts':
				$route = '/wp/v2/' . rawurlencode( $this->post_type_rest_base( (string) ( $args['post_type'] ?? 'posts' ) ) );
				$query = $this->only_args( $args, [ 'status', 'search', 'per_page', 'page', 'orderby', 'order' ] );
				$query = $this->append_term_filters( $query, $args['terms'] ?? [] );
				break;
			case 'get_post':
				$route = '/wp/v2/' . rawurlencode( $this->post_type_rest_base( (string) ( $args['post_type'] ?? 'posts' ) ) ) . '/' . (int) ( $args['post_id'] ?? 0 );
				break;
			case 'create_post':
				$method = 'POST';
				$route  = '/wp/v2/' . rawurlencode( $this->post_type_rest_base( (string) ( $args['post_type'] ?? 'posts' ) ) );
				$body   = $this->only_args( $args, [ 'title', 'content', 'excerpt', 'slug', 'status', 'meta' ] );
				$body   = $this->append_term_filters( $body, $args['terms'] ?? [] );
				break;
			case 'update_post':
				$method = 'PUT';
				$route  = '/wp/v2/' . rawurlencode( $this->post_type_rest_base( (string) ( $args['post_type'] ?? 'posts' ) ) ) . '/' . (int) ( $args['post_id'] ?? 0 );
				$body   = $this->only_args( $args, [ 'title', 'content', 'excerpt', 'slug', 'status', 'meta' ] );
				break;
			case 'delete_post':
				$method = 'DELETE';
				$route  = '/wp/v2/' . rawurlencode( $this->post_type_rest_base( (string) ( $args['post_type'] ?? 'posts' ) ) ) . '/' . (int) ( $args['post_id'] ?? 0 );
				$query  = [ 'force' => ! empty( $args['force'] ) ];
				break;
			case 'list_terms':
				$route = '/wp/v2/' . rawurlencode( $this->taxonomy_rest_base( (string) ( $args['taxonomy'] ?? 'categories' ) ) );
				$query = $this->only_args( $args, [ 'per_page', 'search', 'hide_empty' ] );
				break;
			case 'create_term':
				$method = 'POST';
				$route  = '/wp/v2/' . rawurlencode( $this->taxonomy_rest_base( (string) ( $args['taxonomy'] ?? '' ) ) );
				$body   = $this->only_args( $args, [ 'name', 'slug', 'description', 'parent' ] );
				break;
			case 'duplicate_post':
				$method = 'POST';
				$route  = '/saltus-framework/v1/duplicate/' . (int) ( $args['post_id'] ?? 0 );
				break;
			case 'export_post':
				$route = '/saltus-framework/v1/export/' . (int) ( $args['post_id'] ?? 0 );
				break;
			case 'get_settings':
				$route = '/saltus-framework/v1/settings/' . rawurlencode( (string) ( $args['post_type'] ?? '' ) );
				break;
			case 'update_settings':
				$method = 'PUT';
				$route  = '/saltus-framework/v1/settings/' . rawurlencode( (string) ( $args['post_type'] ?? '' ) );
				$body   = is_array( $args['settings'] ?? null ) ? $args['settings'] : [];
				break;
			case 'reorder_posts':
				$method = 'POST';
				$route  = '/saltus-framework/v1/reorder';
				$body   = [ 'items' => $args['items'] ?? [] ];
				break;
			case 'list_meta_fields':
				$route = '/saltus-framework/v1/meta';
				break;
			case 'get_meta_fields':
				$route = '/saltus-framework/v1/meta/' . rawurlencode( (string) ( $args['post_type'] ?? '' ) );
				break;
			default:
				return null;
		}

		$request = new \WP_REST_Request( $method, $route );
		foreach ( $query as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$request->set_body_params( $body );

		return $request;
	}

	/**
	 * @param array<string, mixed> $args
	 * @param list<string> $keys
	 * @return array<string, mixed>
	 */
	private function only_args( array $args, array $keys ): array {
		$filtered = [];
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $args ) ) {
				$filtered[ $key ] = $args[ $key ];
			}
		}

		return $filtered;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param mixed $terms
	 * @return array<string, mixed>
	 */
	private function append_term_filters( array $data, mixed $terms ): array {
		if ( ! is_array( $terms ) ) {
			return $data;
		}

		foreach ( $terms as $taxonomy => $term_ids ) {
			if ( ! is_string( $taxonomy ) || ! is_array( $term_ids ) ) {
				continue;
			}

			$ids = array_values( array_filter( array_map( 'intval', $term_ids ) ) );
			if ( $ids === [] ) {
				continue;
			}

			$data[ $this->taxonomy_rest_base( $taxonomy ) ] = $ids;
		}

		return $data;
	}

	private function post_type_rest_base( string $post_type ): string {
		if ( in_array( $post_type, [ 'posts', 'pages', 'media', 'users' ], true ) ) {
			return $post_type;
		}

		if ( function_exists( 'get_post_type_object' ) ) {
			$rest_object = get_post_type_object( $post_type );
			$rest_base   = $this->object_rest_base( $rest_object );
			if ( $rest_base !== null ) {
				return $rest_base;
			}
		}

		return $post_type;
	}

	private function taxonomy_rest_base( string $taxonomy ): string {
		if ( function_exists( 'get_taxonomy' ) ) {
			$rest_object = get_taxonomy( $taxonomy );
			$rest_base   = $this->object_rest_base( $rest_object );
			if ( $rest_base !== null ) {
				return $rest_base;
			}
		}

		return $taxonomy;
	}

	private function object_rest_base( mixed $rest_object ): ?string {
		if ( ! is_object( $rest_object ) || ! property_exists( $rest_object, 'rest_base' ) ) {
			return null;
		}

		$rest_base = $rest_object->rest_base;

		return is_string( $rest_base ) && $rest_base !== '' ? $rest_base : null;
	}

	private function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
