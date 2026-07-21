<?php
namespace Saltus\WP\Framework\Tests\MCP\Middleware;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Middleware\MiddlewarePipeline;
use Saltus\WP\Framework\MCP\Middleware\ValidationMiddleware;
use Saltus\WP\Framework\MCP\Middleware\RequestContext;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\MCP\Middleware\ValidationMiddleware
 */
class ValidationMiddlewareTest extends TestCase {

	public function testValidatesToolArgs(): void {
		$pipeline = new MiddlewarePipeline();
		$pipeline->add( new ValidationMiddleware() );

		$context = new RequestContext();
		$context->set_args( [ 'post_type' => 'movie' ] );
		$context->set_tool_metadata( [
			'name'       => 'list_posts',
			'parameters' => [
				'post_type' => [ 'required' => true, 'type' => 'string' ],
			],
		] );

		$result = $pipeline->execute( $context, function () {
			return new \WP_REST_Response( [ 'ok' => true ] );
		} );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
	}

	public function testRejectsInvalidToolArgs(): void {
		$pipeline = new MiddlewarePipeline();
		$pipeline->add( new ValidationMiddleware() );

		$context = new RequestContext();
		$context->set_args( [ 'post_type' => 123 ] );
		$context->set_tool_metadata( [
			'name'       => 'list_posts',
			'parameters' => [
				'post_type' => [ 'required' => true, 'type' => 'string' ],
			],
		] );

		$result = $pipeline->execute( $context, function () {
			return new \WP_REST_Response( [ 'ok' => true ] );
		} );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_invalid_param', $result->get_error_code() );
	}

	public function testRejectsMissingRequiredToolArgs(): void {
		$pipeline = new MiddlewarePipeline();
		$pipeline->add( new ValidationMiddleware() );

		$context = new RequestContext();
		$context->set_args( [] );
		$context->set_tool_metadata( [
			'name'       => 'list_posts',
			'parameters' => [
				'post_type' => [ 'required' => true, 'type' => 'string' ],
			],
		] );

		$result = $pipeline->execute( $context, function () {
			return new \WP_REST_Response( [ 'ok' => true ] );
		} );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function testPassesThroughWithoutSchema(): void {
		$pipeline = new MiddlewarePipeline();
		$pipeline->add( new ValidationMiddleware() );

		$context = new RequestContext();
		$context->set_args( [ 'anything' => 'goes' ] );

		$result = $pipeline->execute( $context, function () {
			return new \WP_REST_Response( [ 'ok' => true ] );
		} );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
	}

	public function testValidatesRestArgs(): void {
		$pipeline = new MiddlewarePipeline();
		$pipeline->add( new ValidationMiddleware() );

		$context = new RequestContext();
		$request = new \WP_REST_Request( 'GET', '/saltus-framework/v1/models' );
		$request->set_param( 'post_type', 'movie' );

		$attrs = $request->get_attributes();
		$attrs['args'] = [
			'post_type' => [ 'required' => true, 'type' => 'string' ],
		];

		$context->set_rest_request( $request );

		$result = $pipeline->execute( $context, function () {
			return new \WP_REST_Response( [ 'ok' => true ] );
		} );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
	}
}
