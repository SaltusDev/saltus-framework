<?php

namespace Saltus\WP\Framework\Rest;

use Saltus\WP\Framework\Features\EditorialReview\ProposalService;
use Saltus\WP\Framework\MCP\MCPConfig;

/** REST review workflow for AI change proposals. */
final class EditorialReviewController {

	private ProposalService $proposals;

	public function __construct( ?ProposalService $proposals = null ) {
		$this->proposals = $proposals ?? new ProposalService();
	}

	public function register_routes(): void {
		$namespace = MCPConfig::get_namespace();
		register_rest_route(
			$namespace,
			'/proposals',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => [ $this, 'permissions_check' ],
				'args'                => [
					'status'   => [
						'type'    => 'string',
						'default' => '',
					],
					'per_page' => [
						'type'    => 'integer',
						'default' => 50,
					],
				],
			]
		);
		register_rest_route(
			$namespace,
			'/proposals/(?P<id>[0-9]+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => [ $this, 'permissions_check' ],
			]
		);
		$review_routes = [
			'approve' => 'approve',
			'reject'  => 'reject',
		];
		foreach ( $review_routes as $route => $method ) {
			register_rest_route(
				$namespace,
				'/proposals/(?P<id>[0-9]+)/' . $route,
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, $method ],
					'permission_callback' => [ $this, 'permissions_check' ],
					'args'                => [
						'note' => [
							'type'    => 'string',
							'default' => '',
						],
					],
				]
			);
		}
	}

	/** @param mixed $request */
	public function permissions_check( $request ): bool {
		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$proposal_id = (int) $request->get_param( 'id' );
			if ( $proposal_id > 0 ) {
				$proposal = $this->proposals->get( $proposal_id );
				$post_id  = is_array( $proposal ) ? (int) ( $proposal['post_id'] ?? 0 ) : 0;
				if ( $post_id > 0 && function_exists( 'get_post' ) && get_post( $post_id ) ) {
					return current_user_can( 'edit_post', $post_id );
				}
			}
		}
		return current_user_can( 'edit_posts' );
	}

	public function get_items( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response(
			$this->proposals->list(
				(string) $request->get_param( 'status' ),
				(int) $request->get_param( 'per_page' )
			)
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function get_item( \WP_REST_Request $request ) {
		$item = $this->proposals->get( (int) $request->get_param( 'id' ) );
		if ( ! $item ) {
			return new \WP_Error( 'proposal_not_found', __( 'Proposal not found.', 'saltus-framework' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response( $item );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function approve( \WP_REST_Request $request ) {
		$result = $this->proposals->approve( (int) $request->get_param( 'id' ), (string) $request->get_param( 'note' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function reject( \WP_REST_Request $request ) {
		$result = $this->proposals->reject( (int) $request->get_param( 'id' ), (string) $request->get_param( 'note' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
}
