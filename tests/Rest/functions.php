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
$wp_is_admin               = false;
$wp_filters_registered     = [];
$wp_filter_values          = [];
$wp_scripts_enqueued       = [];
$wp_styles_enqueued        = [];
$wp_scripts_localized      = [];
$wp_nonce_valid            = true;
$wp_meta_updates           = [];
$wp_post_type_objects      = [];
$wp_posts                  = [];
$wp_post_meta              = [];
$wp_options                = [];
$wp_transients             = [];
$wp_activation_hooks       = [];
$wp_deactivation_hooks     = [];
$wpdb                      = new class implements \Saltus\WP\Framework\MCP\Audit\AuditDatabase {
	public string $prefix = 'wp_';
	public string $posts = 'wp_posts';
	/** @var list<array<string, mixed>> */
	public array $inserts = [];
	/** @var list<string> */
	public array $queries = [];

	public function prefix(): string {
		return $this->prefix;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param list<string> $format
	 */
	public function insert( string $table, array $data, array $format = [] ): bool {
		$this->inserts[] = compact( 'table', 'data', 'format' );
		return true;
	}

	public function query( string $query ): bool {
		$this->queries[] = $query;
		return true;
	}

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[dsf]/', (string) $arg, $query, 1 );
		}
		return $query;
	}

	public function get_results( string $query, mixed $output = null ): array {
		return array_reverse( array_map( fn( array $insert ) => $insert['data'], $this->inserts ) );
	}

	public function get_charset_collate(): string {
		return '';
	}
};

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args = [], bool $override = false ): void {
		global $wp_rest_routes_registered;
		$wp_rest_routes_registered[] = compact( 'namespace', 'route', 'args', 'override' );
	}
}

if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( string $file, callable $callback ): void {
		global $wp_activation_hooks;
		$wp_activation_hooks[] = compact( 'file', 'callback' );
	}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( string $file, callable $callback ): void {
		global $wp_deactivation_hooks;
		$wp_deactivation_hooks[] = compact( 'file', 'callback' );
	}
}

