<?php
namespace Saltus\WP\Framework\Tests\MCP\Middleware;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Middleware\MiddlewareInterface;
use Saltus\WP\Framework\MCP\Middleware\MiddlewarePipeline;
use Saltus\WP\Framework\MCP\Middleware\PipelineIntegration;
use Saltus\WP\Framework\MCP\Middleware\RequestContext;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\MCP\Middleware\PipelineIntegration
 */
class PipelineIntegrationTest extends TestCase {

	public function testRestPreDispatchShortCircuitsOnError(): void {
		$pipeline = new MiddlewarePipeline();
		$pipeline->add( new class implements MiddlewareInterface {
			public function handle( RequestContext $context, callable $next ) {
				return new \WP_Error( 'blocked', 'Blocked by middleware' );
			}
		} );

		$integration = new PipelineIntegration( $pipeline );
		$server      = new \WP_REST_Server();
		$request     = new \WP_REST_Request( 'GET', '/test' );

		$result = $integration->on_rest_pre_dispatch( null, $server, $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'blocked', $result->get_error_code() );
	}

	public function testRestPreDispatchReturnsResponse(): void {
		$pipeline = new MiddlewarePipeline();
		$pipeline->add( new class implements MiddlewareInterface {
			public function handle( RequestContext $context, callable $next ) {
				return $next( $context );
			}
		} );

		$integration = new PipelineIntegration( $pipeline );
		$server      = new \WP_REST_Server();
		$request     = new \WP_REST_Request( 'GET', '/saltus-framework/v1/health' );

		$result = $integration->on_rest_pre_dispatch( null, $server, $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
	}

	public function testRegisterHooksAddsFilter(): void {
		global $wp_filters_registered;
		$wp_filters_registered = [];

		$pipeline     = new MiddlewarePipeline();
		$integration  = new PipelineIntegration( $pipeline );
		$integration->register_rest_hooks();

		$this->assertArrayHasKey( 'rest_pre_dispatch', $wp_filters_registered );
		$filter = $wp_filters_registered['rest_pre_dispatch'][0] ?? null;
		$this->assertNotNull( $filter );
		$this->assertSame( [ $integration, 'on_rest_pre_dispatch' ], $filter['callback'] );
	}

	public function testWithDefaultStagesCreatesPipeline(): void {
		$stage = new class implements MiddlewareInterface {
			public function handle( RequestContext $context, callable $next ) {
				return $next( $context );
			}
		};

		$integration = PipelineIntegration::with_default_stages( $stage );

		$this->assertInstanceOf( PipelineIntegration::class, $integration );
	}
}
