<?php

namespace Saltus\WP\Framework\Tests\Rest;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Rest\MetaController;
use Saltus\WP\Framework\Modeler;
use WP_REST_Request;
use WP_Error;

require_once __DIR__ . '/functions.php';

class MetaControllerTest extends TestCase {
	private MetaController $controller;
	private Modeler $modeler;

	protected function setUp(): void {
		global $wp_rest_routes_registered, $wp_current_user_can;
		$wp_rest_routes_registered = [];
		$wp_current_user_can       = true;

		$this->modeler    = $this->createMock( Modeler::class );
		$this->controller = new MetaController( $this->modeler );
	}

	public function testConstructorSetsNamespaceAndRestBase(): void {
		$this->assertSame( 'saltus-framework/v1', $this->getProtectedProperty( $this->controller, 'namespace' ) );
		$this->assertSame( 'meta', $this->getProtectedProperty( $this->controller, 'rest_base' ) );
	}

	private function getProtectedProperty( object $object, string $property ): mixed {
		return ( new \ReflectionProperty( $object, $property ) )->getValue( $object );
	}

	public function testRegisterRoutes(): void {
		global $wp_rest_routes_registered;

		$this->controller->register_routes();

		$this->assertCount( 1, $wp_rest_routes_registered );
		$this->assertStringContainsString( 'meta', $wp_rest_routes_registered[0]['route'] );
	}

	public function testGetItemsPermissionsCheckReturnsTrueWhenAuthorized(): void {
		global $wp_current_user_can;
		$wp_current_user_can = true;

		$result = $this->controller->get_items_permissions_check( new WP_REST_Request() );
		$this->assertTrue( $result );
	}

	public function testGetItemsPermissionsCheckReturnsErrorWhenUnauthorized(): void {
		global $wp_current_user_can;
		$wp_current_user_can = false;

		$result = $this->controller->get_items_permissions_check( new WP_REST_Request() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	public function testGetItemsReturnsErrorWhenModelNotFound(): void {
		$this->modeler->method( 'get_models' )->willReturn( [] );

		$request = new WP_REST_Request( [ 'post_type' => 'nonexistent' ] );
		$result  = $this->controller->get_items( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'model_not_found', $result->get_error_code() );
	}

	public function testGetItemsReturnsErrorWhenModelTypeIsNotPostType(): void {
		$taxonomy_model = $this->createModelMock( 'taxonomy' );
		$this->modeler->method( 'get_models' )->willReturn( [ 'category' => $taxonomy_model ] );

		$request = new WP_REST_Request( [ 'post_type' => 'category' ] );
		$result  = $this->controller->get_items( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_model_type', $result->get_error_code() );
	}

	public function testGetItemsReturnsEmptyMetaWhenNoMetaDefined(): void {
		$model = $this->createModelMock( 'post_type', [] );
		$this->modeler->method( 'get_models' )->willReturn( [ 'book' => $model ] );

		$request = new WP_REST_Request( [ 'post_type' => 'book' ] );
		$result  = $this->controller->get_items( $request );

		$this->assertNotInstanceOf( WP_Error::class, $result );

		$data = rest_ensure_response( $result )->get_data();
		if ( is_array( $data ) ) {
			$this->assertSame( 'book', $data['post_type'] );
			$this->assertSame( [], $data['meta'] );
		}
	}

	public function testGetItemsReturnsMetaWhenDefined(): void {
		$meta_fields = [
			'author' => [ 'type' => 'text' ],
			'isbn'   => [ 'type' => 'text' ],
		];
		$model = $this->createModelMock( 'post_type', $meta_fields );
		$this->modeler->method( 'get_models' )->willReturn( [ 'book' => $model ] );

		$request = new WP_REST_Request( [ 'post_type' => 'book' ] );
		$result  = $this->controller->get_items( $request );

		$this->assertNotInstanceOf( WP_Error::class, $result );

		$data = rest_ensure_response( $result )->get_data();
		if ( is_array( $data ) ) {
			$this->assertSame( 'book', $data['post_type'] );
			$this->assertSame( $meta_fields, $data['meta'] );
		}
	}

	/**
	 * @return \Saltus\WP\Framework\Models\Model&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function createModelMock( string $type, ?array $meta = null ) {
		$model = $this->createMock( \Saltus\WP\Framework\Models\Model::class );
		$model->method( 'get_type' )->willReturn( $type );

		if ( $meta !== null ) {
			$model->args = [ 'meta' => $meta ];
		} else {
			$model->args = [];
		}

		return $model;
	}
}
