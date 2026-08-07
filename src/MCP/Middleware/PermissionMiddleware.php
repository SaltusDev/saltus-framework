<?php
namespace Saltus\WP\Framework\MCP\Middleware;

use Saltus\WP\Framework\Rest\CapabilityPolicy;

/**
 * Middleware that resolves and checks required capabilities from route metadata.
 *
 * For REST requests: maps the route to a CapabilityPolicy capability key
 * and checks current_user_can() against the resolved post-type capability.
 * For MCP tools: resolves capability from tool metadata or tool's own
 * permission check.
 */
class PermissionMiddleware implements MiddlewareInterface {

	private CapabilityPolicy $policy;

	public function __construct( CapabilityPolicy $policy ) {
		$this->policy = $policy;
	}

	/**
	 * @param RequestContext $context
	 * @param callable $next
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( RequestContext $context, callable $next ) {
		$tool_meta = $context->get_tool_metadata();

		if ( ! empty( $tool_meta ) ) {
			return $this->handle_tool( $context, $next, $tool_meta );
		}

		return $this->handle_rest( $context, $next );
	}

	/**
	 * Handle permission check for MCP tool execution.
	 *
	 * @param RequestContext $context
	 * @param callable $next
	 * @param array<string, mixed> $tool_meta
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function handle_tool( RequestContext $context, callable $next, array $tool_meta ) {
		$has_permission = $tool_meta['has_permission'] ?? null;

		if ( is_callable( $has_permission ) ) {
			$allowed = $has_permission( $context->get_args() );
			if ( ! $allowed ) {
				return \Saltus\WP\Framework\MCP\Error\ErrorResponse::forbidden(
					$tool_meta['capability'] ?? 'edit_posts',
					\__( 'You do not have permission to use this tool.', 'saltus-framework' )
				);
			}
		}

		return $next( $context );
	}

	/**
	 * Handle permission check for REST API requests.
	 *
	 * @param RequestContext $context
	 * @param callable $next
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function handle_rest( RequestContext $context, callable $next ) {
		$request = $context->get_rest_request();
		if ( $request === null ) {
			return $next( $context );
		}

		$route      = $request->get_route();
		$capability = $this->resolve_capability( $route );

		if ( $capability === null ) {
			return $next( $context );
		}

		if ( ! \function_exists( 'current_user_can' ) ) {
			return \Saltus\WP\Framework\MCP\Error\ErrorResponse::internal_error(
				\__( 'WordPress capability system is unavailable.', 'saltus-framework' )
			);
		}

		if ( ! \current_user_can( 'edit_posts' ) ) {
			return \Saltus\WP\Framework\MCP\Error\ErrorResponse::forbidden(
				'edit_posts',
				\__( 'You must have edit_posts capability to access Saltus API.', 'saltus-framework' )
			);
		}

		$model_name = $request->get_param( 'post_type' );
		if ( is_string( $model_name ) && $model_name !== '' ) {
			$cap = $this->resolve_model_capability( $model_name );
			if ( ! \current_user_can( $cap ) ) {
				return \Saltus\WP\Framework\MCP\Error\ErrorResponse::forbidden(
					$cap,
					\sprintf(
						/* translators: %s: model name */
						\__( "You do not have permission to access model '%s'.", 'saltus-framework' ),
						$model_name
					)
				);
			}
		}

		$model_name = $request->get_param( 'id' );
		if ( is_string( $model_name ) && $model_name !== '' ) {
			$post = \get_post( (int) $model_name );
			if ( $post && ! \current_user_can( 'edit_post', $post->ID ) ) {
				return \Saltus\WP\Framework\MCP\Error\ErrorResponse::forbidden(
					'edit_post',
					\__( 'You do not have permission to edit this post.', 'saltus-framework' )
				);
			}
		}

		return $next( $context );
	}

	/**
	 * Resolve the capability key from a REST route.
	 *
	 * @param string $route
	 * @return string|null
	 */
	private function resolve_capability( string $route ): ?string {
		$route_map = [
			'models'    => CapabilityPolicy::CAPABILITY_MODELS,
			'meta'      => CapabilityPolicy::CAPABILITY_META,
			'settings'  => CapabilityPolicy::CAPABILITY_SETTINGS,
			'duplicate' => CapabilityPolicy::CAPABILITY_DUPLICATE,
			'export'    => CapabilityPolicy::CAPABILITY_EXPORT,
			'reorder'   => CapabilityPolicy::CAPABILITY_REORDER,
			'health'    => CapabilityPolicy::CAPABILITY_HEALTH,
		];

		$parts = explode( '/', trim( $route, '/' ) );
		$path  = $parts[2] ?? '';

		return $route_map[ $path ] ?? null;
	}

	/**
	 * Resolve the WordPress edit capability for a model (post type or taxonomy).
	 *
	 * @param string $model_name
	 * @return string
	 */
	private function resolve_model_capability( string $model_name ): string {
		$model = $this->policy->get_model( $model_name );
		if ( $model === null ) {
			return 'edit_posts';
		}

		if ( $model->get_type() === 'taxonomy' ) {
			return $this->taxonomy_edit_capability( $model_name );
		}

		return $this->post_type_edit_capability( $model_name );
	}

	/**
	 * @param string $post_type
	 * @return string
	 */
	private function post_type_edit_capability( string $post_type ): string {
		if ( ! \function_exists( 'get_post_type_object' ) ) {
			return 'edit_posts';
		}

		$post_type_object = \get_post_type_object( $post_type );
		if ( is_object( $post_type_object ) && isset( $post_type_object->cap->edit_posts ) && is_string( $post_type_object->cap->edit_posts ) ) {
			return $post_type_object->cap->edit_posts;
		}

		return 'edit_posts';
	}

	/**
	 * @param string $taxonomy
	 * @return string
	 */
	private function taxonomy_edit_capability( string $taxonomy ): string {
		if ( ! \function_exists( 'get_taxonomy' ) ) {
			return 'manage_categories';
		}

		$taxonomy_object = \get_taxonomy( $taxonomy );
		if ( is_object( $taxonomy_object ) && isset( $taxonomy_object->cap->manage_terms ) && is_string( $taxonomy_object->cap->manage_terms ) ) {
			return $taxonomy_object->cap->manage_terms;
		}

		return 'manage_categories';
	}
}
