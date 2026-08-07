<?php

namespace Saltus\WP\Framework\Rest;

use Saltus\WP\Framework\Features\AiContext\AiContextProvider;
use Saltus\WP\Framework\MCP\MCPConfig;

/** REST controller for model AI governance context. */
final class AiContextController {

	private ModelRestPolicy $policy;
	private AiContextProvider $provider;

	public function __construct( ModelRestPolicy $policy, AiContextProvider $provider ) {
		$this->policy   = $policy;
		$this->provider = $provider;
	}

	public function register_routes(): void {
		register_rest_route(
			MCPConfig::get_namespace(),
			'/context/(?P<post_type>[a-z0-9_-]+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => [ $this, 'permissions_check' ],
			]
		);
	}

	/**
	 * @param mixed $request
	 * @return bool
	 */
	public function permissions_check( $request ) {
		$name = is_object( $request ) && method_exists( $request, 'get_param' ) ? (string) $request->get_param( 'post_type' ) : '';
		return $name !== '' && $this->policy->is_post_type_enabled( $name, ModelRestPolicy::CAPABILITY_MODELS ) && current_user_can( 'edit_posts' );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( \WP_REST_Request $request ) {
		$name    = (string) $request->get_param( 'post_type' );
		$context = $this->provider->get( $name );
		if ( $context === null ) {
			return new \WP_Error( 'model_not_found', __( 'Model not found.', 'saltus-framework' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response( $context );
	}
}
