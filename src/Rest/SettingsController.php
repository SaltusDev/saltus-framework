<?php

namespace Saltus\WP\Framework\Rest;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Saltus\WP\Framework\Features\Settings\SettingsManager;

/**
 * REST controller for reading and updating per-post-type settings.
 */
class SettingsController extends WP_REST_Controller {

	private const ROUTE_NAMESPACE = 'saltus-framework/v1';
	private ?ModelRestPolicy $policy;
	private SettingsManager $settings_manager;

	/**
	 * @param ModelRestPolicy|null $policy  Optional REST policy for capability gating.
	 * @param SettingsManager|null $settings_manager Optional settings manager.
	 */
	public function __construct( ?ModelRestPolicy $policy = null, ?SettingsManager $settings_manager = null ) {
		$this->policy           = $policy;
		$this->settings_manager = $settings_manager ?? new SettingsManager();
		$this->namespace        = self::ROUTE_NAMESPACE;
		$this->rest_base        = 'settings';
	}

	/**
	 * Register the REST routes for reading and updating settings.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/' . $this->rest_base . '/(?P<post_type>[a-z0-9_-]+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'get_item_permissions_check' ],
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::READABLE ),
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'update_item_permissions_check' ],
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				],
				'schema' => [ $this, 'get_item_schema' ],
			]
		);
	}

	/**
	 * Build the option name for a given post type.
	 *
	 * @param string $post_type  The post type slug.
	 * @return string
	 */
	protected function get_option_name( string $post_type ): string {
		return $this->settings_manager->option_name( $post_type );
	}

	/**
	 * Check whether the current user can view settings.
	 *
	 * @param mixed $request  The REST request.
	 * @return bool|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$post_type  = is_object( $request ) && method_exists( $request, 'get_param' ) ? $request->get_param( 'post_type' ) : null;
		$capability = is_string( $post_type ) && $post_type !== ''
			? $this->post_type_edit_capability( $post_type )
			: 'edit_posts';

		if ( ! current_user_can( $capability ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view settings.', 'saltus-framework' ),
				[ 'status' => 403 ]
			);
		}
		return true;
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

	/**
	 * Check whether the current user can update settings.
	 *
	 * @param mixed $request  The REST request.
	 * @return bool|WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to update settings.', 'saltus-framework' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * Retrieve settings for a post type.
	 *
	 * @param mixed $request  The REST request containing the post_type parameter.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$post_type = $request->get_param( 'post_type' );
		if ( $this->policy && ! $this->policy->is_post_type_enabled( (string) $post_type, ModelRestPolicy::CAPABILITY_SETTINGS ) ) {
			return new WP_Error(
				'model_not_found',
				__( 'Model not found.', 'saltus-framework' ),
				[ 'status' => 404 ]
			);
		}

		return rest_ensure_response( $this->settings_manager->get_settings( (string) $post_type ) );
	}

	/**
	 * Update settings for a post type.
	 *
	 * @param mixed $request  The REST request containing the post_type parameter and JSON body.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$post_type = $request->get_param( 'post_type' );
		if ( $this->policy && ! $this->policy->is_post_type_enabled( (string) $post_type, ModelRestPolicy::CAPABILITY_SETTINGS ) ) {
			return new WP_Error(
				'model_not_found',
				__( 'Model not found.', 'saltus-framework' ),
				[ 'status' => 404 ]
			);
		}

		$settings = $request->get_json_params() ?? [];

		return rest_ensure_response( $this->settings_manager->update_settings( (string) $post_type, $settings ) );
	}

	/**
	 * Get the JSON Schema for the settings resource.
	 *
	 * @return array{'$schema': string, title: string, type: string, properties: array<string, array<string, mixed>>}
	 */
	public function get_item_schema(): array {
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'settings',
			'type'       => 'object',
			'properties' => [
				'post_type' => [
					'type'        => 'string',
					'description' => 'The post type slug.',
					'readonly'    => true,
				],
				'settings'  => [
					'type'        => 'object',
					'description' => 'The settings data.',
					'arg_options' => [
						'sanitize_callback' => function ( $value ) {
							return $value;
						},
					],
				],
			],
		];
	}
}
