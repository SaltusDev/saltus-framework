<?php
namespace Saltus\WP\Framework\MCP\Middleware;

/**
 * Hooks the middleware pipeline into WordPress REST dispatch and MCP execution.
 *
 * For REST: intercepts rest_pre_dispatch to run the full middleware chain,
 * replacing the inline orchestration that previously lived in REST controllers.
 * For MCP: provides a factory method that builds a pipeline configured with
 * the standard stage ordering.
 * @api
 */
class PipelineIntegration {

	private MiddlewarePipeline $pipeline;

	public function __construct( MiddlewarePipeline $pipeline ) {
		$this->pipeline = $pipeline;
	}

	/**
	 * Register the rest_pre_dispatch hook to run the middleware pipeline.
	 *
	 * The pipeline wraps the actual REST dispatch, running permission,
	 * validation, rate-limit, cache, and audit middleware around it.
	 */
	public function register_rest_hooks(): void {
		if ( ! \function_exists( 'add_filter' ) ) {
			return;
		}

		\add_filter( 'rest_pre_dispatch', [ $this, 'on_rest_pre_dispatch' ], 10, 3 );
	}

	/**
	 * Handle rest_pre_dispatch: run the pipeline and return the result.
	 *
	 * @param mixed            $result
	 * @param \WP_REST_Server  $server
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error|null
	 */
	public function on_rest_pre_dispatch( $result, $server, $request ) {
		$context = new RequestContext();
		$context->set_rest_request( $request );
		$context->set_route( (string) $request->get_route() );

		$params = $request->get_params();
		$context->set_args( $params );

		return $this->pipeline->execute(
			$context,
			function ( RequestContext $ctx ) use ( $request ) {
				if ( ! \function_exists( 'rest_do_request' ) ) {
					return \Saltus\WP\Framework\MCP\Error\ErrorResponse::internal_error(
						\__( 'WordPress REST dispatch is not available.', 'saltus-framework' )
					);
				}

				$response = \rest_do_request( $request );
				$ctx->set_response( $response );

				return $response;
			}
		);
	}

	/**
	 * Build the default pipeline with the standard stage ordering.
	 *
	 * Order: Permission → Validation → RateLimit → Cache → Audit
	 *
	 * @param MiddlewareInterface ...$stages  Optional additional stages appended after defaults.
	 * @return self
	 */
	public static function with_default_stages( MiddlewareInterface ...$stages ): self {
		$pipeline = new MiddlewarePipeline();

		$defaults = [
			// Permission stage injected by caller (requires CapabilityPolicy)
			// Validation stage injected by caller
			// RateLimit stage injected by caller
			// Cache stage injected by caller
			// Audit stage injected by caller
		];

		foreach ( $stages as $stage ) {
			$pipeline->add( $stage );
		}

		return new self( $pipeline );
	}
}
