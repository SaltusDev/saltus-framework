<?php

namespace Saltus\WP\Framework\Rest;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Response;
use WP_Error;

/**
 * REST controller for exporting posts as WXR.
 */
class ExportController extends WP_REST_Controller {

	private const ROUTE_NAMESPACE = 'saltus-framework/v1';
	private ?ModelRestPolicy $policy;

	/**
	 * @param ModelRestPolicy|null $policy  Optional REST policy for capability gating.
	 */
	public function __construct( ?ModelRestPolicy $policy = null ) {
		$this->policy    = $policy;
		$this->namespace = self::ROUTE_NAMESPACE;
		$this->rest_base = 'export';
	}

	/**
	 * Register the REST route for post export.
	 */
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

	/**
	 * Check whether the current user can export posts.
	 *
	 * @param mixed $request  The REST request.
	 * @return WP_Error|bool
	 */
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

	/**
	 * Export a single post as WXR.
	 *
	 * @param mixed $request  The REST request containing the post_id parameter.
	 * @return WP_REST_Response|WP_Error
	 */
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

	/**
	 * Generate WXR export XML for a single post.
	 *
	 * @param \WP_Post $post  The post to export.
	 * @return string
	 */
	private function generate_wxr( \WP_Post $post ): string {
		$version = \defined( 'WXR_VERSION' ) ? WXR_VERSION : '1.2';

		return sprintf(
			"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" .
			"<!-- WXR export -->\n" .
			"<rss version=\"2.0\" xmlns:excerpt=\"http://wordpress.org/export/%1\$s/excerpt/\" xmlns:content=\"http://purl.org/rss/1.0/modules/content/\" xmlns:wp=\"http://wordpress.org/export/%1\$s/\">\n" .
			"<channel>\n" .
			"<wp:wxr_version>%1\$s</wp:wxr_version>\n" .
			"<item>\n" .
			"<title>%2\$s</title>\n" .
			"<content:encoded><![CDATA[%3\$s]]></content:encoded>\n" .
			"<excerpt:encoded><![CDATA[%4\$s]]></excerpt:encoded>\n" .
			"<wp:post_id>%5\$d</wp:post_id>\n" .
			"<wp:post_type>%6\$s</wp:post_type>\n" .
			"<wp:status>%7\$s</wp:status>\n" .
			"</item>\n" .
			"</channel>\n" .
			"</rss>\n",
			$this->xml( (string) $version ),
			$this->xml( (string) $post->post_title ),
			$this->cdata( (string) $post->post_content ),
			$this->cdata( (string) $post->post_excerpt ),
			(int) $post->ID,
			$this->xml( (string) $post->post_type ),
			$this->xml( (string) $post->post_status )
		);
	}

	/**
	 * Escape text for XML element content.
	 *
	 * @param string $value  Raw value.
	 * @return string
	 */
	private function xml( string $value ): string {
		return \htmlspecialchars( $value, ENT_XML1 | ENT_COMPAT, 'UTF-8' );
	}

	/**
	 * Make arbitrary text safe inside a CDATA node.
	 *
	 * @param string $value  Raw value.
	 * @return string
	 */
	private function cdata( string $value ): string {
		return str_replace( ']]>', ']]]]><![CDATA[>', $value );
	}
}
