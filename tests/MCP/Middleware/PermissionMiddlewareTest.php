<?php
namespace Saltus\WP\Framework\Tests\MCP\Middleware;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Middleware\MiddlewarePipeline;
use Saltus\WP\Framework\MCP\Middleware\PermissionMiddleware;
use Saltus\WP\Framework\MCP\Middleware\RequestContext;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\ModelFactory;
use Saltus\WP\Framework\Rest\CapabilityPolicy;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\MCP\Middleware\PermissionMiddleware
 */
class PermissionMiddlewareTest extends TestCase {

	protected function setUp(): void {
		global $wp_current_user_can;
		$wp_current_user_can = true;
	}

	public function testAllowsWithSufficientPermission(): void {
		$modeler = new Modeler( $this->createStub( ModelFactory::class ) );
		$pipeline = new MiddlewarePipeline();
		$pipeline->add( new PermissionMiddleware( new CapabilityPolicy( $modeler ) ) );

		$context = new RequestContext();
		$request = new \WP_REST_Request( 'GET', '/saltus-framework/v1/health' );
		$context->set_rest_request( $request );

		$result = $pipeline->execute( $context, function () {
			return new \WP_REST_Response( [ 'ok' => true ] );
		} );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
	}

	public function testBlocksWithoutPermission(): void {
		global $wp_current_user_can;
		$wp_current_user_can = false;

		$modeler  = new Modeler( $this->createStub( ModelFactory::class ) );
		$pipeline = new MiddlewarePipeline();
		$pipeline->add( new PermissionMiddleware( new CapabilityPolicy( $modeler ) ) );

		$context = new RequestContext();
		$request = new \WP_REST_Request( 'GET', '/saltus-framework/v1/models' );
		$context->set_rest_request( $request );

		$result = $pipeline->execute( $context, function () {
			return new \WP_REST_Response( [ 'ok' => true ] );
		} );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	public function testAllowsToolWithPermissionCallback(): void {
		$modeler  = new Modeler( $this->createStub( ModelFactory::class ) );
		$pipeline = new MiddlewarePipeline();
		$pipeline->add( new PermissionMiddleware( new CapabilityPolicy( $modeler ) ) );

		$context = new RequestContext();
		$context->set_tool_metadata( [
			'name'           => 'list_models',
			'has_permission' => function () {
				return true;
			},
		] );

		$result = $pipeline->execute( $context, function () {
			return new \WP_REST_Response( [ 'ok' => true ] );
		} );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
	}

	public function testBlocksToolWithoutPermission(): void {
		$modeler  = new Modeler( $this->createStub( ModelFactory::class ) );
		$pipeline = new MiddlewarePipeline();
		$pipeline->add( new PermissionMiddleware( new CapabilityPolicy( $modeler ) ) );

		$context = new RequestContext();
		$context->set_tool_metadata( [
			'name'           => 'delete_post',
			'has_permission' => function () {
				return false;
			},
		] );

		$result = $pipeline->execute( $context, function () {
			return new \WP_REST_Response( [ 'ok' => true ] );
		} );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	public function testPassesThroughWithoutRequestOrTool(): void {
		$modeler  = new Modeler( $this->createStub( ModelFactory::class ) );
		$pipeline = new MiddlewarePipeline();
		$pipeline->add( new PermissionMiddleware( new CapabilityPolicy( $modeler ) ) );

		$context = new RequestContext();

		$result = $pipeline->execute( $context, function () {
			return new \WP_REST_Response( [ 'ok' => true ] );
		} );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
	}
}
