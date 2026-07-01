<?php

namespace Saltus\WP\Framework\MCP\Tools;

/**
 * Abstract base for MCP tools that dispatch via the WordPress REST API.
 */
abstract class RestTool implements RestBackedToolInterface {

	/**
	 * Get the capability requirement for this tool.
	 *
	 * @return RestCapabilityRequirement|null
	 */
	public function get_rest_capability(): ?RestCapabilityRequirement {
		return null;
	}

	/**
	 * Whether responses from this tool can be cached.
	 *
	 * @return bool
	 */
	public function is_cacheable(): bool {
		return false;
	}

	/**
	 * Cache time-to-live in seconds.
	 *
	 * @return int
	 */
	public function cache_ttl(): int {
		return 300;
	}

	/**
	 * Build and return a WP_REST_Request instance.
	 *
	 * @param string $method  HTTP method.
	 * @param string $route  REST route.
	 * @param array<string, mixed> $query  Query parameters.
	 * @param array<string, mixed> $body  Body parameters.
	 * @return \WP_REST_Request|null
	 */
	protected function request( string $method, string $route, array $query = [], array $body = [] ): ?\WP_REST_Request {
		if ( ! class_exists( '\WP_REST_Request' ) ) {
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
	 * Filter an arguments array to only the specified keys.
	 *
	 * @param array<string, mixed> $args  Source arguments.
	 * @param list<string> $keys  Keys to keep.
	 * @return array<string, mixed>
	 */
	protected function only_args( array $args, array $keys ): array {
		$filtered = [];
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $args ) ) {
				$filtered[ $key ] = $args[ $key ];
			}
		}

		return $filtered;
	}

	/**
	 * Append taxonomy term filters to a query parameter array.
	 *
	 * @param array<string, mixed> $data  Existing query parameters.
	 * @param mixed $terms  Taxonomy term data keyed by taxonomy.
	 * @return array<string, mixed>
	 */
	protected function append_term_filters( array $data, mixed $terms ): array {
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

	/**
	 * Resolve the REST API base path for a post type.
	 *
	 * @param string $post_type  Post type slug.
	 * @return string  REST base path.
	 */
	protected function post_type_rest_base( string $post_type ): string {
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

	/**
	 * Resolve the REST API base path for a taxonomy.
	 *
	 * @param string $taxonomy  Taxonomy slug.
	 * @return string  REST base path.
	 */
	protected function taxonomy_rest_base( string $taxonomy ): string {
		if ( function_exists( 'get_taxonomy' ) ) {
			$rest_object = get_taxonomy( $taxonomy );
			$rest_base   = $this->object_rest_base( $rest_object );
			if ( $rest_base !== null ) {
				return $rest_base;
			}
		}

		return $taxonomy;
	}

	/**
	 * Extract the rest_base property from a post type or taxonomy object.
	 *
	 * @param mixed $rest_object  Post type or taxonomy object.
	 * @return string|null  REST base, or null if unavailable.
	 */
	private function object_rest_base( mixed $rest_object ): ?string {
		if ( ! is_object( $rest_object ) || ! property_exists( $rest_object, 'rest_base' ) ) {
			return null;
		}

		$rest_base = $rest_object->rest_base;

		return is_string( $rest_base ) && $rest_base !== '' ? $rest_base : null;
	}
}
