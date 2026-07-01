<?php
namespace Saltus\WP\Framework\MCP\Abilities;

use Saltus\WP\Framework\MCP\Audit\AuditEntry;
use Saltus\WP\Framework\MCP\Audit\AuditLogger;
use Saltus\WP\Framework\MCP\Cache\TransientCache;
use Saltus\WP\Framework\MCP\RateLimiter\RateLimiter;
use Saltus\WP\Framework\MCP\Tools\ToolInterface;
use Saltus\WP\Framework\MCP\Validation\Validator;

class AbilityRuntime {

	private AuditLogger $audit_logger;
	private RateLimiter $rate_limiter;
	private TransientCache $cache;

	public function __construct(
		?AuditLogger $audit_logger = null,
		?RateLimiter $rate_limiter = null,
		?TransientCache $cache = null
	) {
		$this->audit_logger = $audit_logger ?? new AuditLogger();
		$this->rate_limiter = $rate_limiter ?? new RateLimiter();
		$this->cache        = $cache ?? new TransientCache();
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( ToolInterface $tool, array $args ): array|\WP_Error {
		$entry = new AuditEntry( $tool->get_name(), $args, $this->identifier() );

		$valid = Validator::validate( $args, $tool->get_parameters() );
		if ( ! $valid['valid'] ) {
			$error = $this->error( 'invalid_params', implode( '; ', $valid['errors'] ), 400 );
			$this->record_error( $entry, 'validation_error', $error );
			return $error;
		}

		$rate_limit = $this->rate_limiter->check( $this->identifier() );
		if ( ! $rate_limit->allowed ) {
			$error = $this->error(
				'rate_limited',
				'Rate limit exceeded.',
				429,
				[
					'retry_after' => $rate_limit->retry_after,
					'remaining'   => $rate_limit->remaining,
					'reset_at'    => $rate_limit->reset_at,
				]
			);
			$this->record_error( $entry, 'rate_limited', $error );
			return $error;
		}

		$request = $this->build_rest_request( $tool->get_name(), $args );
		if ( $request === null ) {
			$error = $this->error( 'unsupported_ability', 'This Saltus ability is registered for discovery only until a native dispatcher is available.', 501 );
			$this->record_error( $entry, 'error', $error );
			return $error;
		}

		if ( ! function_exists( 'rest_do_request' ) ) {
			$error = $this->error( 'rest_unavailable', 'WordPress REST dispatch is not available.', 501 );
			$this->record_error( $entry, 'error', $error );
			return $error;
		}

		$cache_key = $this->cache_key( $tool->get_name(), $args );
		if ( $this->is_cacheable( $tool->get_name() ) ) {
			$cached = $this->cache->get( $cache_key );
			if ( $cached !== null ) {
				$entry->complete( 'cache_hit' );
				$this->audit_logger->record( $entry );
				return $cached;
			}
		}

		try {
			$response = rest_do_request( $request );
			$data     = $response->get_data();
			$result   = is_array( $data ) ? $data : [ 'result' => $data ];

			if ( $this->is_cacheable( $tool->get_name() ) ) {
				$this->cache->set( $cache_key, $result, $this->cache_ttl( $tool->get_name() ) );
			} else {
				$this->cache->clear();
			}

			$entry->complete( 'success' );
			$this->audit_logger->record( $entry );

			return $result;
		} catch ( \Throwable $e ) {
			$error = $this->error( 'ability_exception', $e->getMessage(), 500 );
			$this->record_error( $entry, 'exception', $error );
			return $error;
		}
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

	/**
	 * @param array<string, mixed> $extra
	 */
	private function error( string $code, string $message, int $status, array $extra = [] ): \WP_Error {
		return new \WP_Error( $code, $message, array_merge( [ 'status' => $status ], $extra ) );
	}

	private function record_error( AuditEntry $entry, string $status, \WP_Error $error ): void {
		$entry->complete( $status, (string) $error->get_error_code(), $error->get_error_message() );
		$this->audit_logger->record( $entry );
	}

	private function identifier(): string {
		$identifier = function_exists( 'get_current_user_id' ) ? 'user:' . (int) get_current_user_id() : 'user:0';
		if ( $identifier === 'user:0' && isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$identifier = 'ip:' . hash( 'sha256', (string) $_SERVER['REMOTE_ADDR'] );
		}

		return (string) $this->filter( 'saltus/framework/mcp/rate_limit/identifier', $identifier );
	}

	/**
	 * @param array<string, mixed> $args
	 */
	private function cache_key( string $tool_name, array $args ): string {
		$payload = [
			'tool'   => $tool_name,
			'args'   => $args,
			'user'   => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'locale' => function_exists( 'get_locale' ) ? get_locale() : '',
		];

		return 'saltus_mcp_' . hash( 'sha256', $this->encode( $payload ) );
	}

	private function is_cacheable( string $tool_name ): bool {
		$cacheable = in_array(
			$tool_name,
			[ 'list_models', 'get_model', 'list_posts', 'get_post', 'list_terms', 'get_settings', 'list_meta_fields', 'get_meta_fields' ],
			true
		);

		return (bool) $this->filter( 'saltus/framework/mcp/cache/cacheable', $cacheable, $tool_name );
	}

	private function cache_ttl( string $tool_name ): int {
		$ttl = in_array( $tool_name, [ 'list_models', 'get_model', 'list_meta_fields', 'get_meta_fields' ], true ) ? 600 : 300;

		return (int) $this->filter( 'saltus/framework/mcp/cache/ttl', $ttl, $tool_name );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function encode( array $payload ): string {
		if ( \function_exists( 'wp_json_encode' ) ) {
			$encoded = \wp_json_encode( $payload );
			return \is_string( $encoded ) ? $encoded : '';
		}

		$encoded = \wp_json_encode( $payload );
		return \is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * @param non-empty-string $hook
	 */
	private function filter( string $hook, mixed $value, mixed ...$args ): mixed {
		if ( function_exists( 'apply_filters' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook names are internal constants passed through this helper.
			return apply_filters( $hook, $value, ...$args );
		}

		return $value;
	}
}
