<?php

namespace Saltus\WP\Framework\Rest;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Saltus\WP\Framework\Features\Meta\MetaFieldProvider;
use Saltus\WP\Framework\MCP\MCPConfig;
use Saltus\WP\Framework\Modeler;

/**
 * REST controller exposing meta field configuration per post type.
 */
class MetaController extends WP_REST_Controller {

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
		$this->namespace           = MCPConfig::get_namespace();
		$this->rest_base           = 'meta';
	}

	/**
	 * Register the REST routes for listing and reading meta fields.
	 */
	public function register_routes(): void {
		if ( $this->namespace === '' ) {
			return;
		}

		/** @var non-falsy-string $namespace */
		$namespace = $this->namespace;
		register_rest_route(
			$namespace,
			'/' . $this->rest_base,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_all_items' ],
				'permission_callback' => [ $this, 'get_items_permissions_check' ],
			]
		);

		register_rest_route(
			$namespace,
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

		register_rest_route(
			$namespace,
			'/' . $this->rest_base . '/(?P<post_type>[a-z0-9_-]+)/(?P<post_id>\d+)',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => [ $this, 'update_item_permissions_check' ],
				'args'                => [
					'post_type' => [
						'type'        => 'string',
						'required'    => true,
						'description' => 'Post type slug of the post',
					],
					'post_id'   => [
						'type'        => 'integer',
						'required'    => true,
						'description' => 'Post ID to update meta fields for',
					],
					'meta'      => [
						'type'        => 'object',
						'required'    => true,
						'description' => 'Meta fields to update as key-value pairs',
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
					'hint'   => __( "Assign edit_posts to your user, or add 'show_in_rest' => true under the 'meta' section in the model config.", 'saltus-framework' ),
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
	 * Check whether the current user can update meta fields for a specific post.
	 *
	 * @param mixed $request The REST request.
	 * @return WP_Error|bool
	 */
	public function update_item_permissions_check( $request ) {
		$post_type = $request->get_param( 'post_type' );
		$post_id   = (int) $request->get_param( 'post_id' );

		if ( $this->policy && ! $this->policy->is_post_type_enabled( (string) $post_type, ModelRestPolicy::CAPABILITY_META ) ) {
			return new WP_Error(
				'model_not_found',
				__( 'Model not found.', 'saltus-framework' ),
				[
					'status' => 404,
					'hint'   => sprintf(
						/* translators: %s: post type slug */
						__( "Add 'show_in_rest' => true under the 'meta' section in the model config for '%s' in src/models/.", 'saltus-framework' ),
						$post_type
					),
				]
			);
		}

		if ( ! current_user_can( $this->post_type_edit_capability( (string) $post_type ), $post_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to edit this post.', 'saltus-framework' ),
				[
					'status' => 403,
					'hint'   => sprintf(
						/* translators: 1: capability, 2: post ID */
						__( "Assign the '%1\$s' capability to your user role for post ID %2\$d, or use an administrator account.", 'saltus-framework' ),
						$this->post_type_edit_capability( (string) $post_type ),
						$post_id
					),
				]
			);
		}
		return true;
	}

	/**
	 * Update meta fields for a specific post.
	 *
	 * @param mixed $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$post_type = $request->get_param( 'post_type' );
		$post_id   = (int) $request->get_param( 'post_id' );

		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== $post_type ) {
			return new WP_Error(
				'rest_post_invalid_id',
				__( 'Invalid post ID or post type mismatch.', 'saltus-framework' ),
				[ 'status' => 404 ]
			);
		}

		$meta_data = $this->extract_meta_from_request( $request );
		if ( is_wp_error( $meta_data ) ) {
			return $meta_data;
		}

		$meta_fields_info = $this->meta_field_provider->post_type_meta( $this->modeler, $this->policy, (string) $post_type );
		if ( is_wp_error( $meta_fields_info ) ) {
			return $meta_fields_info;
		}

		$meta_key_lookup = $this->build_meta_key_lookup( $meta_fields_info );
		$updated         = $this->apply_meta_updates( $post_id, $meta_data, $meta_key_lookup );

		return rest_ensure_response(
			[
				'post_id'   => $post_id,
				'post_type' => $post_type,
				'meta'      => $updated,
			]
		);
	}

	/**
	 * @param WP_REST_Request $request
	 * @return array<string, mixed>|WP_Error
	 */
	private function extract_meta_from_request( $request ) {
		$meta_data = $request->get_json_params();

		if ( isset( $meta_data['meta'] ) && is_array( $meta_data['meta'] ) ) {
			$meta_data = $meta_data['meta'];
		}

		if ( empty( $meta_data ) ) {
			return new WP_Error(
				'rest_empty_data',
				__( 'No meta data provided.', 'saltus-framework' ),
				[ 'status' => 400 ]
			);
		}

		return $meta_data;
	}

	/**
	 * @param array<string, mixed> $meta_fields_info
	 * @return array{0: string[], 1: array<string, bool>}
	 */
	private function build_meta_key_lookup( array $meta_fields_info ): array {
		$rest_meta_keys = [];
		if ( isset( $meta_fields_info['normalized']['rest_meta_keys'] ) && is_array( $meta_fields_info['normalized']['rest_meta_keys'] ) ) {
			$rest_meta_keys = $meta_fields_info['normalized']['rest_meta_keys'];
		}

		$valid_keys     = [];
		$serialized_map = [];
		foreach ( $rest_meta_keys as $meta_key_info ) {
			if ( ! isset( $meta_key_info['meta_key'] ) ) {
				continue;
			}
			$key          = (string) $meta_key_info['meta_key'];
			$valid_keys[] = $key;
			if ( ! empty( $meta_key_info['serialized'] ) ) {
				$serialized_map[ $key ] = true;
			}
		}

		return [ $valid_keys, $serialized_map ];
	}

	/**
	 * @param array<string, mixed> $meta_data
	 * @param array{0: string[], 1: array<string, bool>} $meta_key_lookup
	 * @return array<string, mixed>
	 */
	private function apply_meta_updates( int $post_id, array $meta_data, array $meta_key_lookup ): array {
		[ $valid_keys, $serialized_map ] = $meta_key_lookup;

		$updated = [];
		foreach ( $meta_data as $key => $value ) {
			if ( ! in_array( (string) $key, $valid_keys, true ) ) {
				continue;
			}

			if ( isset( $serialized_map[ $key ] ) ) {
				$existing = get_post_meta( $post_id, $key, true );
				if ( ! is_array( $existing ) ) {
					$existing = [];
				}
				$new_value    = is_array( $value ) ? $value : [];
				$merged_value = array_replace_recursive( $existing, $new_value );
				update_post_meta( $post_id, $key, $merged_value );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
			$updated[ $key ] = get_post_meta( $post_id, $key, true );
		}

		return $updated;
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
