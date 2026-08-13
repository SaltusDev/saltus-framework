<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Meta\MetaFieldProvider;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use WP_Error;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * MetaFieldProvider flattens Codestar metabox config into the paths and JSON
 * schemas the REST and MCP layers expose.
 *
 * The central distinction is data_type: a 'serialize' box stores every field
 * inside one meta key and paths are dotted below it, while the default
 * 'unserialize' box gives each field its own top-level meta key. Getting that
 * wrong writes meta to the wrong key.
 *
 * @covers \Saltus\WP\Framework\Features\Meta\MetaFieldProvider
 */
class MetaFieldProviderTest extends TestCase {

	private MetaFieldProvider $provider;

	protected function setUp(): void {
		$this->provider = new MetaFieldProvider();
	}

	/** @param array<string, mixed> $args */
	private function model( array $args, string $type = 'post_type' ): Model {
		$model = $this->createStub( Model::class );
		$model->method( 'get_type' )->willReturn( $type );
		$model->method( 'get_args' )->willReturn( $args );

		return $model;
	}

	/** @param array<string, Model> $models */
	private function modeler( array $models ): Modeler {
		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( $models );

		return $modeler;
	}

	/** Index normalized fields by their path for order-independent assertions. */
	private function by_path( array $normalized ): array {
		return array_combine( array_column( $normalized['fields'], 'path' ), $normalized['fields'] );
	}

	public function testAnEmptyMetaConfigNormalizesToNothing(): void {
		$this->assertSame(
			[ 'fields' => [], 'rest_meta_keys' => [] ],
			$this->provider->normalize_meta_fields( [] )
		);
	}

	public function testNonArrayBoxesAreSkipped(): void {
		$normalized = $this->provider->normalize_meta_fields( [ 'broken' => 'not-an-array', 'also' => 42 ] );

		$this->assertSame( [], $normalized['fields'] );
	}

	/**
	 * An unserialized box gives each field its own meta key, and the path is the
	 * bare field id with no box prefix.
	 */
	public function testUnserializedFieldsBecomeTopLevelMetaKeys(): void {
		$normalized = $this->provider->normalize_meta_fields(
			[
				'details' => [
					'fields' => [
						'isbn'  => [ 'type' => 'text', 'title' => 'ISBN' ],
						'pages' => [ 'type' => 'number', 'title' => 'Pages' ],
					],
				],
			]
		);

		$fields = $this->by_path( $normalized );

		$this->assertSame( [ 'isbn', 'pages' ], array_keys( $fields ) );
		$this->assertSame( 'isbn', $fields['isbn']['meta_key'] );
		$this->assertFalse( $fields['isbn']['serialized'] );
		$this->assertSame( 0, $fields['isbn']['depth'] );
		$this->assertSame( 'details', $fields['isbn']['metabox_id'] );
		$this->assertSame( 'ISBN', $fields['isbn']['label'] );
	}

	/**
	 * A serialized box stores everything under the box id, so paths are dotted
	 * beneath it and every field reports the same meta key.
	 */
	public function testSerializedFieldsArePathedUnderTheBoxMetaKey(): void {
		$normalized = $this->provider->normalize_meta_fields(
			[
				'book_data' => [
					'data_type' => 'serialize',
					'fields'    => [
						'isbn'  => [ 'type' => 'text', 'title' => 'ISBN' ],
						'pages' => [ 'type' => 'number', 'title' => 'Pages' ],
					],
				],
			]
		);

		$fields = $this->by_path( $normalized );

		$this->assertSame( [ 'book_data.isbn', 'book_data.pages' ], array_keys( $fields ) );
		$this->assertSame( 'book_data', $fields['book_data.isbn']['meta_key'] );
		$this->assertTrue( $fields['book_data.isbn']['serialized'] );
	}

	public function testASerializedBoxExposesOneRestMetaKeyForTheWholeBox(): void {
		$normalized = $this->provider->normalize_meta_fields(
			[
				'book_data' => [
					'data_type' => 'serialize',
					'fields'    => [ 'isbn' => [ 'type' => 'text' ], 'pages' => [ 'type' => 'number' ] ],
				],
			]
		);

		$this->assertCount( 1, $normalized['rest_meta_keys'] );

		$key = $normalized['rest_meta_keys'][0];
		$this->assertSame( 'book_data', $key['meta_key'] );
		$this->assertTrue( $key['serialized'] );
		$this->assertSame( 'object', $key['schema']['type'] );
		$this->assertSame( [ 'isbn', 'pages' ], array_keys( $key['schema']['properties'] ) );
	}

