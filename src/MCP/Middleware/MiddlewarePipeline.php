<?php
namespace Saltus\WP\Framework\MCP\Middleware;

/**
 * Ordered middleware stage registry and chain executor.
 *
 * Stages are executed in registration order. Each stage receives the
 * RequestContext and a callable to invoke the next stage. The innermost
 * callable is the actual dispatch handler provided at execution time.
 * @api
 */
class MiddlewarePipeline {

	/** @var list<MiddlewareInterface> */
	private array $stages = [];

	/**
	 * Register a middleware stage.
	 *
	 * @param MiddlewareInterface $middleware
	 */
	public function add( MiddlewareInterface $middleware ): void {
		$this->stages[] = $middleware;
	}

	/**
	 * Execute the middleware chain with the given context and dispatch handler.
	 *
	 * @param RequestContext $context    The mutable request context.
	 * @param callable $dispatch  The innermost dispatch handler.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function execute( RequestContext $context, callable $dispatch ) {
		$chain = $this->build_chain( $dispatch );
		return $chain( $context );
	}

	/**
	 * Build the middleware chain as nested closures.
	 *
	 * @param callable $dispatch
	 * @return callable
	 */
	private function build_chain( callable $dispatch ): callable {
		$chain = $dispatch;

		foreach ( array_reverse( $this->stages ) as $stage ) {
			$next  = $chain;
			$chain = function ( RequestContext $context ) use ( $stage, $next ) {
				return $stage->handle( $context, $next );
			};
		}

		return $chain;
	}
}
