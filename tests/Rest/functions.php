<?php

/**
 * Minimal WordPress function/class stubs for REST controller tests.
 * Only loaded when Rest test suite runs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

if ( ! defined( 'WXR_VERSION' ) ) {
	define( 'WXR_VERSION', '1.2' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private array $errors = [];

		public function __construct( string $code = '', string $message = '', array $data = [] ) {
			if ( $code !== '' ) {
				$this->errors[ $code ] = [
					'message' => $message,
					'data'    => $data,
				];
			}
		}

		public function get_error_code(): ?string {
			$keys = array_keys( $this->errors );
			return $keys[0] ?? null;
		}

		public function get_error_message(): string {
			$code = $this->get_error_code();
			return $code ? ( $this->errors[ $code ]['message'] ?? '' ) : '';
		}

		public function get_error_data( string $key = '' ) {
			$code = $this->get_error_code();
			return $code ? ( $this->errors[ $code ]['data'] ?? [] ) : [];
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private mixed $data;

		public function __construct( mixed $data = [], int $status = 200 ) {
			$this->data = $data;
		}

		public function get_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		public const READABLE   = 'GET';
		public const CREATABLE  = 'POST';
		public const EDITABLE   = 'PUT';
		public const DELETABLE  = 'DELETE';
		public const ALLMETHODS = 'GET,POST,PUT,PATCH,DELETE';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $params      = [];
		private array $json_params = [];
		private string $method     = 'GET';
		private string $route      = '';

		public function __construct( array|string $method_or_params = [], string $route = '' ) {
			if ( is_string( $method_or_params ) ) {
				$this->method = $method_or_params;
				$this->route  = $route;
				return;
			}

			$params = $method_or_params;
			$this->params = $params;
		}

		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}

		public function get_params(): array {
			return $this->params;
		}

		public function set_param( string $key, mixed $value ): void {
			$this->params[ $key ] = $value;
		}

		public function set_json_params( array $params ): void {
			$this->json_params = $params;
		}

		public function get_json_params(): array {
			return $this->json_params;
		}

		public function set_body_params( array $params ): void {
			$this->json_params = $params;
		}

		public function get_method(): string {
			return $this->method;
		}

		public function get_route(): string {
			return $this->route;
		}
	}
}

if ( ! class_exists( 'WP_REST_Controller' ) ) {
	class WP_REST_Controller {
		protected string $namespace = '';
		protected string $rest_base = '';

		public function get_endpoint_args_for_item_schema( string $method = 'GET' ): array {
			return [];
		}

		public function get_item_schema(): array {
			return [];
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID            = 0;
		public string $post_type  = 'post';
		public string $post_title = '';
		public string $post_status = 'draft';
		public string $post_content = '';
		public int $post_author   = 0;
		public string $post_name  = '';
		public int $post_parent   = 0;
		public string $post_excerpt = '';
		public string $post_password = '';
		public string $comment_status = 'open';
		public string $ping_status = 'open';
		public int $menu_order    = 0;
		public string $to_ping    = '';

		public function __construct( array $properties = [] ) {
			foreach ( $properties as $key => $value ) {
				if ( property_exists( $this, $key ) ) {
					$this->$key = $value;
				}
			}
		}
	}
}

$wp_rest_routes_registered = [];
$wp_abilities_registered   = [];
$wp_rest_request_log       = [];
$wp_current_user_can       = true;
$wp_posts                  = [];
$wp_options                = [];

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args = [], bool $override = false ): void {
		global $wp_rest_routes_registered;
		$wp_rest_routes_registered[] = compact( 'namespace', 'route', 'args', 'override' );
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( string $name, array $args ): void {
		global $wp_abilities_registered;
		$wp_abilities_registered[ $name ] = $args;
	}
}

if ( ! function_exists( 'rest_do_request' ) ) {
	function rest_do_request( WP_REST_Request $request ): WP_REST_Response {
		global $wp_rest_request_log;
		$wp_rest_request_log[] = [
			'method' => $request->get_method(),
			'route'  => $request->get_route(),
			'params' => $request->get_json_params(),
			'query'  => $request->get_params(),
		];
		return new WP_REST_Response( [ 'ok' => true, 'route' => $request->get_route() ] );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability, mixed ...$args ): bool {
		global $wp_current_user_can;
		if ( is_bool( $wp_current_user_can ) ) {
			return $wp_current_user_can;
		}
		return true;
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( ?int $post_id = null ): ?WP_Post {
		global $wp_posts;
		if ( $post_id === null || $post_id === 0 ) {
			return null;
		}
		return $wp_posts[ $post_id ] ?? null;
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( mixed $value ): WP_REST_Response|WP_Error {
		if ( $value instanceof WP_REST_Response || $value instanceof WP_Error ) {
			return $value;
		}
		return new WP_REST_Response( $value );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '', string $scheme = 'admin' ): string {
		return 'http://example.com/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, mixed $value, mixed ...$args ): mixed {
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $tag, mixed ...$args ): void {}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		global $wp_actions_registered;
		$wp_actions_registered[] = compact( 'hook_name', 'callback', 'priority', 'accepted_args' );
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( string $tag ): bool {
		return false;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return false;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $default = false ): mixed {
		global $wp_options;
		return $wp_options[ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, mixed $value, mixed $autoload = null ): bool {
		global $wp_options;
		$wp_options[ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return trim( $str );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			return stripslashes( $value );
		}
		return $value;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( array $post_data, bool $wp_error = false ): int|WP_Error {
		global $wp_posts;
		$id = $post_data['ID'] ?? 0;
		if ( ! isset( $wp_posts[ $id ] ) ) {
			return $wp_error ? new WP_Error( 'not_found', 'Post not found.' ) : 0;
		}
		foreach ( $post_data as $key => $value ) {
			if ( $key !== 'ID' && property_exists( 'WP_Post', $key ) ) {
				$wp_posts[ $id ]->$key = $value;
			}
		}
		return $id;
	}
}

if ( ! function_exists( 'export_wp' ) ) {
	function export_wp( array $args = [] ): void {
		echo '<?xml version="1.0" encoding="UTF-8"?><!-- WXR export -->';
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 1;
	}
}

if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( array $args, bool $wp_error = false ): int|WP_Error {
		global $wp_posts;
		$new_id      = count( $wp_posts ) + 100;
		$post        = new WP_Post( $args );
		$post->ID    = $new_id;
		$wp_posts[ $new_id ] = $post;
		return $new_id;
	}
}

if ( ! function_exists( 'register_taxonomy' ) ) {
	function register_taxonomy( string $taxonomy, array|string $object_type, array $args = [] ): void {
		global $wp_taxonomies_registered;
		$wp_taxonomies_registered[ $taxonomy ] = compact( 'taxonomy', 'object_type', 'args' );
	}
}

if ( ! function_exists( 'register_taxonomy_for_object_type' ) ) {
	function register_taxonomy_for_object_type( string $taxonomy, string $object_type ): bool {
		return true;
	}
}

if ( ! function_exists( 'get_object_taxonomies' ) ) {
	function get_object_taxonomies( string|array|WP_Post $object, string $output = 'names' ): array {
		return [];
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		return $single ? '' : [];
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $meta_key, mixed $meta_value, mixed $prev_value = '' ): bool {
		return true;
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( ?int $post_id = null ): string|false {
		global $wp_posts;
		if ( $post_id && isset( $wp_posts[ $post_id ] ) ) {
			return $wp_posts[ $post_id ]->post_type;
		}
		return false;
	}
}

if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( string $post_type ): ?stdClass {
		$cap = new stdClass();
		$cap->edit_posts = 'edit_posts';
		return (object) [
			'name' => $post_type,
			'cap'  => $cap,
		];
	}
}

if ( ! function_exists( 'get_taxonomy' ) ) {
	function get_taxonomy( string $taxonomy ): ?stdClass {
		global $wp_taxonomy_objects;
		if ( isset( $wp_taxonomy_objects[ $taxonomy ] ) ) {
			return $wp_taxonomy_objects[ $taxonomy ];
		}

		return (object) [
			'name'      => $taxonomy,
			'rest_base' => $taxonomy,
		];
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $path ): string {
		return rtrim( $path, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( string $path = '', string $plugin = '' ): string {
		return 'http://example.com/wp-content/plugins/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( string $location, int $status = 302 ): void {
		// no-op
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( array|string $key, mixed $value = false, string $url = '' ): string {
		if ( is_array( $key ) ) {
			return $url . '?' . http_build_query( $key );
		}
		return $url . '?' . $key . '=' . $value;
	}
}