	public function testAnUnserializedBoxExposesOneRestMetaKeyPerField(): void {
		$normalized = $this->provider->normalize_meta_fields(
			[ 'details' => [ 'fields' => [ 'isbn' => [ 'type' => 'text' ], 'pages' => [ 'type' => 'number' ] ] ] ]
		);

		$this->assertSame( [ 'isbn', 'pages' ], array_column( $normalized['rest_meta_keys'], 'meta_key' ) );
		$this->assertSame( 'string', $normalized['rest_meta_keys'][0]['schema']['type'] );
		$this->assertSame( 'number', $normalized['rest_meta_keys'][1]['schema']['type'] );
	}

	/**
	 * Writability is opt-in and must be `true` exactly: a truthy value like 1
	 * does not open a field to REST writes.
	 */
	public function testRestWritabilityRequiresExactlyTrue(): void {
		$writable = $this->provider->normalize_meta_fields(
			[ 'box' => [ 'register_rest_api' => true, 'fields' => [ 'a' => [ 'type' => 'text' ] ] ] ]
		);
		$this->assertTrue( $writable['fields'][0]['writable_rest'] );

		foreach ( [ 1, 'yes', false, null ] as $value ) {
			$result = $this->provider->normalize_meta_fields(
				[ 'box' => [ 'register_rest_api' => $value, 'fields' => [ 'a' => [ 'type' => 'text' ] ] ] ]
			);
			$this->assertFalse(
				$result['fields'][0]['writable_rest'],
				sprintf( 'register_rest_api: %s must not grant write access.', var_export( $value, true ) )
			);
		}
	}

	public function testSectionsAreFlattenedAndCarryTheirIdentity(): void {
		$normalized = $this->provider->normalize_meta_fields(
			[
				'box' => [
					'sections' => [
						[
							'id'     => 'general',
							'title'  => 'General',
							'fields' => [ 'isbn' => [ 'type' => 'text' ] ],
						],
						[
							'id'     => 'advanced',
							'title'  => 'Advanced',
							'fields' => [ 'notes' => [ 'type' => 'textarea' ] ],
						],
					],
				],
			]
		);

		$fields = $this->by_path( $normalized );

		$this->assertSame( 'general', $fields['isbn']['section_id'] );
		$this->assertSame( 'General', $fields['isbn']['section_title'] );
		$this->assertSame( 'advanced', $fields['notes']['section_id'] );
	}

	public function testSectionIdFallsBackToTheArrayKey(): void {
		$normalized = $this->provider->normalize_meta_fields(
			[ 'box' => [ 'sections' => [ 'first' => [ 'fields' => [ 'isbn' => [ 'type' => 'text' ] ] ] ] ] ]
		);

		$this->assertSame( 'first', $normalized['fields'][0]['section_id'] );
		$this->assertSame( '', $normalized['fields'][0]['section_title'] );
	}

	/** Sections take precedence, so a stray top-level fields list is ignored. */
	public function testSectionsWinOverATopLevelFieldsList(): void {
		$normalized = $this->provider->normalize_meta_fields(
			[
				'box' => [
					'sections' => [ [ 'id' => 's', 'fields' => [ 'from_section' => [ 'type' => 'text' ] ] ] ],
					'fields'   => [ 'from_box' => [ 'type' => 'text' ] ],
				],
			]
		);

		$this->assertSame( [ 'from_section' ], array_column( $normalized['fields'], 'path' ) );
	}

	public function testEmptyAndMalformedSectionsAreSkipped(): void {
		$normalized = $this->provider->normalize_meta_fields(
			[
				'box' => [
					'sections' => [
						'broken' => 'not-an-array',
						'empty'  => [ 'fields' => [] ],
						'bad'    => [ 'fields' => 'not-an-array' ],
						'good'   => [ 'fields' => [ 'kept' => [ 'type' => 'text' ] ] ],
					],
				],
			]
		);

		$this->assertSame( [ 'kept' ], array_column( $normalized['fields'], 'path' ) );
	}