if ( ! function_exists( 'flush_rewrite_rules' ) ) {
	function flush_rewrite_rules( bool $hard = true ): void {}
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
		if ( is_array( $wp_current_user_can ) ) {
			$key = $capability;
			if ( $args !== [] ) {
				$key .= ':' . implode( ':', array_map( 'strval', $args ) );
			}

			if ( array_key_exists( $key, $wp_current_user_can ) ) {
				return (bool) $wp_current_user_can[ $key ];
			}

			if ( array_key_exists( $capability, $wp_current_user_can ) ) {
				return (bool) $wp_current_user_can[ $capability ];
			}

			return false;
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
		global $wp_filters_registered, $wp_filter_values;
		$wp_filters_registered = is_array( $wp_filters_registered ) ? $wp_filters_registered : [];
		$wp_filter_values      = is_array( $wp_filter_values ) ? $wp_filter_values : [];
		if ( array_key_exists( $tag, $wp_filter_values ) ) {
			$filter = $wp_filter_values[ $tag ];
			return is_callable( $filter ) ? $filter( $value, ...$args ) : $filter;
		}
		foreach ( $wp_filters_registered[ $tag ] ?? [] as $filter ) {
			$value = $filter['callback']( $value, ...array_slice( $args, 0, $filter['accepted_args'] - 1 ) );
		}
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

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		global $wp_filters_registered;
		$wp_filters_registered = is_array( $wp_filters_registered ) ? $wp_filters_registered : [];
		$wp_filters_registered[ $hook_name ][] = compact( 'hook_name', 'callback', 'priority', 'accepted_args' );
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( string $tag ): bool {
		global $wp_filters_registered, $wp_filter_values;
		$wp_filters_registered = is_array( $wp_filters_registered ) ? $wp_filters_registered : [];
		$wp_filter_values      = is_array( $wp_filter_values ) ? $wp_filter_values : [];
		return ! empty( $wp_filters_registered[ $tag ] ) || array_key_exists( $tag, $wp_filter_values );
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		global $wp_is_admin;
		return (bool) $wp_is_admin;
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

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		global $wp_options;
		unset( $wp_options[ $option ] );
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $transient ): mixed {
		global $wp_transients;
		$value = $wp_transients[ $transient ] ?? null;
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( $value['expires'] !== 0 && microtime( true ) >= $value['expires'] ) {
			unset( $wp_transients[ $transient ] );
			return false;
		}
		return $value['value'];
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $transient, mixed $value, int $expiration = 0 ): bool {
		global $wp_transients;
		$wp_transients[ $transient ] = [
			'value'   => $value,
			'expires' => $expiration > 0 ? microtime( true ) + $expiration : 0,
		];
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $transient ): bool {
		global $wp_transients;
		unset( $wp_transients[ $transient ] );
		return true;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	function get_locale(): string {
		return 'en_US';
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
		global $wp_post_meta;
		if ( $key === '' ) {
			return $wp_post_meta[ $post_id ] ?? [];
		}
		if ( ! array_key_exists( $post_id, $wp_post_meta ) || ! array_key_exists( $key, $wp_post_meta[ $post_id ] ) ) {
			return $single ? '' : [];
		}
		if ( $single ) {
			return is_array( $wp_post_meta[ $post_id ][ $key ] ) ? ( $wp_post_meta[ $post_id ][ $key ][0] ?? '' ) : $wp_post_meta[ $post_id ][ $key ];
		}
		if ( is_array( $wp_post_meta[ $post_id ][ $key ] ) ) {
			return $wp_post_meta[ $post_id ][ $key ];
		}
		return [ $wp_post_meta[ $post_id ][ $key ] ];
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $meta_key, mixed $meta_value, mixed $prev_value = '' ): bool {
		global $wp_meta_updates, $wp_post_meta;
		$wp_meta_updates[] = compact( 'post_id', 'meta_key', 'meta_value', 'prev_value' );
		$wp_post_meta[ $post_id ][ $meta_key ] = [ $meta_value ];
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
		global $wp_post_type_objects;
		if ( isset( $wp_post_type_objects[ $post_type ] ) ) {
			return $wp_post_type_objects[ $post_type ];
		}

		$cap = new stdClass();
		$cap->edit_posts   = 'edit_posts';
		$cap->create_posts = 'edit_posts';
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

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public array $query = [];
		private array $vars = [];

		public function __construct( array $query = [] ) {
			$this->query = $query;
			$this->vars  = $query;
		}

		public function get( string $key ): mixed {
			return $this->vars[ $key ] ?? null;
		}

		public function set( string $key, mixed $value ): void {
			$this->vars[ $key ] = $value;
		}
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public int $term_id = 0;
		public string $name = '';
		public int $count = 0;

		public function __construct( array $properties = [] ) {
			foreach ( $properties as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( mixed $nonce, string $action = '' ): bool|int {
		global $wp_nonce_valid;
		return $wp_nonce_valid;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action = '-1' ): string {
		return 'nonce-' . $action;
	}
}

if ( ! function_exists( 'wp_nonce_url' ) ) {
	function wp_nonce_url( string $actionurl, string $action = '-1', string $name = '_wpnonce' ): string {
		return add_query_arg( $name, wp_create_nonce( $action ), $actionurl );
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( string $action = '-1', string $name = '_wpnonce', bool $referer = true, bool $display = true ): string {
		$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';
		if ( $display ) {
			echo $field;
		}
		return $field;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( mixed $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( mixed $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( mixed $text ): string {
		return addslashes( (string) $text );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( mixed $url ): string {
		return (string) $url;
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, bool $in_footer = false ): void {
		global $wp_scripts_enqueued;
		$wp_scripts_enqueued[] = compact( 'handle', 'src', 'deps', 'ver', 'in_footer' );
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, string $media = 'all' ): void {
		global $wp_styles_enqueued;
		$wp_styles_enqueued[] = compact( 'handle', 'src', 'deps', 'ver', 'media' );
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( string $handle, string $object_name, array $l10n ): bool {
		global $wp_scripts_localized;
		$wp_scripts_localized[] = compact( 'handle', 'object_name', 'l10n' );
		return true;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $maybeint ): int {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'wp_get_object_terms' ) ) {
	function wp_get_object_terms( int $object_id, string|array $taxonomies, array $args = [] ): array|WP_Error {
		return [];
	}
}

if ( ! function_exists( 'wp_set_object_terms' ) ) {
	function wp_set_object_terms( int $object_id, string|int|array $terms, string $taxonomy, bool $append = false ): array|WP_Error {
		return [];
	}
}
