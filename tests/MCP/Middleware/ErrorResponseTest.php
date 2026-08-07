<?php
namespace Saltus\WP\Framework\Tests\MCP\Middleware;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Error\ErrorResponse;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\MCP\Error\ErrorResponse
 */
class ErrorResponseTest extends TestCase {

	public function testForbidden(): void {
		$error = ErrorResponse::forbidden( 'edit_posts', 'Assign edit_posts to your user.' );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'rest_forbidden', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] ?? null );
		$this->assertSame( 'Assign edit_posts to your user.', $error->get_error_data()['hint'] ?? '' );
	}

	public function testForbiddenMinimal(): void {
		$error = ErrorResponse::forbidden();

		$this->assertSame( 'rest_forbidden', $error->get_error_code() );
		$this->assertArrayNotHasKey( 'hint', $error->get_error_data() );
	}

	public function testNotFound(): void {
		$error = ErrorResponse::not_found( 'model', 'Model not registered.' );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'model_not_found', $error->get_error_code() );
		$this->assertSame( 404, $error->get_error_data()['status'] ?? null );
		$this->assertSame( 'Model not registered.', $error->get_error_data()['hint'] ?? '' );
	}

	public function testNotFoundMinimal(): void {
		$error = ErrorResponse::not_found();

		$this->assertSame( 'not_found', $error->get_error_code() );
	}

	public function testInvalid(): void {
		$error = ErrorResponse::invalid( 'post_type', 'Must be a string', 'Provide a valid post type slug.' );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'rest_invalid_param', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] ?? null );
		$this->assertSame( 'post_type', $error->get_error_data()['field'] ?? '' );
		$this->assertSame( 'Must be a string', $error->get_error_data()['reason'] ?? '' );
	}

	public function testRateLimited(): void {
		$error = ErrorResponse::rate_limited( 30 );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'rate_limited', $error->get_error_code() );
		$this->assertSame( 429, $error->get_error_data()['status'] ?? null );
		$this->assertSame( 30, $error->get_error_data()['retry_after'] ?? 0 );
	}

	public function testInternalError(): void {
		$error = ErrorResponse::internal_error( 'Something broke.', 'Contact support.' );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'rest_internal_error', $error->get_error_code() );
		$this->assertSame( 500, $error->get_error_data()['status'] ?? null );
		$this->assertSame( 'Contact support.', $error->get_error_data()['hint'] ?? '' );
	}

	public function testDispatchError(): void {
		$upstream = new \WP_Error( 'db_error', 'Database query failed.', [ 'status' => 503 ] );
		$error    = ErrorResponse::dispatch_error( $upstream );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'db_error', $error->get_error_code() );
		$this->assertSame( 503, $error->get_error_data()['status'] ?? null );
	}
}
