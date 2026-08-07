<?php
namespace Saltus\WP\Framework\Tests\MCP\Middleware;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Audit\AuditLogger;
use Saltus\WP\Framework\MCP\Middleware\AuditMiddleware;
use Saltus\WP\Framework\MCP\Middleware\MiddlewarePipeline;
use Saltus\WP\Framework\MCP\Middleware\RequestContext;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\MCP\Middleware\AuditMiddleware
 */
class AuditMiddlewareTest extends TestCase {

	protected function setUp(): void {
		global $wpdb;
		$wpdb->inserts = [];
	}

	public function testRecordsSuccessAudit(): void {
		global $wpdb;

		$pipeline = new MiddlewarePipeline();
		$pipeline->add( new AuditMiddleware( new AuditLogger() ) );

		$context = new RequestContext();
		$context->set_tool_metadata( [ 'name' => 'list_models' ] );
		$context->set_args( [ 'type' => 'post_types' ] );

		$pipeline->execute( $context, function () {
			return new \WP_REST_Response( [ 'ok' => true ] );
		} );

		$this->assertNotEmpty( $wpdb->inserts );
		$this->assertSame( 'success', $wpdb->inserts[0]['data']['status'] );
		$this->assertStringContainsString( 'list_models', $wpdb->inserts[0]['data']['ability'] );
	}

	public function testRecordsErrorAudit(): void {
		global $wpdb;

		$pipeline = new MiddlewarePipeline();
		$pipeline->add( new AuditMiddleware( new AuditLogger() ) );

		$context = new RequestContext();
		$context->set_tool_metadata( [ 'name' => 'failing_tool' ] );

		$pipeline->execute( $context, function () {
			return new \WP_Error( 'some_error', 'Something failed' );
		} );

		$this->assertNotEmpty( $wpdb->inserts );
		$this->assertSame( 'error', $wpdb->inserts[0]['data']['status'] );
		$this->assertSame( 'some_error', $wpdb->inserts[0]['data']['error_code'] );
	}

	public function testRecordsRouteBasedAudit(): void {
		global $wpdb;

		$pipeline = new MiddlewarePipeline();
		$pipeline->add( new AuditMiddleware( new AuditLogger() ) );

		$context = new RequestContext();
		$request = new \WP_REST_Request( 'GET', '/saltus-framework/v1/models' );
		$context->set_rest_request( $request );
		$context->set_route( '/saltus-framework/v1/models' );

		$pipeline->execute( $context, function () {
			return new \WP_REST_Response( [ 'ok' => true ] );
		} );

		$this->assertNotEmpty( $wpdb->inserts );
		$this->assertStringContainsString( 'rest:', $wpdb->inserts[0]['data']['ability'] );
	}
}
