<?php

namespace Saltus\WP\Framework\Tests\MCP\Tools;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Tools\DeletePost;
use WP_REST_Request;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\MCP\Tools\DeletePost
 */
class DeletePostTest extends TestCase {

	private DeletePost $tool;

	protected function setUp(): void {
		global $wp_post_type_objects, $wp_current_user_can;
		$wp_post_type_objects = [];
		$wp_current_user_can = true;

		$this->tool = new DeletePost();
	}

	public function testBasicGetters(): void {
		$this->assertSame( 'delete_post', $this->tool->get_name() );
		$this->assertStringContainsString( 'Delete (trash or force delete) a post by ID', $this->tool->get_description() );
		
		$params = $this->tool->get_parameters();
		$this->assertArrayHasKey( 'post_id', $params );
		$this->assertArrayHasKey( 'post_type', $params );
		$this->assertArrayHasKey( 'force', $params );
	}

	public function testBuildRestRequest(): void {
		// Default posts post_type
		$request = $this->tool->build_rest_request( [
			'post_id' => 123,
		] );

		$this->assertInstanceOf( WP_REST_Request::class, $request );
		$this->assertSame( 'DELETE', $request->get_method() );
		$this->assertSame( '/wp/v2/posts/123', $request->get_route() );
		$this->assertSame( [ 'force' => false ], $request->get_params() );

		// Custom post_type and force flag
		global $wp_post_type_objects;
		$wp_post_type_objects['book'] = (object) [
			'rest_base' => 'books',
		];

		$request = $this->tool->build_rest_request( [
			'post_id'   => 456,
			'post_type' => 'book',
			'force'     => true,
		] );

		$this->assertSame( '/wp/v2/books/456', $request->get_route() );
		$this->assertSame( [ 'force' => true ], $request->get_params() );
	}

	public function testHasPermission(): void {
		global $wp_current_user_can;

		// When user can delete post
		$wp_current_user_can = [
			'delete_post:123' => true,
		];
		$this->assertTrue( $this->tool->has_permission( [ 'post_id' => 123 ] ) );

		// When user cannot delete post
		$wp_current_user_can = [
			'delete_post:123' => false,
		];
		$this->assertFalse( $this->tool->has_permission( [ 'post_id' => 123 ] ) );

		// Empty post_id
		$this->assertFalse( $this->tool->has_permission( [] ) );
	}
}
