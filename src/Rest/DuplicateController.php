<?php

namespace Saltus\WP\Framework\Rest;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Saltus\WP\Framework\Features\Duplicate\SaltusDuplicate;

class DuplicateController extends WP_REST_Controller {

	public function __construct() {
		$this->namespace = 'saltus-framework/v1';
		$this->rest_base = 'duplicate';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
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
