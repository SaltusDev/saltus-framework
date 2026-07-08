<?php

namespace Saltus\WP\Framework\Rest;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Response;
use WP_Error;
use Saltus\WP\Framework\Features\SingleExport\SaltusSingleExport;
use Saltus\WP\Framework\MCP\MCPConfig;

/**
 * REST controller for exporting posts as WXR.
 */
class ExportController extends WP_REST_Controller {

	private ?ModelRestPolicy $policy;
	private SaltusSingleExport $exporter;

	/**
	 * @param ModelRestPolicy|null $policy  Optional REST policy for capability gating.
	 * @param SaltusSingleExport|null $exporter  Optional export feature implementation.
	 */
	public function __construct( ?ModelRestPolicy $policy = null, ?SaltusSingleExport $exporter = null ) {
		$this->policy    = $policy;
		$this->exporter  = $exporter ?? new SaltusSingleExport( '', [] );
		$this->namespace = MCPConfig::get_namespace();
		$this->rest_base = 'export';
	}

	/**
	 * Register the REST route for post export.
	 */
	public function register_routes(): void {
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<post_id>\d+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => [ $this, 'get_item_permissions_check' ],
				'args'                => [
					'post_id' => [
						'type'        => 'integer',
						'required'    => true,
						'description' => 'ID of the post to export',
					],
				],
			]
		);
	}

	/**
	 * Check whether the current user can export posts.
	 *
	 * @param mixed $request  The REST request.
	 * @return WP_Error|bool
	 */
	public function get_item_permissions_check( $request ) {
		if ( ! \current_user_can( 'export' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to export posts.', 'saltus-framework' ),
				[
					'status' => 403,
					'hint'   => __( 'Assign the export capability to your user role via Users → Edit User, or use an administrator account.', 'saltus-framework' ),
				]
			);
		}
		return true;
	}

	/**
	 * Export a single post as WXR.
	 *
	 * @param mixed $request  The REST request containing the post_id parameter.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$post    = \get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'post_not_found',
				__( 'Post not found.', 'saltus-framework' ),
				[ 'status' => 404 ]
			);
		}

		if ( $this->policy && ! $this->policy->is_post_type_enabled( (string) $post->post_type, ModelRestPolicy::CAPABILITY_EXPORT ) ) {
			return new WP_Error(
				'model_rest_capability_disabled',
				__( 'Export is not enabled for this post type.', 'saltus-framework' ),
				[
					'status' => 403,
					'hint'   => sprintf(
						/* translators: %s: post type slug */
						__( "Add 'saltus_rest' => [ 'capabilities' => [ 'export' => true ] ] to the model config for '%s' in src/models/.", 'saltus-framework' ),
						$post->post_type
					),
				]
			);
		}

		return \rest_ensure_response( $this->exporter->export_post( $post_id ) );
	}
}
