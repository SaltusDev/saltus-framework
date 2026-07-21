<?php
namespace Saltus\WP\Framework\Tests\MCP\Middleware;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Middleware\MiddlewarePipeline;
use Saltus\WP\Framework\MCP\Middleware\RateLimitMiddleware;
use Saltus\WP\Framework\MCP\Middleware\RequestContext;
use Saltus\WP\Framework\MCP\RateLimiter\RateLimiter;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\MCP\Middleware\RateLimitMiddleware
 */
class RateLimitMiddlewareTest extends TestCase {

	protected function setUp(): void {
		global $wp_transients;
		$wp_transients = [];
	}

	public function testAllowsRequestWithinLimit(): void {
		$pipeline = new MiddlewarePipeline();
		$pipeline->add( new RateLimitMiddleware( new RateLimiter( 5, 60 ) ) );

		for ( $i = 0; $i < 5; $i++ ) {
			$context = new RequestContext();
			$result  = $pipeline->execute( $context, function () {
				return new \WP_REST_Response( [ 'ok' => true ] );
			} );
			$this->assertInstanceOf( \WP_REST_Response::class, $result );
		}
	}

	public function testBlocksRequestOverLimit(): void {
		$pipeline = new MiddlewarePipeline();
		$pipeline->add( new RateLimitMiddleware( new RateLimiter( 2, 60 ) ) );

		$context1 = new RequestContext();
		$pipeline->execute( $context1, function () {
			return new \WP_REST_Response( [ 'ok' => true ] );
		} );

		$context2 = new RequestContext();
		$pipeline->execute( $context2, function () {
			return new \WP_REST_Response( [ 'ok' => true ] );
		} );

		$context3 = new RequestContext();
		$result   = $pipeline->execute( $context3, function () {
			return new \WP_REST_Response( [ 'ok' => true ] );
		} );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] ?? null );
	}

	public function testAllowsDifferentIdentifiersIndependently(): void {
		$pipeline = new MiddlewarePipeline();
		$pipeline->add( new RateLimitMiddleware( new RateLimiter( 1, 60 ) ) );

		$context = new RequestContext();
		$context->set_tool_metadata( [ 'rate_limit_identifier' => 'client_a' ] );
		$pipeline->execute( $context, function () {
			return new \WP_REST_Response( [ 'ok' => true ] );
		} );

		$context2 = new RequestContext();
		$context2->set_tool_metadata( [ 'rate_limit_identifier' => 'client_b' ] );
		$result = $pipeline->execute( $context2, function () {
			return new \WP_REST_Response( [ 'ok' => true ] );
		} );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );

		$context3 = new RequestContext();
		$context3->set_tool_metadata( [ 'rate_limit_identifier' => 'client_a' ] );
		$blocked = $pipeline->execute( $context3, function () {
			return new \WP_REST_Response( [ 'ok' => true ] );
		} );

		$this->assertInstanceOf( \WP_Error::class, $blocked );
	}

	public function testSetsRateLimitAttributesOnContext(): void {
		$pipeline = new MiddlewarePipeline();
		$pipeline->add( new RateLimitMiddleware( new RateLimiter( 5, 60 ) ) );

		$context = new RequestContext();
		$pipeline->execute( $context, function ( RequestContext $ctx ) {
			$this->assertNotNull( $ctx->get_attribute( 'rate_limit_remaining' ) );
			$this->assertNotNull( $ctx->get_attribute( 'rate_limit_reset_at' ) );
			return new \WP_REST_Response( [ 'ok' => true ] );
		} );
	}
}
