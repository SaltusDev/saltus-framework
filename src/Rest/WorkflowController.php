<?php

namespace Saltus\WP\Framework\Rest;

use Saltus\WP\Framework\Features\Workflow\InvalidWorkflow;
use Saltus\WP\Framework\Features\Workflow\StateTransitioner;
use Saltus\WP\Framework\Features\Workflow\WorkflowRegistry;
use Saltus\WP\Framework\Features\Workflow\WorkflowTransition;
use Saltus\WP\Framework\MCP\MCPConfig;

/**
 * REST access to workflow state.
 *
 * Reading a post's workflow tells a client what it may do next; the transition
 * route is the only way to move a post, so the legality and capability rules
 * live in one place.
 */
final class WorkflowController {

	private WorkflowRegistry $registry;
	private StateTransitioner $transitioner;

	public function __construct( WorkflowRegistry $registry, ?StateTransitioner $transitioner = null ) {
		$this->registry     = $registry;
		$this->transitioner = $transitioner ?? new StateTransitioner( $registry );
	}

	public function register_routes(): void {
		$namespace = MCPConfig::get_namespace();

		register_rest_route(
			$namespace,
			'/workflow/(?P<post_id>[0-9]+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
				'args'                => [
					'model' => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/workflow/(?P<post_id>[0-9]+)/transition',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'transition' ],
				'permission_callback' => [ $this, 'write_permissions_check' ],
				'args'                => [
					'model'      => [
						'type'     => 'string',
						'required' => true,
					],
					'transition' => [
						'type'     => 'string',
						'required' => true,
					],
					'note'       => [
						'type'    => 'string',
						'default' => '',
					],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/workflow/(?P<post_id>[0-9]+)/history',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_history' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
			]
		);
	}

	/**
	 * A post's current state, plus what may be done next.
	 *
	 * @param mixed $request
	 *
	 * @return mixed
	 */
	public function get_item( $request ) {
		$post_id = $this->int_param( $request, 'post_id' );
		$model   = $this->string_param( $request, 'model' );

		try {
			$definition = $this->registry->require( $model );
			$current    = $this->transitioner->current_state( $model, $post_id );
			$available  = $this->transitioner->available_transitions( $model, $post_id );
		} catch ( InvalidWorkflow $exception ) {
			return $this->error( $exception );
		}

		$state = $definition->state( $current );

		return rest_ensure_response(
			[
				'post_id'               => $post_id,
				'model'                 => $model,
				'state'                 => $current,
				'label'                 => $state !== null ? $state->label : $current,
				'public'                => $state !== null && $state->is_public,
				'available_transitions' => array_values(
					array_map(
						static fn( WorkflowTransition $transition ): array => $transition->to_array(),
						$available
					)
				),
				'workflow'              => $definition->to_array(),
			]
		);
	}

	/**
	 * Move a post to a new state.
	 *
	 * @param mixed $request
	 *
	 * @return mixed
	 */
	public function transition( $request ) {
		$post_id    = $this->int_param( $request, 'post_id' );
		$model      = $this->string_param( $request, 'model' );
		$transition = $this->string_param( $request, 'transition' );
		$note       = $this->string_param( $request, 'note' );

		if ( $transition === '' ) {
			return $this->bad_request( 'A transition name is required.' );
		}

		try {
			$result = $this->transitioner->transition( $model, $post_id, $transition, $note );
		} catch ( InvalidWorkflow $exception ) {
			return $this->error( $exception );
		}

		// A refused transition is the caller asking for something illegal, so it
		// is a 400 rather than a silent no-op with a 200.
		if ( empty( $result['transitioned'] ) ) {
			return $this->bad_request( (string) ( $result['error'] ?? 'The transition was refused.' ) );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * A post's transition history.
	 *
	 * @param mixed $request
	 *
	 * @return mixed
	 */
	public function get_history( $request ) {
		$post_id = $this->int_param( $request, 'post_id' );

		return rest_ensure_response(
			[
				'post_id' => $post_id,
				'history' => $this->transitioner->history( $post_id ),
			]
		);
	}

	/**
	 * @param mixed $_request The REST request; unused, reads need no per-post check.
	 */
	public function read_permissions_check( $_request = null ): bool {
		unset( $_request );

		return current_user_can( 'read' );
	}

	/**
	 * Moving a post requires being able to edit it.
	 *
	 * The transition's own capability is checked separately by the
	 * transitioner, so both the post and the specific move are gated.
	 *
	 * @param mixed $request
	 */
	public function write_permissions_check( $request ): bool {
		$post_id = $this->int_param( $request, 'post_id' );
		if ( $post_id <= 0 ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * @param mixed $request
	 */
	private function int_param( $request, string $key ): int {
		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			return (int) $request->get_param( $key );
		}

		return 0;
	}

	/**
	 * @param mixed $request
	 */
	private function string_param( $request, string $key ): string {
		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			return (string) $request->get_param( $key );
		}

		return '';
	}

	/**
	 * @return mixed
	 */
	private function error( InvalidWorkflow $exception ) {
		return $this->bad_request( $exception->getMessage() );
	}

	/**
	 * @return mixed
	 */
	private function bad_request( string $message ) {
		if ( class_exists( '\WP_Error' ) ) {
			return new \WP_Error( 'saltus_workflow_invalid', $message, [ 'status' => 400 ] );
		}

		return rest_ensure_response( [ 'error' => $message ] );
	}
}
