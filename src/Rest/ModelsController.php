<?php

namespace Saltus\WP\Framework\Rest;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Taxonomy;

class ModelsController extends WP_REST_Controller {

	protected Modeler $modeler;

	public function __construct( Modeler $modeler ) {
		$this->modeler   = $modeler;
		$this->namespace = 'saltus-framework/v1';
		$this->rest_base = 'models';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => [ $this, 'get_items_permissions_check' ],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<post_type>[a-z0-9_-]+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => [ $this, 'get_item_permissions_check' ],
				'args'                => [
					'post_type' => [
						'type'        => 'string',
						'required'    => true,
						'description' => 'Model name (post type or taxonomy slug)',
					],
				],
			]
		);
	}

	public function get_items_permissions_check( $request ): true|WP_Error {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view models.', 'saltus-framework' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	public function get_item_permissions_check( $request ): true|WP_Error {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view models.', 'saltus-framework' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	public function get_items( $request ): WP_REST_Response|WP_Error {
		$models = $this->modeler->get_models();

		if ( empty( $models ) ) {
			return rest_ensure_response( [] );
		}

		$data = [];
		foreach ( $models as $name => $model ) {
			$data[] = $this->prepare_model_for_response( $model, $request );
		}

		return rest_ensure_response( $data );
	}

	public function get_item( $request ): WP_REST_Response|WP_Error {
		$models = $this->modeler->get_models();
		$name   = $request->get_param( 'post_type' );

		if ( ! isset( $models[ $name ] ) ) {
			return new WP_Error(
				'model_not_found',
				__( 'Model not found.', 'saltus-framework' ),
				[ 'status' => 404 ]
			);
		}

		return rest_ensure_response(
			$this->prepare_model_for_response( $models[ $name ], $request )
		);
	}

	/**
	 * @param \Saltus\WP\Framework\Models\Model $model
	 * @return array<string, mixed>
	 */
	private function prepare_model_for_response( $model, WP_REST_Request $request ): array {
		$options = method_exists( $model, 'get_options' ) ? $model->get_options() : ( $model->options ?? [] );

		$data = [
			'name'           => method_exists( $model, 'get_registration_name' ) ? $model->get_registration_name() : ( $model->name ?? '' ),
			'type'           => $model->get_type(),
			'label_singular' => method_exists( $model, 'get_label_singular' ) ? $model->get_label_singular() : ( $model->one ?? '' ),
			'label_plural'   => method_exists( $model, 'get_label_plural' ) ? $model->get_label_plural() : ( $model->many ?? '' ),
			'featured_image' => method_exists( $model, 'get_featured_image_label' ) ? $model->get_featured_image_label() : ( $model->featured_image ?? '' ),
			'description'    => $model->description ?? '',
			'is_public'      => $options['public'] ?? true,
			'show_in_rest'   => $options['show_in_rest'] ?? true,
			'rest_base'      => method_exists( $model, 'get_rest_base' ) ? $model->get_rest_base() : ( $options['rest_base'] ?? ( $model->name ?? '' ) ),
		];

		if ( $model instanceof Taxonomy ) {
			$data['associations'] = $model->get_associations();
			$data['hierarchical'] = $model->is_hierarchical();
		}

		return $data;
	}
}
