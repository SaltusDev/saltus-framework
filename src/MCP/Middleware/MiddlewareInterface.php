<?php
namespace Saltus\WP\Framework\MCP\Middleware;

/**
 * @api
 */
interface MiddlewareInterface {

	/**
	 * Handle a middleware stage.
	 *
	 * @param RequestContext $context  The mutable request context flowing through the pipeline.
	 * @param callable $next  The next stage in the chain.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( RequestContext $context, callable $next );
}
