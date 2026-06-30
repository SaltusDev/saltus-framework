<?php
namespace Saltus\WP\Framework\MCP\Abilities;

use Saltus\WP\Framework\MCP\Tools\ToolInterface;
use Saltus\WP\Framework\MCP\Tools\ToolProvider;
use Saltus\WP\Framework\MCP\Validation\Validator;

class AbilityDefinitionFactory {

	/**
	 * @return list<array{name: lowercase-string&non-falsy-string, label: string, description: string, category: string, input_schema: array<string, mixed>, inputSchema: array<string, mixed>, execute_callback: callable, permission_callback: callable, callback: callable, meta: array<string, mixed>}>
	 */
	public function fromToolProvider( ToolProvider $provider ): array {
		$definitions = [];

		foreach ( $provider->all() as $tool ) {
			$definitions[] = $this->fromTool( $tool );
		}

		return $definitions;
	}

	/**
	 * @return array{name: lowercase-string&non-falsy-string, label: string, description: string, category: string, input_schema: array<string, mixed>, inputSchema: array<string, mixed>, execute_callback: callable, permission_callback: callable, callback: callable, meta: array<string, mixed>}
	 */
	public function fromTool( ToolInterface $tool ): array {
		$schema = $tool->getParameters();

		return [
			'name'                => $this->abilityName( $tool->getName() ),
			'label'               => $this->labelFromToolName( $tool->getName() ),
			'description'         => $tool->getDescription(),
			'category'            => 'saltus-framework',
			'input_schema'        => $schema,
			'inputSchema'         => $schema,
			'execute_callback'    => function ( array $args = [] ) use ( $tool ) {
				return $this->dispatchToolToRest( $tool, $args );
			},
			'permission_callback' => [ $this, 'canUseSaltusAbilities' ],
			'callback'            => function ( array $args = [] ) use ( $tool ) {
				return $this->dispatchToolToRest( $tool, $args );
			},
			'meta'                => [
				'mcp_tool'  => $tool->getName(),
				'namespace' => 'saltus-framework/v1',
				'transport' => 'wordpress-rest',
				'show_in_rest' => true,
			],
		];
	}

	public function canUseSaltusAbilities(): bool {
		return function_exists( 'current_user_can' ) && current_user_can( 'edit_posts' );
	}

	/**
	 * @return lowercase-string&non-falsy-string
	 */
	private function abilityName( string $toolName ): string {
		return strtolower( 'saltus/' . str_replace( '_', '-', $toolName ) );
	}

	private function labelFromToolName( string $toolName ): string {
		return ucwords( str_replace( '_', ' ', $toolName ) );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	private function dispatchToolToRest( ToolInterface $tool, array $args ): array|\WP_Error {
		$valid = Validator::validate( $args, $tool->getParameters() );
		if ( ! $valid['valid'] ) {
			return $this->error( 'invalid_params', implode( '; ', $valid['errors'] ), 400 );
		}

		$request = $this->buildRestRequest( $tool->getName(), $args );
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
	private function buildRestRequest( string $toolName, array $args ): ?\WP_REST_Request {
		if ( ! class_exists( '\WP_REST_Request' ) ) {
			return null;
		}

		$method = 'GET';
		$route  = '';
		$body   = [];
		$query  = [];

		switch ( $toolName ) {
			case 'list_models':
				$route = '/saltus-framework/v1/models';
				$query = $args;
				break;
			case 'get_model':
				$route = '/saltus-framework/v1/models/' . rawurlencode( (string) ( $args['slug'] ?? '' ) );
				break;
			case 'list_posts':
				$route = '/wp/v2/' . rawurlencode( $this->postTypeRestBase( (string) ( $args['post_type'] ?? 'posts' ) ) );
				$query = $this->onlyArgs( $args, [ 'status', 'search', 'per_page', 'page', 'orderby', 'order' ] );
				$query = $this->appendTermFilters( $query, $args['terms'] ?? [] );
				break;
			case 'get_post':
				$route = '/wp/v2/' . rawurlencode( $this->postTypeRestBase( (string) ( $args['post_type'] ?? 'posts' ) ) ) . '/' . (int) ( $args['post_id'] ?? 0 );
				break;
			case 'create_post':
				$method = 'POST';
				$route  = '/wp/v2/' . rawurlencode( $this->postTypeRestBase( (string) ( $args['post_type'] ?? 'posts' ) ) );
				$body   = $this->onlyArgs( $args, [ 'title', 'content', 'excerpt', 'slug', 'status', 'meta' ] );
				$body   = $this->appendTermFilters( $body, $args['terms'] ?? [] );
				break;
			case 'update_post':
				$method = 'PUT';
				$route  = '/wp/v2/' . rawurlencode( $this->postTypeRestBase( (string) ( $args['post_type'] ?? 'posts' ) ) ) . '/' . (int) ( $args['post_id'] ?? 0 );
				$body   = $this->onlyArgs( $args, [ 'title', 'content', 'excerpt', 'slug', 'status', 'meta' ] );
				break;
			case 'delete_post':
				$method = 'DELETE';
				$route  = '/wp/v2/' . rawurlencode( $this->postTypeRestBase( (string) ( $args['post_type'] ?? 'posts' ) ) ) . '/' . (int) ( $args['post_id'] ?? 0 );
				$query  = [ 'force' => ! empty( $args['force'] ) ];
				break;
			case 'list_terms':
				$route = '/wp/v2/' . rawurlencode( $this->taxonomyRestBase( (string) ( $args['taxonomy'] ?? 'categories' ) ) );
				$query = $this->onlyArgs( $args, [ 'per_page', 'search', 'hide_empty' ] );
				break;
			case 'create_term':
				$method = 'POST';
				$route  = '/wp/v2/' . rawurlencode( $this->taxonomyRestBase( (string) ( $args['taxonomy'] ?? '' ) ) );
				$body   = $this->onlyArgs( $args, [ 'name', 'slug', 'description', 'parent' ] );
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
	private function onlyArgs( array $args, array $keys ): array {
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
	private function appendTermFilters( array $data, mixed $terms ): array {
		if ( ! is_array( $terms ) ) {
			return $data;
		}

		foreach ( $terms as $taxonomy => $termIds ) {
			if ( ! is_string( $taxonomy ) || ! is_array( $termIds ) ) {
				continue;
			}

			$ids = array_values( array_filter( array_map( 'intval', $termIds ) ) );
			if ( $ids === [] ) {
				continue;
			}

			$data[ $this->taxonomyRestBase( $taxonomy ) ] = $ids;
		}

		return $data;
	}

	private function postTypeRestBase( string $postType ): string {
		if ( in_array( $postType, [ 'posts', 'pages', 'media', 'users' ], true ) ) {
			return $postType;
		}

		if ( function_exists( 'get_post_type_object' ) ) {
			$object = get_post_type_object( $postType );
			$restBase = $this->objectRestBase( $object );
			if ( $restBase !== null ) {
				return $restBase;
			}
		}

		return $postType;
	}

	private function taxonomyRestBase( string $taxonomy ): string {
		if ( function_exists( 'get_taxonomy' ) ) {
			$object = get_taxonomy( $taxonomy );
			$restBase = $this->objectRestBase( $object );
			if ( $restBase !== null ) {
				return $restBase;
			}
		}

		return $taxonomy;
	}

	private function objectRestBase( mixed $object ): ?string {
		if ( ! is_object( $object ) || ! property_exists( $object, 'rest_base' ) ) {
			return null;
		}

		$restBase = $object->rest_base;

		return is_string( $restBase ) && $restBase !== '' ? $restBase : null;
	}

	private function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
