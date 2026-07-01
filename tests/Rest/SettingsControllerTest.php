<?php

namespace Saltus\WP\Framework\Tests\Rest;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Rest\SettingsController;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use WP_REST_Request;
use WP_Error;

require_once __DIR__ . '/functions.php';

class SettingsControllerTest extends TestCase {
	private SettingsController $controller;

	protected function setUp(): void {
		global $wp_rest_routes_registered, $wp_current_user_can, $wp_options;
		$wp_rest_routes_registered = [];
		$wp_current_user_can       = true;
		$wp_options                = [];

		$this->controller = new SettingsController();
	}

	public function testConstructorSetsNamespaceAndRestBase(): void {
		$this->assertSame( 'saltus-framework/v1', $this->getProtectedProperty( $this->controller, 'namespace' ) );
		$this->assertSame( 'settings', $this->getProtectedProperty( $this->controller, 'rest_base' ) );
	}

	private function getProtectedProperty( object $object, string $property ): mixed {
		return ( new \ReflectionProperty( $object, $property ) )->getValue( $object );
	}

	public function testRegisterRoutes(): void {
		global $wp_rest_routes_registered;

		$this->controller->register_routes();

		$this->assertCount( 1, $wp_rest_routes_registered );
		$this->assertStringContainsString( 'settings', $wp_rest_routes_registered[0]['route'] );
	}

	public function testGetItemPermissionsCheckReturnsTrueWhenAuthorized(): void {
		global $wp_current_user_can;
		$wp_current_user_can = true;

		$result = $this->controller->get_item_permissions_check( new WP_REST_Request() );
		$this->assertTrue( $result );
	}

	public function testGetItemPermissionsCheckReturnsErrorWhenUnauthorized(): void {
		global $wp_current_user_can;
		$wp_current_user_can = false;

		$result = $this->controller->get_item_permissions_check( new WP_REST_Request() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	public function testUpdateItemPermissionsCheckReturnsTrueWhenAuthorized(): void {
		global $wp_current_user_can;
		$wp_current_user_can = true;

		$result = $this->controller->update_item_permissions_check( new WP_REST_Request() );
		$this->assertTrue( $result );
	}

	public function testUpdateItemPermissionsCheckReturnsErrorWhenUnauthorized(): void {
		global $wp_current_user_can;
		$wp_current_user_can = false;

		$result = $this->controller->update_item_permissions_check( new WP_REST_Request() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	public function testGetItemReturnsEmptySettingsByDefault(): void {
		$request = new WP_REST_Request( [ 'post_type' => 'book' ] );
		$result  = $this->controller->get_item( $request );

		$response = rest_ensure_response( $result );
		$data     = $response->get_data();

		if ( is_array( $data ) ) {
			$this->assertSame( 'book', $data['post_type'] );
			$this->assertSame( [], $data['settings'] );
		}
	}

	public function testGetItemReturnsNotFoundWhenModelDoesNotEnableSettings(): void {
		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn(
			[
				'book' => $this->createModelMock(
					[
						'show_in_rest' => true,
						'saltus_rest'  => [ 'settings' => false ],
					]
				),
			]
		);
		$this->controller = new SettingsController( new ModelRestPolicy( $modeler ) );

		$result = $this->controller->get_item( new WP_REST_Request( [ 'post_type' => 'book' ] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'model_not_found', $result->get_error_code() );
	}

	public function testGetItemReturnsSavedSettings(): void {
		global $wp_options;
		$saved = [ 'display_title' => 'yes', 'show_excerpt' => 'no' ];
		update_option( 'saltus_framework_settings_book', $saved );

		$request = new WP_REST_Request( [ 'post_type' => 'book' ] );
		$result  = $this->controller->get_item( $request );

		$response = rest_ensure_response( $result );
		$data     = $response->get_data();

		if ( is_array( $data ) ) {
			$this->assertSame( 'book', $data['post_type'] );
			$this->assertSame( $saved, $data['settings'] );
		}
	}

	public function testUpdateItemReturnsErrorWhenNoSettingsProvided(): void {
		$request = new WP_REST_Request( [ 'post_type' => 'book' ] );
		$request->set_json_params( [] );
		$result = $this->controller->update_item( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_empty_data', $result->get_error_code() );
	}

	public function testUpdateItemSavesAndReturnsSettings(): void {
		$request = new WP_REST_Request( [ 'post_type' => 'book' ] );
		$request->set_json_params( [ 'display_title' => 'yes', 'show_excerpt' => 'no' ] );
		$result = $this->controller->update_item( $request );

		$response = rest_ensure_response( $result );
		$data     = $response->get_data();

		if ( is_array( $data ) ) {
			$this->assertSame( 'book', $data['post_type'] );
			$this->assertSame( 'updated', $data['status'] );
			$this->assertArrayHasKey( 'settings', $data );
		}

		global $wp_options;
		$this->assertArrayHasKey( 'saltus_framework_settings_book', $wp_options );
	}

	public function testUpdateItemSanitizesKeys(): void {
		$request = new WP_REST_Request( [ 'post_type' => 'book' ] );
		$request->set_json_params( [ 'Display-Title' => 'yes' ] );
		$result = $this->controller->update_item( $request );

		$response = rest_ensure_response( $result );
		$data     = $response->get_data();

		if ( is_array( $data ) ) {
			$settings = $data['settings'];
			$this->assertArrayHasKey( 'display-title', $settings );
		}
	}

	public function testGetItemSchema(): void {
		$schema = $this->controller->get_item_schema();

		$this->assertIsArray( $schema );
		$this->assertSame( 'settings', $schema['title'] );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'post_type', $schema['properties'] );
		$this->assertArrayHasKey( 'settings', $schema['properties'] );
		$this->assertTrue( $schema['properties']['post_type']['readonly'] );
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
