<?php
namespace Saltus\WP\Framework\Tests\MCP\Middleware;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Cache\TransientCache;
use Saltus\WP\Framework\MCP\Middleware\CacheMiddleware;
use Saltus\WP\Framework\MCP\Middleware\MiddlewarePipeline;
use Saltus\WP\Framework\MCP\Middleware\RequestContext;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\MCP\Middleware\CacheMiddleware
 */
class CacheMiddlewareTest extends TestCase {

	protected function setUp(): void {
		global $wp_transients;
		$wp_transients = [];
	}

	public function testCachesGetResponse(): void {
		$cache     = new TransientCache();
		$pipeline  = new MiddlewarePipeline();
		$pipeline->add( new CacheMiddleware( $cache ) );
		$callCount = 0;

		$context = new RequestContext();
		$context->set_args( [ 'type' => 'post_types' ] );
		$context->set_tool_metadata( [ 'name' => 'list_models' ] );
		$context->set_attribute( 'cache_ttl', 300 );

		$result1 = $pipeline->execute( $context, function () use ( &$callCount ) {
			++$callCount;
			return new \WP_REST_Response( [ 'data' => 'fresh' ] );
		} );

		$this->assertInstanceOf( \WP_REST_Response::class, $result1 );
		$this->assertSame( 1, $callCount );

		$result2 = $pipeline->execute( $context, function () use ( &$callCount ) {
			++$callCount;
			return new \WP_REST_Response( [ 'data' => 'fresh' ] );
		} );

		$this->assertInstanceOf( \WP_REST_Response::class, $result2 );
		$this->assertSame( 1, $callCount, 'Dispatch should not be called again for cached response' );
	}

	public function testBypassesCacheForNonGet(): void {
		$cache     = new TransientCache();
		$pipeline  = new MiddlewarePipeline();
		$pipeline->add( new CacheMiddleware( $cache ) );
		$callCount = 0;

		$context = new RequestContext();
		$request = new \WP_REST_Request( 'POST', '/saltus-framework/v1/settings/movie' );
		$context->set_rest_request( $request );
		$context->set_attribute( 'cache_ttl', 300 );

		$result1 = $pipeline->execute( $context, function () use ( &$callCount ) {
			++$callCount;
			return new \WP_REST_Response( [ 'updated' => true ] );
		} );

		$this->assertSame( 1, $callCount );

		$result2 = $pipeline->execute( $context, function () use ( &$callCount ) {
			++$callCount;
			return new \WP_REST_Response( [ 'updated' => true ] );
		} );

		$this->assertSame( 2, $callCount, 'POST requests should not be cached' );
	}

	public function testPassesErrorResponsesWithoutCaching(): void {
		$cache     = new TransientCache();
		$pipeline  = new MiddlewarePipeline();
		$pipeline->add( new CacheMiddleware( $cache ) );

		$context = new RequestContext();
		$context->set_args( [ 'bad' => 'args' ] );
		$context->set_tool_metadata( [ 'name' => 'bad_tool' ] );

		$result = $pipeline->execute( $context, function () {
			return new \WP_Error( 'error', 'Something went wrong' );
		} );

		$this->assertInstanceOf( \WP_Error::class, $result );

		$cache_key = 'saltus_mcp_' . hash( 'sha256', wp_json_encode( [
			'tool'   => 'bad_tool',
			'args'   => [ 'bad' => 'args' ],
			'user'   => 1,
			'locale' => 'en_US',
		] ) );

		$this->assertNull( $cache->get( $cache_key ) );
	}
}
