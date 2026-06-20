<?php

namespace Saltus\WP\Framework\Tests\Rest;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Rest\ReorderController;
use WP_REST_Request;
use WP_Error;

require_once __DIR__ . '/functions.php';

class ReorderControllerTest extends TestCase {
	private ReorderController $controller;

	protected function setUp(): void {
		global $wp_rest_routes_registered, $wp_current_user_can, $wp_posts;
		$wp_rest_routes_registered = [];
		$wp_current_user_can       = true;
		$wp_posts                  = [];

		$this->controller = new ReorderController();
	}

	public function testConstructorSetsNamespaceAndRestBase(): void {
		$this->assertSame( 'saltus-framework/v1', $this->getProtectedProperty( $this->controller, 'namespace' ) );
		$this->assertSame( 'reorder', $this->getProtectedProperty( $this->controller, 'rest_base' ) );
	}

	private function getProtectedProperty( object $object, string $property ): mixed {
		return ( new \ReflectionProperty( $object, $property ) )->getValue( $object );
	}

	public function testRegisterRoutes(): void {
		global $wp_rest_routes_registered;

		$this->controller->register_routes();

		$this->assertCount( 1, $wp_rest_routes_registered );
		$this->assertStringContainsString( 'reorder', $wp_rest_routes_registered[0]['route'] );
		$this->assertSame( 'POST', $wp_rest_routes_registered[0]['args']['methods'] );
	}

	public function testCreateItemPermissionsCheckReturnsTrueWhenAuthorized(): void {
		global $wp_current_user_can;
		$wp_current_user_can = true;

		$result = $this->controller->create_item_permissions_check( new WP_REST_Request() );
		$this->assertTrue( $result );
	}

	public function testCreateItemPermissionsCheckReturnsErrorWhenUnauthorized(): void {
		global $wp_current_user_can;
		$wp_current_user_can = false;

		$result = $this->controller->create_item_permissions_check( new WP_REST_Request() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	public function testCreateItemReturnsErrorWhenNoItemsProvided(): void {
		$request = new WP_REST_Request( [] );
		$result  = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_empty_data', $result->get_error_code() );
	}

	public function testCreateItemReturnsErrorWhenItemsIsEmpty(): void {
		$request = new WP_REST_Request( [ 'items' => [] ] );
		$result  = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_empty_data', $result->get_error_code() );
	}

	public function testCreateItemSkipsNonExistentPosts(): void {
		global $wp_posts;
		$wp_posts[1] = new \WP_Post( [ 'ID' => 1, 'menu_order' => 0 ] );

		$request = new WP_REST_Request( [
			'items' => [
				[ 'id' => 1, 'menu_order' => 2 ],
				[ 'id' => 999, 'menu_order' => 1 ],
			],
		] );
		$result  = $this->controller->create_item( $request );

		$response = rest_ensure_response( $result );
		$data     = $response->get_data();

		if ( is_array( $data ) ) {
			$this->assertArrayHasKey( 'results', $data );
			$this->assertCount( 2, $data['results'] );
			$this->assertSame( 2, $data['total'] );
			$this->assertSame( 1, $data['updated'] );
			$this->assertSame( 'updated', $data['results'][0]['status'] );
			$this->assertSame( 'skipped', $data['results'][1]['status'] );
			$this->assertSame( 'Post not found', $data['results'][1]['reason'] );
		}
	}

	public function testCreateItemUpdatesMenuOrder(): void {
		global $wp_posts;
		$wp_posts[1] = new \WP_Post( [ 'ID' => 1, 'menu_order' => 0 ] );
		$wp_posts[2] = new \WP_Post( [ 'ID' => 2, 'menu_order' => 0 ] );
		$wp_posts[3] = new \WP_Post( [ 'ID' => 3, 'menu_order' => 0 ] );

		$request = new WP_REST_Request( [
			'items' => [
				[ 'id' => 1, 'menu_order' => 1 ],
				[ 'id' => 2, 'menu_order' => 2 ],
				[ 'id' => 3, 'menu_order' => 3 ],
			],
		] );
		$result  = $this->controller->create_item( $request );

		$response = rest_ensure_response( $result );
		$data     = $response->get_data();

		if ( is_array( $data ) ) {
			$this->assertSame( 3, $data['total'] );
			$this->assertSame( 3, $data['updated'] );
			$this->assertSame( 'updated', $data['results'][0]['status'] );
		}

		$this->assertSame( 1, $wp_posts[1]->menu_order );
		$this->assertSame( 2, $wp_posts[2]->menu_order );
		$this->assertSame( 3, $wp_posts[3]->menu_order );
	}
}
