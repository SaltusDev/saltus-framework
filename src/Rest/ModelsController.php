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

	private const ROUTE_NAMESPACE = 'saltus-framework/v1';

	protected Modeler $modeler;

	public function __construct( Modeler $modeler ) {
		$this->modeler   = $modeler;
		$this->namespace = self::ROUTE_NAMESPACE;
		$this->rest_base = 'models';
	}

	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/' . $this->rest_base,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => [ $this, 'get_items_permissions_check' ],
			]
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
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
			'name'           => $this->check_method( $model, 'get_registration_name', 'name', '' ),
			'type'           => $model->get_type(),
			'label_singular' => $this->check_method( $model, 'get_label_singular', 'one', '' ),
			'label_plural'   => $this->check_method( $model, 'get_label_plural', 'many', '' ),
			'featured_image' => $this->check_method( $model, 'get_featured_image_label', 'featured_image', '' ),
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
	/**
	 *
	 * Check method or prop in class, then default in case none found.
	 *
	 * @param object $target
	 * @param string $method
	 * @param string $default_prop
	 * @param string $default_val
	 * @return mixed
	 */
	private function check_method( object $target, string $method, string $default_prop, string $default_val ) {
		if ( method_exists( $target, $method ) ) {
			return $target->{$method}();
		}

		return property_exists( $target, $default_prop ) ? $target->{$default_prop} : $default_val;
	}
}
