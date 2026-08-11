<?php

namespace Saltus\WP\Framework\Tests\Models;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Meta\CodestarMeta;
use Saltus\WP\Framework\Features\Relationships\RelationshipDefinition;
use Saltus\WP\Framework\Models\Config\SchemaBuilder;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\Models\Config\SchemaBuilder
 */
class SchemaBuilderTest extends TestCase {

	public function testBuildReturnsCompleteSchema(): void {
		$builder = new SchemaBuilder();
		$schema  = $builder->build();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertArrayHasKey( 'name', $schema );
		$this->assertArrayHasKey( 'meta', $schema );
		$this->assertArrayHasKey( 'relationships', $schema );
		$this->assertArrayHasKey( 'features', $schema );
		$this->assertArrayHasKey( 'known_top_level_keys', $schema );
	}

	public function testTypeSchemaIncludesAllModelFactoryAliases(): void {
		$builder = new SchemaBuilder();
		$schema  = $builder->build();

		$expected_aliases = [
			'post-type',
			'cpt',
			'posttype',
			'post_type',
			'taxonomy',
			'tax',
			'category',
			'cat',
			'tag',
		];

		$this->assertSame( $expected_aliases, $schema['type']['accepted'] );
	}

	public function testNameSchemaDefinesLimits(): void {
		$builder = new SchemaBuilder();
		$schema  = $builder->build();

		$this->assertSame( 20, $schema['name']['post_type_max'] );
		$this->assertSame( 32, $schema['name']['taxonomy_max'] );
	}

	public function testActiveSchemaWarnsAboutTruthyValues(): void {
		$builder = new SchemaBuilder();
		$schema  = $builder->build();

		$this->assertArrayHasKey( 'warning', $schema['active'] );
		$this->assertStringContainsString( 'Truthy non-true', $schema['active']['warning'] );
	}

	/**
	 * Field types are read from CodestarMeta's own map, not transcribed.
	 *
	 * The first draft of this schema listed types by hand and invented three that
	 * do not exist (`wysiwyg`, `file`, `dimensions` spellings) while missing real
	 * ones like `link_color` and `color_group`. Deriving is what caught that, so
	 * this asserts against the real map rather than a second hand-written copy.
	 */
	public function testMetaFieldTypesAreDerivedFromCodestarMeta(): void {
		$schema = ( new SchemaBuilder() )->build();
		$actual = array_keys( ( new CodestarMeta( '', [] ) )->match_fields() );

		$this->assertSame( $actual, $schema['meta']['field_types'] );
		$this->assertContains( 'wp_editor', $schema['meta']['field_types'] );
		$this->assertNotContains( 'wysiwyg', $schema['meta']['field_types'], 'wysiwyg is not a real Codestar field type.' );
	}

	public function testMetaSchemaDefinesBoxStructure(): void {
		$builder = new SchemaBuilder();
		$schema  = $builder->build();

		$this->assertArrayHasKey( 'box_structure', $schema['meta'] );
		$this->assertArrayHasKey( 'data_type', $schema['meta']['box_structure'] );
		$this->assertArrayHasKey( 'register_rest_api', $schema['meta']['box_structure'] );
	}

	public function testRelationshipsSchemaListsAllCardinalities(): void {
		$builder = new SchemaBuilder();
		$schema  = $builder->build();

		$expected = [
			RelationshipDefinition::HAS_ONE,
			RelationshipDefinition::HAS_MANY,
			RelationshipDefinition::BELONGS_TO,
			RelationshipDefinition::MANY_TO_MANY,
		];

		$this->assertSame( $expected, $schema['relationships']['cardinalities'] );
	}

	public function testFeaturesSchemaDistinguishesDragAndDropVariants(): void {
		$builder = new SchemaBuilder();
		$schema  = $builder->build();

		$sections = $schema['features']['sections'];

		$this->assertArrayHasKey( 'drag_and_drop', $sections );
		$this->assertArrayHasKey( 'draganddrop', $sections );
		$this->assertNotSame(
			$sections['drag_and_drop']['description'],
			$sections['draganddrop']['description'],
			'drag_and_drop and draganddrop are distinct keys, not aliases.'
		);
	}

	public function testFeaturesSchemaIncludesFrontendAndBlocks(): void {
		$builder = new SchemaBuilder();
		$schema  = $builder->build();

		$sections = $schema['features']['sections'];

		$this->assertArrayHasKey( 'frontend', $sections );
		$this->assertArrayHasKey( 'blocks', $sections );
		$this->assertArrayHasKey( 'keys', $sections['frontend'] );
		$this->assertArrayHasKey( 'keys', $sections['blocks'] );
	}

	public function testAssociationsSchemaMarkedTaxonomyOnly(): void {
		$builder = new SchemaBuilder();
		$schema  = $builder->build();

		$this->assertTrue( $schema['associations']['taxonomy_only'] );
	}

	public function testKnownTopLevelKeysIncludesCommonOnes(): void {
		$builder = new SchemaBuilder();
		$schema  = $builder->build();

		$keys = $schema['known_top_level_keys'];

		$this->assertContains( 'type', $keys );
		$this->assertContains( 'name', $keys );
		$this->assertContains( 'meta', $keys );
		$this->assertContains( 'relationships', $keys );
		$this->assertContains( 'features', $keys );
		$this->assertContains( 'hierarchical', $keys );
		$this->assertContains( 'show_in_rest', $keys );
	}

	public function testKnownTopLevelKeysDoesNotIncludeTaxonomies(): void {
		$builder = new SchemaBuilder();
		$schema  = $builder->build();

		$this->assertNotContains(
			'taxonomies',
			$schema['known_top_level_keys'],
			'taxonomies is not a valid config key — association is declared taxonomy-side via "associations".'
		);
	}

	public function testFieldPermissionOperationsListsReadAndWrite(): void {
		$builder    = new SchemaBuilder();
		$operations = $builder->field_permission_operations();

		$this->assertContains( 'read', $operations );
		$this->assertContains( 'write', $operations );
		$this->assertCount( 2, $operations );
	}
}
