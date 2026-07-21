<?php
namespace Saltus\WP\Framework\Tests\MCP\Middleware;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Middleware\RequestContext;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\MCP\Middleware\RequestContext
 */
class RequestContextTest extends TestCase {

	public function testArgs(): void {
		$context = new RequestContext();
		$this->assertSame( [], $context->get_args() );

		$context->set_args( [ 'post_type' => 'movie' ] );
		$this->assertSame( [ 'post_type' => 'movie' ], $context->get_args() );
	}

	public function testRestRequest(): void {
		$context = new RequestContext();
		$this->assertNull( $context->get_rest_request() );

		$request = new \WP_REST_Request( 'GET', '/test' );
		$context->set_rest_request( $request );
		$this->assertSame( $request, $context->get_rest_request() );

		$context->set_rest_request( null );
		$this->assertNull( $context->get_rest_request() );
	}

	public function testResponse(): void {
		$context = new RequestContext();
		$this->assertNull( $context->get_response() );

		$response = new \WP_REST_Response( [ 'ok' => true ] );
		$context->set_response( $response );
		$this->assertSame( $response, $context->get_response() );

		$context->set_response( null );
		$this->assertNull( $context->get_response() );
	}

	public function testAttributes(): void {
		$context = new RequestContext();
		$this->assertSame( [], $context->get_attributes() );
		$this->assertNull( $context->get_attribute( 'nonexistent' ) );
		$this->assertSame( 'default', $context->get_attribute( 'nonexistent', 'default' ) );

		$context->set_attribute( 'key1', 'value1' );
		$context->set_attribute( 'key2', 42 );
		$this->assertSame( 'value1', $context->get_attribute( 'key1' ) );
		$this->assertSame( 42, $context->get_attribute( 'key2' ) );

		$context->set_attributes( [ 'new' => 'all' ] );
		$this->assertSame( [ 'new' => 'all' ], $context->get_attributes() );
	}

	public function testRoute(): void {
		$context = new RequestContext();
		$this->assertSame( '', $context->get_route() );

		$context->set_route( '/saltus-framework/v1/models' );
		$this->assertSame( '/saltus-framework/v1/models', $context->get_route() );
	}

	public function testToolMetadata(): void {
		$context = new RequestContext();
		$this->assertSame( [], $context->get_tool_metadata() );

		$context->set_tool_metadata( [ 'name' => 'list_models', 'parameters' => [] ] );
		$this->assertSame( [ 'name' => 'list_models', 'parameters' => [] ], $context->get_tool_metadata() );
	}
}
