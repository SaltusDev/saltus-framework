<?php

namespace Saltus\WP\Framework\Rest;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Saltus\WP\Framework\Modeler;

class MetaController extends WP_REST_Controller {

	protected Modeler $modeler;

	public function __construct( Modeler $modeler ) {
		$this->modeler   = $modeler;
		$this->namespace = 'saltus-framework/v1';
		$this->rest_base = 'meta';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
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

	public function get_items_permissions_check( $request ): WP_Error|true {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view meta fields.', 'saltus-framework' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	public function get_items( $request ): WP_REST_Response|WP_Error {
		$post_type = $request->get_param( 'post_type' );
		$models    = $this->modeler->get_models();

		if ( ! isset( $models[ $post_type ] ) ) {
			return new WP_Error(
				'model_not_found',
				__( 'Model not found.', 'saltus-framework' ),
				[ 'status' => 404 ]
			);
		}

		$model = $models[ $post_type ];

		if ( $model->get_type() !== 'post_type' ) {
			return new WP_Error(
				'invalid_model_type',
				__( 'Meta fields are only available for post type models.', 'saltus-framework' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! isset( $model->args['meta'] ) || empty( $model->args['meta'] ) ) {
			return rest_ensure_response(
				[
					'post_type' => $post_type,
					'meta'      => [],
				]
			);
		}

		return rest_ensure_response(
			[
				'post_type' => $post_type,
				'meta'      => $model->args['meta'],
			]
		);
	}
}
