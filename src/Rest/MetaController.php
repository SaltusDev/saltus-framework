<?php

namespace Saltus\WP\Framework\Rest;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Saltus\WP\Framework\Features\Meta\MetaFieldProvider;
use Saltus\WP\Framework\Modeler;

/**
 * REST controller exposing meta field configuration per post type.
 */
class MetaController extends WP_REST_Controller {

	private const ROUTE_NAMESPACE = 'saltus-framework/v1';

	protected Modeler $modeler;
	private ?ModelRestPolicy $policy;
	private MetaFieldProvider $meta_field_provider;

	/**
	 * @param Modeler $modeler  The model registry.
	 * @param ModelRestPolicy|null $policy  Optional REST policy for capability gating.
	 * @param MetaFieldProvider|null $meta_field_provider Optional meta field provider.
	 */
	public function __construct( Modeler $modeler, ?ModelRestPolicy $policy = null, ?MetaFieldProvider $meta_field_provider = null ) {
		$this->modeler             = $modeler;
		$this->policy              = $policy;
		$this->meta_field_provider = $meta_field_provider ?? new MetaFieldProvider();
		$this->namespace           = self::ROUTE_NAMESPACE;
		$this->rest_base           = 'meta';
	}

	/**
	 * Register the REST routes for listing and reading meta fields.
	 */
	public function register_routes(): void {
		if ( $this->namespace === '' ) {
			return;
		}

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/' . $this->rest_base,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_all_items' ],
				'permission_callback' => [ $this, 'get_items_permissions_check' ],
			]
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/' . $this->rest_base . '/(?P<post_type>[a-z0-9_-]+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => [ $this, 'get_items_permissions_check' ],
				'args'                => [
					'post_type' => [
						'type'        => 'string',
						'required'    => true,
						'description' => 'Post type slug to get meta fields for',
					],
				],
			]
		);
	}

	/**
	 * Check whether the current user can view meta fields.
	 *
	 * @param mixed $request  The REST request.
	 * @return WP_Error|bool
	 */
	public function get_items_permissions_check( $request ) {
		$post_type = is_object( $request ) && method_exists( $request, 'get_param' ) ? $request->get_param( 'post_type' ) : null;
		$allowed   = is_string( $post_type ) && $post_type !== ''
			? $this->can_view_post_type_meta( $post_type )
			: $this->can_view_any_post_type_meta();

		if ( ! $allowed ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view meta fields.', 'saltus-framework' ),
				[
					'status' => 403,
					'hint'   => __( "Assign edit_posts to your user, or ensure the model has 'saltus_rest' => [ 'capabilities' => [ 'meta' => true ] ] in its config.", 'saltus-framework' ),
				]
			);
		}
		return true;
	}

	/**
	 * Get meta field definitions for all post types.
	 *
	 * @param WP_REST_Request $request  The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_all_items( WP_REST_Request $request ) {
		$post_types = $this->meta_field_provider->all_post_type_meta(
			$this->modeler,
			$this->policy,
			fn( string $post_type ): bool => $this->can_view_post_type_meta( $post_type )
		);

		return rest_ensure_response(
			[
				'post_types' => $post_types,
			]
		);
	}

	/**
	 * Get meta field definitions for a specific post type.
	 *
	 * @param mixed $request  The REST request containing the post_type parameter.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$post_type = $request->get_param( 'post_type' );

		return rest_ensure_response( $this->meta_field_provider->post_type_meta( $this->modeler, $this->policy, (string) $post_type ) );
	}

	/**
	 * Check whether the current user can view meta for any enabled post type.
	 *
	 * @return bool
	 */
	private function can_view_any_post_type_meta(): bool {
		if ( function_exists( 'current_user_can' ) && current_user_can( 'edit_posts' ) ) {
			return true;
		}

		$models = $this->policy
			? $this->policy->get_enabled_models( ModelRestPolicy::CAPABILITY_META, 'post_type' )
			: $this->modeler->get_models();

		foreach ( $models as $post_type => $model ) {
			if ( $model->get_type() !== 'post_type' ) {
				continue;
			}

			if ( $this->can_view_post_type_meta( (string) $post_type ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether the current user can view meta for a post type.
	 *
	 * @param string $post_type  Post type slug.
	 * @return bool
	 */
	private function can_view_post_type_meta( string $post_type ): bool {
		if ( ! function_exists( 'current_user_can' ) ) {
			return false;
		}

		return current_user_can( $this->post_type_edit_capability( $post_type ) );
	}

	/**
	 * Resolve the edit capability for a post type.
	 *
	 * @param string $post_type  Post type slug.
	 * @return string
	 */
	private function post_type_edit_capability( string $post_type ): string {
		if ( ! function_exists( 'get_post_type_object' ) ) {
			return 'edit_posts';
		}

		$post_type_object = get_post_type_object( $post_type );
		if ( is_object( $post_type_object ) && isset( $post_type_object->cap->edit_posts ) && is_string( $post_type_object->cap->edit_posts ) ) {
			return $post_type_object->cap->edit_posts;
		}

		return 'edit_posts';
	}
}
