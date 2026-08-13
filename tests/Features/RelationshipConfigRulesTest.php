<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Relationships\RelationshipConfigRules;
use Saltus\WP\Framework\Models\ConfigError;

/**
 * The relationships section's rules, extracted from `ConfigValidator`.
 *
 * These rules used to live in the validator, which had to import
 * `RelationshipDefinition` to reach the cardinality constants. They now sit
 * beside the code that enforces them, so a new cardinality added to
 * `RelationshipDefinition` is read here rather than restated.
 *
 * @covers \Saltus\WP\Framework\Features\Relationships\RelationshipConfigRules
 */
class RelationshipConfigRulesTest extends TestCase {

	public function testItOwnsTheRelationshipsSection(): void {
		$this->assertSame( 'relationships', ( new RelationshipConfigRules() )->get_config_section() );
	}

	public function testItsSchemaListsEveryCardinalityWithoutRestatingIt(): void {
		$schema = ( new RelationshipConfigRules() )->get_config_schema();

		$this->assertContains( 'has_one', $schema['cardinalities'] );
		$this->assertContains( 'has_many', $schema['cardinalities'] );
		$this->assertContains( 'belongs_to', $schema['cardinalities'] );
		$this->assertContains( 'many_to_many', $schema['cardinalities'] );
		$this->assertArrayHasKey( 'description', $schema );
	}

	public function testANonArrayRelationshipsValueIsAnError(): void {
		$problems = ( new RelationshipConfigRules() )->validate_config_section( 'yes', 'movie' );

		$this->assertCount( 1, $problems );
		$this->assertSame( 'relationships_not_an_object', $problems[0]->get_rule() );
		$this->assertSame( 'relationships', $problems[0]->get_path() );
		$this->assertTrue( $problems[0]->is_error() );
	}

	public function testARelationshipDeclaredAsAScalarIsAnError(): void {
		$problems = ( new RelationshipConfigRules() )->validate_config_section(
			[ 'actors' => 'nope' ],
			'movie'
		);

		$this->assertCount( 1, $problems );
		$this->assertSame( 'relationship_not_an_object', $problems[0]->get_rule() );
		$this->assertSame( 'relationships.actors', $problems[0]->get_path() );
		$this->assertSame( 'nope', $problems[0]->get_found() );
	}

	public function testCardinalityKeyIsReportedAsTheWrongKeyName(): void {
		$problems = ( new RelationshipConfigRules() )->validate_config_section(
			[ 'actors' => [ 'cardinality' => 'has_many', 'model' => 'person' ] ],
			'movie'
		);

		$this->assertCount( 1, $problems );
		$this->assertSame( 'relationship_cardinality_key', $problems[0]->get_rule() );
		$this->assertSame( 'relationships.actors.cardinality', $problems[0]->get_path() );
		$this->assertSame( 'type', $problems[0]->get_suggestion() );
	}

	public function testAnUnrecognizedTypeIsAnErrorWithTheNearestCardinality(): void {
		$problems = ( new RelationshipConfigRules() )->validate_config_section(
			[ 'actors' => [ 'type' => 'has_meny', 'model' => 'person' ] ],
			'movie'
		);

		$this->assertCount( 1, $problems );
		$this->assertSame( 'relationship_type_unrecognized', $problems[0]->get_rule() );
		$this->assertSame( 'relationships.actors.type', $problems[0]->get_path() );
		$this->assertSame( 'has_many', $problems[0]->get_suggestion() );
	}

	public function testAMissingTargetModelIsAnError(): void {
		$problems = ( new RelationshipConfigRules() )->validate_config_section(
			[ 'actors' => [ 'type' => 'has_many' ] ],
			'movie'
		);

		$this->assertCount( 1, $problems );
		$this->assertSame( 'relationship_model_missing', $problems[0]->get_rule() );
		$this->assertSame( 'relationships.actors.model', $problems[0]->get_path() );
	}

	public function testAValidDeclarationProducesNoProblems(): void {
		$problems = ( new RelationshipConfigRules() )->validate_config_section(
			[ 'actors' => [ 'type' => 'has_many', 'model' => 'person' ] ],
			'movie'
		);

		$this->assertSame( [], $problems );
	}

	public function testEveryProblemInAMultiRelationshipSectionIsReported(): void {
		$problems = ( new RelationshipConfigRules() )->validate_config_section(
			[
				'actors'  => [ 'type' => 'has_many', 'model' => 'person' ],
				'broken'  => 'nope',
				'director' => [ 'type' => 'has_one', 'model' => 'person' ],
				'orphan'  => [ 'type' => 'belongs_to' ],
			],
			'movie'
		);

		$rules = array_map( static fn( ConfigError $e ): string => $e->get_rule(), $problems );

		$this->assertSame( [ 'relationship_not_an_object', 'relationship_model_missing' ], $rules );
	}
}
