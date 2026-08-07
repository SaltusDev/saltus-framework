<?php
namespace Saltus\WP\Framework\Tests\MCP\Middleware;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Middleware\MiddlewareInterface;
use Saltus\WP\Framework\MCP\Middleware\MiddlewarePipeline;
use Saltus\WP\Framework\MCP\Middleware\RequestContext;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\MCP\Middleware\MiddlewarePipeline
 */
class MiddlewarePipelineTest extends TestCase {

	public function testExecutesSingleStage(): void {
		$pipeline = new MiddlewarePipeline();
		$pipeline->add( new class implements MiddlewareInterface {
			public function handle( RequestContext $context, callable $next ) {
				return new \WP_REST_Response( [ 'handled' => true ] );
			}
		} );

		$context = new RequestContext();
		$result  = $pipeline->execute( $context, function () {
			return new \WP_REST_Response( [ 'dispatched' => true ] );
		} );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertTrue( $data['handled'] );
	}

	public function testExecutesMultipleStagesInOrder(): void {
		$pipeline = new MiddlewarePipeline();
		$GLOBALS['_test_log'] = [];

		$pipeline->add( new class implements MiddlewareInterface {
			public function handle( RequestContext $context, callable $next ) {
				$GLOBALS['_test_log'][] = 'first';
				return $next( $context );
			}
		} );

		$pipeline->add( new class implements MiddlewareInterface {
			public function handle( RequestContext $context, callable $next ) {
				$GLOBALS['_test_log'][] = 'second';
				return $next( $context );
			}
		} );

		$pipeline->add( new class implements MiddlewareInterface {
			public function handle( RequestContext $context, callable $next ) {
				$GLOBALS['_test_log'][] = 'third';
				return $next( $context );
			}
		} );

		$context = new RequestContext();
		$pipeline->execute( $context, function () {
			$GLOBALS['_test_log'][] = 'dispatch';
			return new \WP_REST_Response( [ 'ok' => true ] );
		} );

		$this->assertSame( [ 'first', 'second', 'third', 'dispatch' ], $GLOBALS['_test_log'] );
		unset( $GLOBALS['_test_log'] );
	}

	public function testShortCircuitsOnError(): void {
		$pipeline = new MiddlewarePipeline();
		$GLOBALS['_test_log'] = [];

		$pipeline->add( new class implements MiddlewareInterface {
			public function handle( RequestContext $context, callable $next ) {
				$GLOBALS['_test_log'][] = 'pass';
				return $next( $context );
			}
		} );

		$pipeline->add( new class implements MiddlewareInterface {
			public function handle( RequestContext $context, callable $next ) {
				$GLOBALS['_test_log'][] = 'block';
				return new \WP_Error( 'blocked', 'Blocked' );
			}
		} );

		$pipeline->add( new class implements MiddlewareInterface {
			public function handle( RequestContext $context, callable $next ) {
				$GLOBALS['_test_log'][] = 'should_not_reach';
				return $next( $context );
			}
		} );

		$context = new RequestContext();
		$result  = $pipeline->execute( $context, function () {
			$GLOBALS['_test_log'][] = 'dispatch';
			return new \WP_REST_Response( [ 'ok' => true ] );
		} );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'blocked', $result->get_error_code() );
		$this->assertSame( [ 'pass', 'block' ], $GLOBALS['_test_log'] );
		unset( $GLOBALS['_test_log'] );
	}

	public function testExecutesWithNoStages(): void {
		$pipeline = new MiddlewarePipeline();

		$context = new RequestContext();
		$result  = $pipeline->execute( $context, function () {
			return new \WP_REST_Response( [ 'direct' => true ] );
		} );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertTrue( $data['direct'] );
	}

	public function testContextIsMutableThroughChain(): void {
		$pipeline = new MiddlewarePipeline();

		$pipeline->add( new class implements MiddlewareInterface {
			public function handle( RequestContext $context, callable $next ) {
				$context->set_attribute( 'trace', 'added' );
				return $next( $context );
			}
		} );

		$context = new RequestContext();
		$pipeline->execute( $context, function ( RequestContext $ctx ) {
			$this->assertSame( 'added', $ctx->get_attribute( 'trace' ) );
			return new \WP_REST_Response( [ 'ok' => true ] );
		} );
	}
}