	/** A field without a type is presentational (a heading, a notice), not data. */
	public function testFieldsWithoutATypeAreNotData(): void {
		$normalized = $this->provider->normalize_meta_fields(
			[
				'box' => [
					'fields' => [
						'heading' => [ 'title' => 'Just a heading' ],
						'real'    => [ 'type' => 'text' ],
					],
				],
			]
		);

		$this->assertSame( [ 'real' ], array_column( $normalized['fields'], 'path' ) );
	}

	public function testAnExplicitFieldIdOverridesTheArrayKey(): void {
		$normalized = $this->provider->normalize_meta_fields(
			[ 'box' => [ 'fields' => [ 'ignored_key' => [ 'id' => 'real_id', 'type' => 'text' ] ] ] ]
		);

		$this->assertSame( 'real_id', $normalized['fields'][0]['path'] );
		$this->assertSame( 'real_id', $normalized['fields'][0]['field_id'] );
	}

	/** A numerically-keyed field with no id has no addressable name. */
	public function testNumericallyKeyedFieldsWithoutAnIdAreSkipped(): void {
		$normalized = $this->provider->normalize_meta_fields(
			[ 'box' => [ 'fields' => [ [ 'type' => 'text' ], [ 'id' => 'named', 'type' => 'text' ] ] ] ]
		);

		$this->assertSame( [ 'named' ], array_column( $normalized['fields'], 'path' ) );
	}

	public function testNestedFieldsAreRecursedWithIncreasingDepth(): void {
		$normalized = $this->provider->normalize_meta_fields(
			[
				'box' => [
					'data_type' => 'serialize',
					'fields'    => [
						'address' => [
							'type'   => 'fieldset',
							'fields' => [
								'street' => [ 'type' => 'text' ],
								'geo'    => [
									'type'   => 'fieldset',
									'fields' => [ 'lat' => [ 'type' => 'number' ] ],
								],
							],
						],
					],
				],
			]
		);

		$fields = $this->by_path( $normalized );

		$this->assertSame( 0, $fields['box.address']['depth'] );
		$this->assertSame( 1, $fields['box.address.street']['depth'] );
		$this->assertSame( 2, $fields['box.address.geo.lat']['depth'] );
		$this->assertSame( 'box', $fields['box.address.geo.lat']['meta_key'], 'Nested fields stay under the box meta key.' );
	}

	/**
	 * Even in an unserialized box, children of a field are dotted below their
	 * parent — the field id is the meta key and the nesting lives inside it.
	 */
	public function testNestedUnserializedFieldsArePathedBelowTheirParent(): void {
		$normalized = $this->provider->normalize_meta_fields(
			[
				'box' => [
					'fields' => [
						'address' => [
							'type'   => 'fieldset',
							'fields' => [ 'street' => [ 'type' => 'text' ] ],
						],
					],
				],
			]
		);

		$fields = $this->by_path( $normalized );

		$this->assertSame( 'address', $fields['address']['meta_key'] );
		$this->assertSame( 'address', $fields['address.street']['meta_key'] );
		$this->assertSame( 1, $fields['address.street']['depth'] );
	}

	public function testCodestarTypesMapToJsonSchemaTypes(): void {
		$expected = [
			'text'        => 'string',
			'textarea'    => 'string',
			'number'      => 'number',
			'switcher'    => 'boolean',
			'background'  => 'object',
			'color_group' => 'object',
			'fieldset'    => 'object',
			'group'       => 'object',
			'map'         => 'object',
			'media'       => 'array',
			'select'      => 'array',
			'repeater'    => 'array',
			'made_up'     => 'string',
		];

		foreach ( $expected as $codestar_type => $schema_type ) {
			$normalized = $this->provider->normalize_meta_fields(
				[ 'box' => [ 'fields' => [ 'f' => [ 'type' => $codestar_type ] ] ] ]
			);

			$this->assertSame( $schema_type, $normalized['fields'][0]['type'], $codestar_type . ' should map to ' . $schema_type );
			$this->assertSame( $codestar_type, $normalized['fields'][0]['codestar_type'] );
		}
	}

