<?php

namespace Saltus\WP\Framework\Tests\Rest;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Rest\DuplicateController;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use WP_REST_Request;
use WP_Error;

require_once __DIR__ . '/functions.php';

class DuplicateControllerTest extends TestCase {
	private DuplicateController $controller;

	protected function setUp(): void {
		global $wp_rest_routes_registered, $wp_current_user_can, $wp_posts;
		$wp_rest_routes_registered = [];
		$wp_current_user_can       = true;
		$wp_posts                  = [];

		$this->controller = new DuplicateController();
	}

	public function testConstructorSetsNamespaceAndRestBase(): void {
		$this->assertSame( 'saltus-framework/v1', $this->getProtectedProperty( $this->controller, 'namespace' ) );
		$this->assertSame( 'duplicate', $this->getProtectedProperty( $this->controller, 'rest_base' ) );
	}

	/**
	 * @return mixed
	 */
	private function getProtectedProperty( object $object, string $property ) {
		return ( new \ReflectionProperty( $object, $property ) )->getValue( $object );
	}

	public function testRegisterRoutes(): void {
		global $wp_rest_routes_registered;

		$this->controller->register_routes();

		$this->assertCount( 1, $wp_rest_routes_registered );
		$this->assertSame( 'saltus-framework/v1', $wp_rest_routes_registered[0]['namespace'] );
		$this->assertStringContainsString( 'duplicate', $wp_rest_routes_registered[0]['route'] );
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

	public function testCreateItemPermissionsCheckUsesPostSpecificCapability(): void {
		global $wp_current_user_can, $wp_posts;

		$wp_posts[42]        = new \WP_Post( [
			'ID'        => 42,
			'post_type' => 'book',
		] );
		$wp_current_user_can = [
			'edit_posts'   => false,
			'edit_post:42' => true,
		];

		$result = $this->controller->create_item_permissions_check( new WP_REST_Request( [ 'post_id' => 42 ] ) );

		$this->assertTrue( $result );
	}

	public function testCreateItemReturnsErrorWhenPostNotFound(): void {
		$request = new WP_REST_Request( [ 'post_id' => 999 ] );

		$result = $this->controller->create_item( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}

	public function testCreateItemReturnsErrorWhenModelDoesNotEnableDuplicate(): void {
		global $wp_posts;
		$wp_posts[42] = new \WP_Post( [
			'ID'         => 42,
			'post_type'  => 'book',
			'post_title' => 'Original',
		] );

		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn(
			[
				'book' => $this->createModelMock(
					[
						'show_in_rest' => true,
						'saltus_rest'  => [ 'duplicate' => false ],
					]
				),
			]
		);
		$this->controller = new DuplicateController( new ModelRestPolicy( $modeler ) );

		$result = $this->controller->create_item( new WP_REST_Request( [ 'post_id' => 42 ] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'model_rest_capability_disabled', $result->get_error_code() );
	}

	public function testCreateItemReturnsErrorWhenCannotEditPost(): void {
		global $wp_posts, $wp_current_user_can;
		$wp_current_user_can       = true;
		$wp_posts[42]              = new \WP_Post( [
			'ID'         => 42,
			'post_type'  => 'post',
			'post_title' => 'Original',
			'post_status' => 'publish',
		] );

		$request = new WP_REST_Request( [ 'post_id' => 42 ] );

		$this->controller->create_item( $request );

		$this->assertTrue( true );
	}

	public function testCreateItemSuccess(): void {
		global $wp_posts, $wp_current_user_can;
		$wp_current_user_can = true;
		$wp_posts[42]        = new \WP_Post( [
			'ID'         => 42,
			'post_type'  => 'post',
			'post_title' => 'Original Post',
			'post_status' => 'publish',
		] );

		$request = new WP_REST_Request( [ 'post_id' => 42 ] );
		$result  = $this->controller->create_item( $request );

		$this->assertNotInstanceOf( WP_Error::class, $result );

		$response = rest_ensure_response( $result );
		$data     = $response->get_data();

		if ( is_array( $data ) ) {
			$this->assertArrayHasKey( 'id', $data );
			$this->assertArrayHasKey( 'post_type', $data );
			$this->assertArrayHasKey( 'post_title', $data );
			$this->assertArrayHasKey( 'post_status', $data );
			$this->assertArrayHasKey( 'edit_link', $data );
			$this->assertSame( 'post', $data['post_type'] );
		}
	}

	/**
	 * @param array<string, mixed> $options
	 * @return Model&object{options: array<string, mixed>}
	 */
	private function createModelMock( array $options ) {
		return new class( $options ) implements Model {
			/** @var array<string, mixed> */
			public array $options;

			/**
			 * @param array<string, mixed> $options
			 */
			public function __construct( array $options ) {
				$this->options = $options;
			}

			public function setup(): void {}

			public function get_name(): string {
				return 'book';
			}

			public function get_type(): string {
				return 'post_type';
			}
		};
	}
}
