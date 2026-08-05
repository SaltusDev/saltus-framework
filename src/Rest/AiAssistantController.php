<?php

namespace Saltus\WP\Framework\Rest;

use Saltus\WP\Framework\Features\AiAssistant\AiAssistantProvider;
use Saltus\WP\Framework\MCP\MCPConfig;

/** REST controller for model-scoped admin AI assistant actions. */
final class AiAssistantController {

	private ModelRestPolicy $policy;
	private AiAssistantProvider $provider;

	public function __construct( ModelRestPolicy $policy, AiAssistantProvider $provider ) {
		$this->policy   = $policy;
		$this->provider = $provider;
	}

	public function register_routes(): void {
		register_rest_route(
			MCPConfig::get_namespace(),
			'/ai-assistant/(?P<post_type>[a-z0-9_-]+)/(?P<post_id>\d+)/(?P<action>[a-z0-9_-]+)',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => [ $this, 'permissions_check' ],
				'args'                => [
					'post_type' => [
						'type'     => 'string',
						'required' => true,
					],
					'post_id'   => [
						'type'     => 'integer',
						'required' => true,
					],
					'action'    => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);
	}

	/**
	 * @param mixed $request
	 * @return bool|\WP_Error
	 */
	public function permissions_check( $request ) {
		$post_type = is_object( $request ) && method_exists( $request, 'get_param' ) ? (string) $request->get_param( 'post_type' ) : '';
		$post_id   = is_object( $request ) && method_exists( $request, 'get_param' ) ? (int) $request->get_param( 'post_id' ) : 0;
		if ( $post_type === '' || ! $this->policy->is_post_type_enabled( $post_type, ModelRestPolicy::CAPABILITY_MODELS ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'AI assistants are not enabled for this model.', 'saltus-framework' ), [ 'status' => 403 ] );
		}
		if ( $post_id > 0 ) {
			$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
			if ( ! $post ) {
				return new \WP_Error( 'ai_assistant_post_not_found', __( 'The requested post was not found.', 'saltus-framework' ), [ 'status' => 404 ] );
			}
			if ( (string) $post->post_type !== $post_type ) {
				return new \WP_Error( 'ai_assistant_post_type_mismatch', __( 'The post does not belong to the requested model.', 'saltus-framework' ), [ 'status' => 400 ] );
			}
			return current_user_can( 'edit_post', $post_id )
				? true
				: new \WP_Error( 'rest_forbidden', __( 'You do not have permission to edit this post.', 'saltus-framework' ), [ 'status' => 403 ] );
		}
		return current_user_can( 'edit_posts' )
			? true
			: new \WP_Error( 'rest_forbidden', __( 'You do not have permission to create this post.', 'saltus-framework' ), [ 'status' => 403 ] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function create_item( \WP_REST_Request $request ) {
		$payload            = $request->get_json_params();
		$payload['post_id'] = (int) $request->get_param( 'post_id' );
		$result             = $this->provider->dispatch(
			(string) $request->get_param( 'post_type' ),
			(string) $request->get_param( 'action' ),
			$payload
		);
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
}