	public function testArrayTypedFieldsDeclareTheirItemType(): void {
		$select = $this->provider->normalize_meta_fields(
			[ 'box' => [ 'fields' => [ 'tags' => [ 'type' => 'select' ] ] ] ]
		);

		$this->assertSame( [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ], $select['fields'][0]['schema'] );
	}

	public function testRepeaterItemsAreObjectsDescribingTheirSubFields(): void {
		$normalized = $this->provider->normalize_meta_fields(
			[
				'box' => [
					'fields' => [
						'authors' => [
							'type'   => 'repeater',
							'fields' => [
								'name' => [ 'type' => 'text' ],
								'age'  => [ 'type' => 'number' ],
							],
						],
					],
				],
			]
		);

		$schema = $this->by_path( $normalized )['authors']['schema'];

		$this->assertSame( 'array', $schema['type'] );
		$this->assertSame( 'object', $schema['items']['type'] );
		$this->assertSame(
			[ 'name' => [ 'type' => 'string' ], 'age' => [ 'type' => 'number' ] ],
			$schema['items']['properties']
		);
	}

	public function testAnExplicitSchemaOverridesTheDerivedOne(): void {
		$custom     = [ 'type' => 'string', 'format' => 'uri', 'maxLength' => 200 ];
		$normalized = $this->provider->normalize_meta_fields(
			[ 'box' => [ 'fields' => [ 'link' => [ 'type' => 'text', 'schema' => $custom ] ] ] ]
		);

		$this->assertSame( $custom, $normalized['fields'][0]['schema'] );
	}

	public function testNestedObjectPropertiesAreDescribedRecursively(): void {
		$normalized = $this->provider->normalize_meta_fields(
			[
				'box' => [
					'data_type' => 'serialize',
					'fields'    => [
						'address' => [
							'type'   => 'fieldset',
							'fields' => [ 'street' => [ 'type' => 'text' ] ],
						],
					],
				],
			]
		);

		$properties = $normalized['rest_meta_keys'][0]['schema']['properties'];

		$this->assertSame( 'object', $properties['address']['type'] );
		$this->assertSame( [ 'street' => [ 'type' => 'string' ] ], $properties['address']['properties'] );
	}

	public function testLabelFallsBackFromTitleToLabelToEmpty(): void {
		$normalized = $this->provider->normalize_meta_fields(
			[
				'box' => [
					'fields' => [
						'a' => [ 'type' => 'text', 'title' => 'From title' ],
						'b' => [ 'type' => 'text', 'label' => 'From label' ],
						'c' => [ 'type' => 'text' ],
					],
				],
			]
		);

		$fields = $this->by_path( $normalized );

		$this->assertSame( 'From title', $fields['a']['label'] );
		$this->assertSame( 'From label', $fields['b']['label'] );
		$this->assertSame( '', $fields['c']['label'] );
	}

	public function testRawFieldConfigIsPreserved(): void {
		$field      = [ 'type' => 'text', 'title' => 'ISBN', 'custom_key' => 'kept' ];
		$normalized = $this->provider->normalize_meta_fields( [ 'box' => [ 'fields' => [ 'isbn' => $field ] ] ] );

		$this->assertSame( $field, $normalized['fields'][0]['raw'] );
	}

	/**
	 * Two boxes can legitimately declare the same field id; the REST layer keys
	 * by meta key, so a duplicate would register the same key twice.
	 */
	public function testDuplicateRestMetaKeysAreCollapsed(): void {
		$normalized = $this->provider->normalize_meta_fields(
			[
				'box_one' => [ 'fields' => [ 'shared' => [ 'type' => 'text' ] ] ],
				'box_two' => [ 'fields' => [ 'shared' => [ 'type' => 'number' ] ] ],
			]
		);

		$this->assertSame( [ 'shared' ], array_column( $normalized['rest_meta_keys'], 'meta_key' ) );
		$this->assertCount( 2, $normalized['fields'], 'Both field definitions still surface.' );
	}

	public function testASerializedBoxWithNoFieldsExposesNoRestMetaKey(): void {
		$normalized = $this->provider->normalize_meta_fields(
			[ 'empty_box' => [ 'data_type' => 'serialize', 'fields' => [] ] ]
		);

		$this->assertSame( [], $normalized['rest_meta_keys'] );
		$this->assertSame( [], $normalized['fields'] );
	}

