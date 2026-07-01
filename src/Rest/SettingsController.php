<?php

namespace Saltus\WP\Framework\Rest;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class SettingsController extends WP_REST_Controller {

	private const ROUTE_NAMESPACE = 'saltus-framework/v1';
	private ?ModelRestPolicy $policy;

	public function __construct( ?ModelRestPolicy $policy = null ) {
		$this->policy    = $policy;
		$this->namespace = self::ROUTE_NAMESPACE;
		$this->rest_base = 'settings';
	}

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

	protected function get_option_name( string $post_type ): string {
		return sprintf( 'saltus_framework_settings_%s', $post_type );
	}

	public function get_item_permissions_check( $request ): true|WP_Error {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view settings.', 'saltus-framework' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	public function update_item_permissions_check( $request ): true|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to update settings.', 'saltus-framework' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	public function get_item( $request ): WP_REST_Response|WP_Error {
		$post_type = $request->get_param( 'post_type' );
		if ( $this->policy && ! $this->policy->is_post_type_enabled( (string) $post_type, ModelRestPolicy::CAPABILITY_SETTINGS ) ) {
			return new WP_Error(
				'model_not_found',
				__( 'Model not found.', 'saltus-framework' ),
				[ 'status' => 404 ]
			);
		}

		$option_name = $this->get_option_name( $post_type );
		$settings    = get_option( $option_name, [] );

		return rest_ensure_response(
			[
				'post_type' => $post_type,
				'settings'  => $settings,
			]
		);
	}

	public function update_item( $request ): WP_REST_Response|WP_Error {
		$post_type = $request->get_param( 'post_type' );
		if ( $this->policy && ! $this->policy->is_post_type_enabled( (string) $post_type, ModelRestPolicy::CAPABILITY_SETTINGS ) ) {
			return new WP_Error(
				'model_not_found',
				__( 'Model not found.', 'saltus-framework' ),
				[ 'status' => 404 ]
			);
		}

		$option_name = $this->get_option_name( $post_type );
		$settings    = $request->get_json_params();

		if ( empty( $settings ) ) {
			return new WP_Error(
				'rest_empty_data',
				__( 'No settings data provided.', 'saltus-framework' ),
				[ 'status' => 400 ]
			);
		}

		$sanitized = [];
		foreach ( $settings as $key => $value ) {
			$sanitized[ sanitize_key( $key ) ] = sanitize_text_field( wp_unslash( $value ) );
		}

		$updated = update_option( $option_name, $sanitized );

		if ( ! $updated ) {
			$current = get_option( $option_name, [] );
			if ( $current === $sanitized ) {
				return rest_ensure_response(
					[
						'post_type' => $post_type,
						'settings'  => $sanitized,
						'status'    => 'unchanged',
					]
				);
			}

			return new WP_Error(
				'rest_update_failed',
				__( 'Failed to update settings.', 'saltus-framework' ),
				[ 'status' => 500 ]
			);
		}

		return rest_ensure_response(
			[
				'post_type' => $post_type,
				'settings'  => $sanitized,
				'status'    => 'updated',
			]
		);
	}

	/**
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
