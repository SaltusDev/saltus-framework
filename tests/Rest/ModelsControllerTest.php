<?php

namespace Saltus\WP\Framework\Tests\Rest;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Rest\ModelsController;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Config\NoFile;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Models\Taxonomy;
use WP_REST_Request;
use WP_Error;

require_once __DIR__ . '/functions.php';

class ModelsControllerTest extends TestCase {
	private ModelsController $controller;
	private Modeler $modeler;

	protected function setUp(): void {
		global $wp_rest_routes_registered, $wp_current_user_can;
		$wp_rest_routes_registered = [];
		$wp_current_user_can       = true;

		$this->modeler    = $this->createStub( Modeler::class );
		$this->controller = new ModelsController( $this->modeler );
	}

	public function testConstructorSetsNamespaceAndRestBase(): void {
		$this->assertSame( 'saltus-framework/v1', $this->getProtectedProperty( $this->controller, 'namespace' ) );
		$this->assertSame( 'models', $this->getProtectedProperty( $this->controller, 'rest_base' ) );
	}

	private function getProtectedProperty( object $object, string $property ): mixed {
		return ( new \ReflectionProperty( $object, $property ) )->getValue( $object );
	}

	public function testRegisterRoutesRegistersTwoRoutes(): void {
		global $wp_rest_routes_registered;

		$this->controller->register_routes();

		$this->assertCount( 2, $wp_rest_routes_registered );
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

	public function testGetItemsReturnsEmptyArrayWhenNoModels(): void {
		$this->modeler->method( 'get_models' )->willReturn( [] );

		$result = $this->controller->get_items( new WP_REST_Request() );

		$data = rest_ensure_response( $result )->get_data();
		$this->assertSame( [], $data );
	}

	public function testGetItemsReturnsPreparedModels(): void {
		$model1 = $this->createModelMock( 'post_type', 'Books', 'Books', 'book', 'post_type', [ 'public' => true, 'show_in_rest' => true ] );
		$model2 = $this->createModelMock( 'taxonomy', 'Categories', 'Categories', 'category', 'taxonomy', [ 'public' => true, 'show_in_rest' => true ] );

		$this->modeler->method( 'get_models' )->willReturn( [
			'book'     => $model1,
			'category' => $model2,
		] );

		$result = $this->controller->get_items( new WP_REST_Request() );

		$data = rest_ensure_response( $result )->get_data();
		$this->assertIsArray( $data );
		$this->assertCount( 2, $data );
	}

	public function testGetItemReturnsErrorWhenModelNotFound(): void {
		$this->modeler->method( 'get_models' )->willReturn( [] );

		$request = new WP_REST_Request( [ 'post_type' => 'nonexistent' ] );
		$result  = $this->controller->get_item( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'model_not_found', $result->get_error_code() );
	}

	public function testGetItemReturnsPreparedModel(): void {
		$args   = [ 'public' => true, 'show_in_rest' => true ];
		$model  = $this->createModelMock( 'post_type', 'Books', 'Books', 'book', 'post_type', $args, 'Featured book model', true);
		$this->modeler->method( 'get_models' )->willReturn( [ 'book' => $model ] );

		$request = new WP_REST_Request( [ 'post_type' => 'book' ] );
		$result  = $this->controller->get_item( $request );

		$data = rest_ensure_response( $result )->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'book', $data['name'] );
		$this->assertSame( 'post_type', $data['type'] );
		$this->assertSame( 'Books', $data['label_singular'] );
		$this->assertSame( 'Books', $data['label_plural'] );
	}

	public function testGetItemReturnsTaxonomyMetadata(): void {
		$taxonomy = new Taxonomy(
			new NoFile(
				[
					'type'         => 'category',
					'name'         => 'genre',
					'associations' => [ 'movie', 'book' ],
					'options'      => [
						'rest_base' => 'genres',
					],
				]
			)
		);
		$taxonomy->setup();

		$this->modeler->method( 'get_models' )->willReturn( [ 'genre' => $taxonomy ] );

		$request = new WP_REST_Request( [ 'post_type' => 'genre' ] );
		$result  = $this->controller->get_item( $request );

		$data = rest_ensure_response( $result )->get_data();
		$this->assertSame( 'genre', $data['name'] );
		$this->assertSame( 'taxonomy', $data['type'] );
		$this->assertSame( 'genres', $data['rest_base'] );
		$this->assertSame( [ 'movie', 'book' ], $data['associations'] );
		$this->assertTrue( $data['hierarchical'] );
	}

	/**
	 * @return Model&object{name: string, one: string, many: string, type: string, description: string, featured_image: bool, options: array<string, mixed>}
	 */
	private function createModelMock(
		string $type,
		string $one = '',
		string $many = '',
		string $name = '',
		string $getType = 'post_type',
		array $options = [],
		string $description = '',
		bool $featuredImage = true
	) {
		return new class( $type, $one, $many, $name, $getType, $options, $description, $featuredImage ) implements Model {
			public string $type;
			public string $one;
			public string $many;
			public string $name;
			public string $description;
			public bool $featured_image;
			/** @var array<string, mixed> */
			public array $options;
			private string $getType;

			/**
			 * @param array<string, mixed> $options
			 */
			public function __construct(
				string $type,
				string $one,
				string $many,
				string $name,
				string $getType,
				array $options,
				string $description,
				bool $featuredImage
			) {
				$this->type           = $type;
				$this->one            = $one;
				$this->many           = $many;
				$this->name           = $name;
				$this->getType        = $getType;
				$this->options        = $options;
				$this->description    = $description;
				$this->featured_image = $featuredImage;
			}

			public function setup(): void {}

			public function get_name(): string {
				return $this->name;
			}

			public function get_type(): string {
				return $this->getType;
			}
		};
	}
}
