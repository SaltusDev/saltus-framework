<?php
namespace Saltus\WP\Framework\Rest;

use Saltus\WP\Framework\Features\Blocks\SaltusBlocks;
use Saltus\WP\Framework\MCP\MCPConfig;
use Saltus\WP\Framework\Modeler;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Server;

/** REST discovery for configured Saltus blocks. @api */
final class BlocksController extends WP_REST_Controller {

	private Modeler $modeler;
	private ModelRestPolicy $policy;

	public function __construct( Modeler $modeler, ModelRestPolicy $policy ) {
		$this->modeler   = $modeler;
		$this->policy    = $policy;
		$this->namespace = MCPConfig::get_namespace();
		$this->rest_base = 'blocks';
	}

	public function register_routes(): void {
		/** @var non-falsy-string $namespace */
		$namespace = $this->namespace;
		register_rest_route(
			$namespace,
			'/' . $this->rest_base,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => [ $this, 'get_items_permissions_check' ],
			]
		);
	}

	/** @param mixed $request @return bool|WP_Error */
	public function get_items_permissions_check( $request ) {
		return current_user_can( 'edit_posts' ) ? true : new WP_Error( 'rest_forbidden', __( 'You do not have permission to discover blocks.', 'saltus-framework' ), [ 'status' => 403 ] );
	}

	/** @param mixed $request */
	public function get_items( $request ): WP_REST_Response {
		$definitions = ( new SaltusBlocks( $this->modeler ) )->definitions();
		$items       = [];
		foreach ( $definitions as $definition ) {
			if ( ! $this->policy->is_post_type_enabled( (string) $definition['post_type'], ModelRestPolicy::CAPABILITY_BLOCKS ) ) {
				continue;
			}
			$blocks = [];
			foreach ( $definition['blocks'] as $view => $block ) {
				$blocks[ $view ] = $block['name'];
			}
			$items[] = [
				'post_type'    => $definition['post_type'],
				'label'        => $definition['label'],
				'label_plural' => $definition['label_plural'],
				'blocks'       => $blocks,
				'meta_fields'  => $definition['meta_fields'],
			];
		}

		return rest_ensure_response( [ 'post_types' => $items ] );
	}
}
