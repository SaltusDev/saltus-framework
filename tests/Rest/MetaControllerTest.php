<?php

namespace Saltus\WP\Framework\Tests\Rest;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Rest\MetaController;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
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

		$this->modeler    = $this->createStub( Modeler::class );
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

		$this->assertCount( 2, $wp_rest_routes_registered );
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

	public function testGetAllItemsReturnsPostTypeMetaFields(): void {
		$book_meta  = [ 'isbn' => [ 'type' => 'text' ] ];
		$movie_meta = [];

		$this->modeler->method( 'get_models' )->willReturn(
			[
				'book'     => $this->createModelMock( 'post_type', $book_meta, 'Book', 'Books' ),
				'genre'    => $this->createModelMock( 'taxonomy' ),
				'movie'    => $this->createModelMock( 'post_type', $movie_meta, 'Movie', 'Movies' ),
			]
		);

		$result = $this->controller->get_all_items( new WP_REST_Request() );

		$this->assertNotInstanceOf( WP_Error::class, $result );

		$data = rest_ensure_response( $result )->get_data();
		if ( is_array( $data ) ) {
			$this->assertCount( 2, $data['post_types'] );
			$this->assertSame( 'book', $data['post_types'][0]['post_type'] );
			$this->assertSame( 'Book', $data['post_types'][0]['label_singular'] );
			$this->assertSame( 'Books', $data['post_types'][0]['label_plural'] );
			$this->assertSame( $book_meta, $data['post_types'][0]['meta'] );
			$this->assertSame( 'movie', $data['post_types'][1]['post_type'] );
			$this->assertSame( [], $data['post_types'][1]['meta'] );
		}
	}

	public function testGetAllItemsFiltersModelsWhenPolicyIsInjected(): void {
		$book_meta = [ 'isbn' => [ 'type' => 'text' ] ];

		$this->modeler->method( 'get_models' )->willReturn(
			[
				'book'   => $this->createModelMock(
					'post_type',
					$book_meta,
					'Book',
					'Books',
					[
						'show_in_rest' => true,
						'saltus_rest'  => [ 'meta' => true ],
					]
				),
				'movie'  => $this->createModelMock(
					'post_type',
					[],
					'Movie',
					'Movies',
					[
						'show_in_rest' => true,
						'saltus_rest'  => [ 'meta' => false ],
					]
				),
				'hidden' => $this->createModelMock(
					'post_type',
					[],
					'Hidden',
					'Hidden',
					[
						'show_in_rest' => false,
						'saltus_rest'  => true,
					]
				),
			]
		);
		$this->controller = new MetaController( $this->modeler, new ModelRestPolicy( $this->modeler ) );

		$result = $this->controller->get_all_items( new WP_REST_Request() );
		$data   = rest_ensure_response( $result )->get_data();

		$this->assertIsArray( $data );
		$this->assertCount( 1, $data['post_types'] );
		$this->assertSame( 'book', $data['post_types'][0]['post_type'] );
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

	public function testGetItemsReturnsNormalizedSerializedMetaFieldPaths(): void {
		$meta_fields = [
			'points_info' => [
				'data_type'         => 'serialize',
				'register_rest_api' => true,
				'sections'          => [
					[
						'id'     => 'location',
						'title'  => 'Location',
						'fields' => [
							'coordinates' => [
								'type'   => 'fieldset',
								'title'  => 'Coordinates',
								'fields' => [
									'latitude'  => [
										'type'  => 'number',
										'title' => 'Latitude',
									],
									'longitude' => [
										'type'  => 'number',
										'title' => 'Longitude',
									],
								],
							],
							'tooltipContent' => [
								'type'  => 'textarea',
								'title' => 'Tooltip',
							],
						],
					],
				],
			],
		];

		$model = $this->createModelMock( 'post_type', $meta_fields );
		$this->modeler->method( 'get_models' )->willReturn( [ 'point' => $model ] );

		$result = $this->controller->get_items( new WP_REST_Request( [ 'post_type' => 'point' ] ) );
		$data   = rest_ensure_response( $result )->get_data();

		$this->assertIsArray( $data );
		$this->assertSame( $meta_fields, $data['meta'] );
		$this->assertSame( 'points_info', $data['normalized']['rest_meta_keys'][0]['meta_key'] );
		$this->assertTrue( $data['normalized']['rest_meta_keys'][0]['serialized'] );
		$this->assertTrue( $data['normalized']['rest_meta_keys'][0]['writable_rest'] );
		$this->assertSame( 'object', $data['normalized']['rest_meta_keys'][0]['schema']['type'] );
		$this->assertArrayHasKey( 'coordinates', $data['normalized']['rest_meta_keys'][0]['schema']['properties'] );

		$fields = $this->indexNormalizedFieldsByPath( $data['normalized']['fields'] );

		$this->assertArrayHasKey( 'points_info.coordinates', $fields );
		$this->assertArrayHasKey( 'points_info.coordinates.latitude', $fields );
		$this->assertArrayHasKey( 'points_info.tooltipContent', $fields );
		$this->assertSame( 'object', $fields['points_info.coordinates']['type'] );
		$this->assertSame( 'number', $fields['points_info.coordinates.latitude']['type'] );
		$this->assertSame( 1, $fields['points_info.coordinates.latitude']['depth'] );
		$this->assertSame( 'points_info', $fields['points_info.coordinates.latitude']['meta_key'] );
		$this->assertSame( 'location', $fields['points_info.coordinates.latitude']['section_id'] );
	}

	public function testGetItemsReturnsNormalizedUnserializedRestMetaKeys(): void {
		$meta_fields = [
			'relationship_point' => [
				'register_rest_api' => true,
				'fields'            => [
					'globe_id' => [
						'type'  => 'number',
						'title' => 'Globe ID',
					],
					'globe_id_select' => [
						'type'    => 'select',
						'title'   => 'Globe',
						'options' => 'get_globes',
					],
				],
			],
		];

		$model = $this->createModelMock( 'post_type', $meta_fields );
		$this->modeler->method( 'get_models' )->willReturn( [ 'point' => $model ] );

		$result = $this->controller->get_items( new WP_REST_Request( [ 'post_type' => 'point' ] ) );
		$data   = rest_ensure_response( $result )->get_data();

		$this->assertIsArray( $data );

		$rest_meta_keys = array_column( $data['normalized']['rest_meta_keys'], null, 'meta_key' );
		$this->assertArrayHasKey( 'globe_id', $rest_meta_keys );
		$this->assertArrayHasKey( 'globe_id_select', $rest_meta_keys );
		$this->assertFalse( $rest_meta_keys['globe_id']['serialized'] );
		$this->assertSame( 'number', $rest_meta_keys['globe_id']['schema']['type'] );
		$this->assertSame( 'array', $rest_meta_keys['globe_id_select']['schema']['type'] );

		$fields = $this->indexNormalizedFieldsByPath( $data['normalized']['fields'] );

		$this->assertSame( 'globe_id', $fields['globe_id']['path'] );
		$this->assertSame( 'globe_id', $fields['globe_id']['meta_key'] );
		$this->assertFalse( $fields['globe_id']['serialized'] );
		$this->assertTrue( $fields['globe_id']['writable_rest'] );
		$this->assertSame( 'relationship_point', $fields['globe_id']['metabox_id'] );
	}

	/**
	 * @param list<array<string, mixed>> $fields
	 * @return array<string, array<string, mixed>>
	 */
	private function indexNormalizedFieldsByPath( array $fields ): array {
		$indexed = [];

		foreach ( $fields as $field ) {
			$indexed[ $field['path'] ] = $field;
		}

		return $indexed;
	}

	/**
	 * @return \Saltus\WP\Framework\Models\Model&object{args: array<string, mixed>}
	 */
	private function createModelMock( string $type, ?array $meta = null, string $label_singular = '', string $label_plural = '', array $options = [] ) {
		$args = [];

		if ( $meta !== null ) {
			$args['meta'] = $meta;
		}
		if ( $label_singular !== '' ) {
			$args['label_singular'] = $label_singular;
		}
		if ( $label_plural !== '' ) {
			$args['label_plural'] = $label_plural;
		}

		return new class( $type, $args, $options ) implements \Saltus\WP\Framework\Models\Model {
			/** @var array<string, mixed> */
			public array $args;
			/** @var array<string, mixed> */
			public array $options;
			private string $type;

			/**
			 * @param array<string, mixed> $args
			 * @param array<string, mixed> $options
			 */
			public function __construct( string $type, array $args, array $options ) {
				$this->type    = $type;
				$this->args    = $args;
				$this->options = $options;
			}

			public function setup(): void {}

			public function get_name(): string {
				return '';
			}

			public function get_type(): string {
				return $this->type;
			}
		};
	}
}
