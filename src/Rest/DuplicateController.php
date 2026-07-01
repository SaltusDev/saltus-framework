<?php

namespace Saltus\WP\Framework\Rest;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Saltus\WP\Framework\Features\Duplicate\SaltusDuplicate;

/**
 * REST controller for duplicating posts.
 */
class DuplicateController extends WP_REST_Controller {

	private const ROUTE_NAMESPACE = 'saltus-framework/v1';
	private ?ModelRestPolicy $policy;

	/**
	 * @param ModelRestPolicy|null $policy  Optional REST policy for capability gating.
	 */
	public function __construct( ?ModelRestPolicy $policy = null ) {
		$this->policy    = $policy;
		$this->namespace = self::ROUTE_NAMESPACE;
		$this->rest_base = 'duplicate';
	}

	/**
	 * Register the REST route for post duplication.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/' . $this->rest_base . '/(?P<post_id>\d+)',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => [ $this, 'create_item_permissions_check' ],
				'args'                => [
					'post_id' => [
						'type'        => 'integer',
						'required'    => true,
						'description' => 'ID of the post to duplicate',
					],
				],
			]
		);
	}

	/**
	 * Check whether the current user can duplicate posts.
	 *
	 * @param mixed $request  The REST request.
	 * @return WP_Error|true
	 */
	public function create_item_permissions_check( $request ): WP_Error|true {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to duplicate posts.', 'saltus-framework' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * Duplicate a post by ID.
	 *
	 * @param mixed $request  The REST request containing the post_id parameter.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'post_not_found',
				__( 'Post not found.', 'saltus-framework' ),
				[ 'status' => 404 ]
			);
		}

		if ( $this->policy && ! $this->policy->is_post_type_enabled( (string) $post->post_type, ModelRestPolicy::CAPABILITY_DUPLICATE ) ) {
			return new WP_Error(
				'model_rest_capability_disabled',
				__( 'Duplication is not enabled for this post type.', 'saltus-framework' ),
				[ 'status' => 403 ]
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to duplicate this post.', 'saltus-framework' ),
				[ 'status' => 403 ]
			);
		}

		$duplicator           = new SaltusDuplicate( $post->post_type, [] );
		$new_post_id_or_error = $duplicator->perform_duplication( $post_id );

		if ( is_wp_error( $new_post_id_or_error ) ) {
			return $new_post_id_or_error;
		}

		$new_post = get_post( $new_post_id_or_error );

		return rest_ensure_response(
			[
				'id'          => $new_post_id_or_error,
				'post_type'   => $new_post->post_type,
				'post_title'  => $new_post->post_title,
				'post_status' => $new_post->post_status,
				'edit_link'   => admin_url( 'post.php?action=edit&post=' . $new_post_id_or_error ),
			]
		);
	}
}