	public function testAllPostTypeMetaDescribesEveryVisiblePostTypeModel(): void {
		$modeler = $this->modeler(
			[
				'book' => $this->model(
					[
						'label_singular' => 'Book',
						'label_plural'   => 'Books',
						'meta'           => [ 'box' => [ 'fields' => [ 'isbn' => [ 'type' => 'text' ] ] ] ],
					]
				),
			]
		);

		$result = $this->provider->all_post_type_meta( $modeler, null, fn(): bool => true );

		$this->assertCount( 1, $result );
		$this->assertSame( 'book', $result[0]['post_type'] );
		$this->assertSame( 'Book', $result[0]['label_singular'] );
		$this->assertSame( 'Books', $result[0]['label_plural'] );
		$this->assertSame( [ 'isbn' ], array_column( $result[0]['normalized']['fields'], 'path' ) );
	}

	public function testAllPostTypeMetaExcludesTaxonomies(): void {
		$modeler = $this->modeler(
			[
				'book'  => $this->model( [ 'meta' => [] ] ),
				'genre' => $this->model( [ 'meta' => [] ], 'taxonomy' ),
			]
		);

		$result = $this->provider->all_post_type_meta( $modeler, null, fn(): bool => true );

		$this->assertSame( [ 'book' ], array_column( $result, 'post_type' ) );
	}

	/** The visibility callback is the capability gate, so a false must exclude. */
	public function testAllPostTypeMetaHonoursTheVisibilityCallback(): void {
		$modeler = $this->modeler(
			[
				'book'    => $this->model( [ 'meta' => [] ] ),
				'private' => $this->model( [ 'meta' => [] ] ),
			]
		);

		$result = $this->provider->all_post_type_meta(
			$modeler,
			null,
			fn( string $post_type ): bool => $post_type === 'book'
		);

		$this->assertSame( [ 'book' ], array_column( $result, 'post_type' ) );
	}

	public function testAllPostTypeMetaToleratesAMissingOrMalformedMetaKey(): void {
		$modeler = $this->modeler(
			[
				'none'   => $this->model( [] ),
				'broken' => $this->model( [ 'meta' => 'not-an-array' ] ),
			]
		);

		$result = $this->provider->all_post_type_meta( $modeler, null, fn(): bool => true );

		$this->assertSame( [], $result[0]['meta'] );
		$this->assertSame( [], $result[1]['meta'] );
		$this->assertSame( '', $result[0]['label_singular'] );
	}

	public function testPostTypeMetaReturnsOneModelsFields(): void {
		$modeler = $this->modeler(
			[ 'book' => $this->model( [ 'meta' => [ 'box' => [ 'fields' => [ 'isbn' => [ 'type' => 'text' ] ] ] ] ] ) ]
		);

		$result = $this->provider->post_type_meta( $modeler, null, 'book' );

		$this->assertSame( 'book', $result['post_type'] );
		$this->assertSame( [ 'isbn' ], array_column( $result['normalized']['fields'], 'path' ) );
	}

	/**
	 * The 404 hint names the config change a developer has to make, which is the
	 * difference between a dead end and a fixable error.
	 */
	public function testPostTypeMetaReportsAnUnknownModelAsA404WithAConfigHint(): void {
		$result = $this->provider->post_type_meta( $this->modeler( [] ), null, 'ghost' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'model_not_found', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 404, $data['status'] );
		$this->assertStringContainsString( 'ghost', $data['hint'] );
		$this->assertStringContainsString( 'show_in_rest', $data['hint'] );
	}

	public function testPostTypeMetaRejectsATaxonomyModel(): void {
		$modeler = $this->modeler( [ 'genre' => $this->model( [ 'meta' => [] ], 'taxonomy' ) ] );

		$result = $this->provider->post_type_meta( $modeler, null, 'genre' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_model_type', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function testPostTypeMetaToleratesAMalformedMetaKey(): void {
		$modeler = $this->modeler( [ 'book' => $this->model( [ 'meta' => 'not-an-array' ] ) ] );

		$result = $this->provider->post_type_meta( $modeler, null, 'book' );

		$this->assertSame( [], $result['meta'] );
		$this->assertSame( [], $result['normalized']['fields'] );
	}
}
