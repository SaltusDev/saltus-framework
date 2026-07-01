<?php

namespace Saltus\WP\Framework\Rest;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Response;
use WP_Error;

class ExportController extends WP_REST_Controller {

	private const ROUTE_NAMESPACE = 'saltus-framework/v1';
	private ?ModelRestPolicy $policy;

	public function __construct( ?ModelRestPolicy $policy = null ) {
		$this->policy    = $policy;
		$this->namespace = self::ROUTE_NAMESPACE;
		$this->rest_base = 'export';
	}

	public function register_routes(): void {
		\register_rest_route(
			self::ROUTE_NAMESPACE,
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

	public function get_item_permissions_check( $request ): WP_Error|bool {
		if ( ! \current_user_can( 'export' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to export posts.', 'saltus-framework' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	public function get_item( $request ): WP_REST_Response|WP_Error {
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
				[ 'status' => 403 ]
			);
		}

		if ( ! \defined( 'WXR_VERSION' ) ) {
			require_once ABSPATH . 'wp-admin/includes/export.php';
		}

		$wxr = $this->generate_wxr( $post );

		return \rest_ensure_response(
			[
				'post_id'    => $post_id,
				'post_type'  => $post->post_type,
				'post_title' => $post->post_title,
				'wxr'        => $wxr,
			]
		);
	}

	private function generate_wxr( \WP_Post $post ): string {
		\ob_start();
		\export_wp(
			[
				'content'    => $post->post_type,
				'author'     => '',
				'category'   => '',
				'start_date' => '',
				'end_date'   => '',
				'status'     => 'any',
			]
		);
		$wxr = \ob_get_clean();
		return $wxr !== false ? $wxr : '';
	}
}
