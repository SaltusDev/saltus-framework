<?php

namespace Saltus\WP\Framework\Tests\Models;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Models\Config\ConfigValidator;
use Saltus\WP\Framework\Models\Config\NoFile;
use Saltus\WP\Framework\Models\ConfigError;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * One fixture per known-bad config shape.
 *
 * The severity split is what these mostly assert. An error must stop
 * registration; a warning must not. Getting that backwards is the failure mode
 * that matters — an over-strict validator breaks working sites on upgrade, and an
 * over-lenient one lets the original silent-failure bugs back in.
 *
 * @covers \Saltus\WP\Framework\Models\Config\ConfigValidator
 */
class ConfigValidatorTest extends TestCase {

	private function validate( array $config, string $name = '' ) {
		return ( new ConfigValidator() )->validate( $config, $name );
	}

	/** Rules present in a result, for asserting without depending on message text. */
	private function rules( $result ): array {
		return array_map(
			static fn( ConfigError $p ): string => $p->get_rule(),
			$result->get_problems()
		);
	}

	// --- The baseline: a good config must be silent ---

	public function testAValidPostTypeProducesNoProblems(): void {
		$result = $this->validate(
			[
				'type'   => 'cpt',
				'name'   => 'movie',
				'labels' => [ 'singular' => 'Movie' ],
			]
		);

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( [], $result->get_problems(), 'A correct config must produce nothing at all.' );
	}

	public function testEveryAcceptedTypeAliasIsSilent(): void {
		foreach ( [ 'cpt', 'post_type', 'post-type', 'posttype', 'taxonomy', 'tax', 'category', 'cat', 'tag' ] as $type ) {
			$result = $this->validate( [ 'type' => $type, 'name' => 'thing' ] );

			$this->assertTrue( $result->is_valid(), sprintf( 'Type "%s" must be accepted.', $type ) );
		}
	}

	public function testAcceptsAnAbstractConfigAsWellAsAnArray(): void {
		$result = $this->validate_config( new NoFile( [ 'type' => 'cpt', 'name' => 'movie' ] ) );

		$this->assertTrue( $result->is_valid() );
	}

	private function validate_config( $config ) {
		return ( new ConfigValidator() )->validate( $config );
	}

	// --- Errors: registration must stop ---

	public function testMissingTypeIsAnError(): void {
		$result = $this->validate( [ 'name' => 'movie' ] );

		$this->assertFalse( $result->is_valid() );
		$this->assertContains( 'type_missing', $this->rules( $result ) );
	}

	public function testUnrecognizedTypeIsAnError(): void {
		$result = $this->validate( [ 'type' => 'not_a_type', 'name' => 'movie' ] );

		$this->assertFalse( $result->is_valid() );
		$this->assertContains( 'type_unrecognized', $this->rules( $result ) );
	}

	public function testOverLongPostTypeNameIsAnError(): void {
		$result = $this->validate( [ 'type' => 'cpt', 'name' => str_repeat( 'a', 21 ) ] );

		$this->assertFalse( $result->is_valid() );
		$this->assertContains( 'name_too_long', $this->rules( $result ) );
	}

	/** A taxonomy gets 32 characters where a post type gets 20. */
	public function testNameLimitDependsOnModelKind(): void {
		$twenty_five = str_repeat( 'a', 25 );

		$this->assertFalse(
			$this->validate( [ 'type' => 'cpt', 'name' => $twenty_five ] )->is_valid(),
			'25 characters is too long for a post type.'
		);
		$this->assertTrue(
			$this->validate( [ 'type' => 'taxonomy', 'name' => $twenty_five ] )->is_valid(),
			'25 characters is within the taxonomy limit.'
		);
	}

	public function testUnrecognizedRelationshipTypeIsAnError(): void {
		$result = $this->validate(
			[
				'type'          => 'cpt',
				'name'          => 'movie',
				'relationships' => [
					'actors' => [ 'type' => 'has_meny', 'model' => 'person' ],
				],
			]
		);

		$this->assertFalse( $result->is_valid() );
		$this->assertContains( 'relationship_type_unrecognized', $this->rules( $result ) );
	}

	/**
	 * `cardinality` is a plausible name for the key, but the code reads `type`.
	 * The report names the key, not just the value, because that is the actual fix.
	 */
	public function testCardinalityKeyIsReportedAsTheWrongKeyName(): void {
		$result = $this->validate(
			[
				'type'          => 'cpt',
				'name'          => 'movie',
				'relationships' => [
					'actors' => [ 'cardinality' => 'has_many', 'model' => 'person' ],
				],
			]
		);

		$this->assertContains( 'relationship_cardinality_key', $this->rules( $result ) );

		$problem = $result->get_errors()[0];
		$this->assertSame( 'type', $problem->get_suggestion() );
	}

	public function testRelationshipWithoutATargetModelIsAnError(): void {
		$result = $this->validate(
			[
				'type'          => 'cpt',
				'name'          => 'movie',
				'relationships' => [ 'actors' => [ 'type' => 'has_many' ] ],
			]
		);

		$this->assertFalse( $result->is_valid() );
		$this->assertContains( 'relationship_model_missing', $this->rules( $result ) );
	}

	/** A section without `fields` raises a TypeError at registration, so it is an error. */
	public function testMetaSectionWithoutFieldsIsAnError(): void {
		$result = $this->validate(
			[
				'type' => 'cpt',
				'name' => 'movie',
				'meta' => [
					'details' => [ 'sections' => [ [ 'title' => 'Main' ] ] ],
				],
			]
		);

		$this->assertFalse( $result->is_valid() );
		$this->assertContains( 'meta_section_without_fields', $this->rules( $result ) );
	}

	// --- Warnings: registration must continue ---

	public function testUnknownTopLevelKeyIsOnlyAWarning(): void {
		$result = $this->validate( [ 'type' => 'cpt', 'name' => 'movie', 'taxonomies' => [] ] );

		$this->assertTrue( $result->is_valid(), 'An unknown key must not stop registration.' );
		$this->assertContains( 'unknown_key', $this->rules( $result ) );
	}

	/**
	 * The validator's own suggestions come from the shared trait.
	 *
	 * It once carried a private copy of the distance logic beside
	 * `SuggestsNearestKey`, free to drift from what contributors used, so the same
	 * typo could be named in one section and not another. These two cover both
	 * validator-owned paths that suggest — an unknown key and an unrecognized type.
	 */
	public function testAMisspelledTopLevelKeyIsNamedInTheWarning(): void {
		$result = $this->validate( [ 'type' => 'cpt', 'name' => 'movie', 'labelz' => [] ] );

		$this->assertSame( 'labels', $result->get_warnings()[0]->get_suggestion() );
	}

	public function testAMisspelledTypeIsNamedInTheError(): void {
		$result = $this->validate( [ 'type' => 'taxonomi', 'name' => 'genre' ] );

		$this->assertSame( 'taxonomy', $result->get_errors()[0]->get_suggestion() );
	}

	public function testTruthyNonTrueActiveIsAWarningThatExplainsTheInversion(): void {
		$result = $this->validate( [ 'type' => 'cpt', 'name' => 'movie', 'active' => 1 ] );

		$this->assertTrue( $result->is_valid() );
		$this->assertContains( 'active_not_strict_true', $this->rules( $result ) );
		$this->assertStringContainsString( 'will not register', $result->get_warnings()[0]->get_message() );
	}

	public function testLiteralTrueAndFalseActiveAreSilent(): void {
		foreach ( [ true, false ] as $active ) {
			$result = $this->validate( [ 'type' => 'cpt', 'name' => 'movie', 'active' => $active ] );

			$this->assertSame( [], $result->get_problems() );
		}
	}

	/**
	 * The pair is two distinct keys, so declaring one alone warns about the
	 * *missing* half rather than suggesting a correction to the present one.
	 */
	public function testDragAndDropUiWithoutCapabilityWarns(): void {
		$result = $this->validate(
			[
				'type'     => 'cpt',
				'name'     => 'movie',
				'features' => [ 'draganddrop' => true ],
			]
		);

		$this->assertTrue( $result->is_valid() );
		$this->assertContains( 'drag_and_drop_pair', $this->rules( $result ) );
		$this->assertStringContainsString( 'drag_and_drop', $result->get_warnings()[0]->get_message() );
	}

	public function testDragAndDropCapabilityWithoutUiWarns(): void {
		$result = $this->validate(
			[
				'type'     => 'cpt',
				'name'     => 'movie',
				'features' => [ 'drag_and_drop' => true ],
			]
		);

		$this->assertContains( 'drag_and_drop_pair', $this->rules( $result ) );
		$this->assertStringContainsString( 'draganddrop', $result->get_warnings()[0]->get_message() );
	}

	public function testBothDragAndDropKeysTogetherAreSilent(): void {
		$result = $this->validate(
			[
				'type'     => 'cpt',
				'name'     => 'movie',
				'features' => [
					'draganddrop'   => true,
					'drag_and_drop' => true,
				],
			]
		);

		$this->assertSame( [], $result->get_problems(), 'Declaring both is the correct configuration.' );
	}

	/**
	 * Field types come from a filterable map, so a type this validator does not
	 * know may still be legitimately registered by a site.
	 */
	public function testUnknownFieldTypeIsOnlyAWarning(): void {
		$result = $this->validate(
			[
				'type' => 'cpt',
				'name' => 'movie',
				'meta' => [
					'details' => [
						'fields' => [ 'poster' => [ 'type' => 'custom_thing' ] ],
					],
				],
			]
		);

		$this->assertTrue( $result->is_valid(), 'A filter may register this type, so it cannot be an error.' );
		$this->assertContains( 'field_type_unknown', $this->rules( $result ) );
	}

	public function testKnownFieldTypesAreSilent(): void {
		$result = $this->validate(
			[
				'type' => 'cpt',
				'name' => 'movie',
				'meta' => [
					'details' => [
						'fields' => [
							'summary' => [ 'type' => 'textarea' ],
							'poster'  => [ 'type' => 'upload' ],
						],
					],
				],
			]
		);

		$this->assertSame( [], $result->get_problems() );
	}

	public function testNestedFieldTypesAreChecked(): void {
		$result = $this->validate(
			[
				'type' => 'cpt',
				'name' => 'movie',
				'meta' => [
					'details' => [
						'fields' => [
							'cast' => [
								'type'   => 'repeater',
								'fields' => [ 'role' => [ 'type' => 'nonsense' ] ],
							],
						],
					],
				],
			]
		);

		$this->assertContains( 'field_type_unknown', $this->rules( $result ) );
	}

	public function testTruthyNonTrueRestFlagWarns(): void {
		$result = $this->validate(
			[
				'type' => 'cpt',
				'name' => 'movie',
				'meta' => [
					'details' => [
						'register_rest_api' => 1,
						'fields'            => [ 'summary' => [ 'type' => 'textarea' ] ],
					],
				],
			]
		);

		$this->assertContains( 'rest_flag_not_strict_true', $this->rules( $result ) );
	}

	public function testMetaBoxWithBothFieldsAndSectionsWarns(): void {
		$result = $this->validate(
			[
				'type' => 'cpt',
				'name' => 'movie',
				'meta' => [
					'details' => [
						'fields'   => [ 'summary' => [ 'type' => 'textarea' ] ],
						'sections' => [ [ 'fields' => [ 'extra' => [ 'type' => 'text' ] ] ] ],
					],
				],
			]
		);

		$this->assertTrue( $result->is_valid() );
		$this->assertContains( 'meta_box_fields_and_sections', $this->rules( $result ) );
	}

	public function testAssociationsOnAPostTypeWarns(): void {
		$result = $this->validate(
			[
				'type'         => 'cpt',
				'name'         => 'movie',
				'associations' => [ 'genre' ],
			]
		);

		$this->assertTrue( $result->is_valid() );
		$this->assertContains( 'associations_on_post_type', $this->rules( $result ) );
	}

	public function testAssociationsOnATaxonomyIsSilent(): void {
		$result = $this->validate(
			[
				'type'         => 'taxonomy',
				'name'         => 'genre',
				'associations' => [ 'movie' ],
			]
		);

		$this->assertSame( [], $result->get_problems() );
	}

	// --- Suggestions ---

	public function testANearMissGetsASuggestion(): void {
		$result = $this->validate(
			[
				'type'          => 'cpt',
				'name'          => 'movie',
				'relationships' => [ 'actors' => [ 'type' => 'has_meny', 'model' => 'person' ] ],
			]
		);

		$this->assertSame( 'has_many', $result->get_errors()[0]->get_suggestion() );
	}

	/**
	 * A distant value gets no suggestion. Naming the wrong key sends the author to
	 * the wrong place, which costs more than saying nothing.
	 */
	public function testADistantValueGetsNoSuggestion(): void {
		$result = $this->validate(
			[
				'type'          => 'cpt',
				'name'          => 'movie',
				'relationships' => [ 'actors' => [ 'type' => 'completely_different', 'model' => 'person' ] ],
			]
		);

		$this->assertNull( $result->get_errors()[0]->get_suggestion() );
	}

	// --- Reporting ---

	public function testDescribeNamesTheModelAndThePath(): void {
		$result = $this->validate( [ 'type' => 'nope', 'name' => 'movie' ] );
		$line   = $result->get_errors()[0]->describe();

		$this->assertStringContainsString( 'movie', $line );
		$this->assertStringContainsString( 'type', $line );
	}

	public function testModelNameFallsBackToTheConfigName(): void {
		$result = $this->validate( [ 'type' => 'nope', 'name' => 'movie' ] );

		$this->assertSame( 'movie', $result->get_model_name() );
	}

	public function testUnnamedConfigIsStillReportable(): void {
		$result = $this->validate( [ 'type' => 'nope' ] );

		$this->assertSame( '(unnamed)', $result->get_model_name() );
	}

	public function testExcerptMarksTheOffendingKey(): void {
		$config  = [ 'type' => 'nope', 'name' => 'movie' ];
		$result  = $this->validate( $config );
		$excerpt = $result->get_errors()[0]->render_excerpt( $config );

		$this->assertStringContainsString( '> type:', $excerpt, 'The offending key must be marked.' );
		$this->assertStringContainsString( 'name:', $excerpt, 'Surrounding keys give context.' );
	}

	public function testExcerptReportsAMissingKeyAsMissing(): void {
		$config  = [ 'name' => 'movie' ];
		$result  = $this->validate( $config );
		$excerpt = $result->get_errors()[0]->render_excerpt( $config );

		$this->assertStringContainsString( '(missing)', $excerpt );
	}

	public function testResultRoundTripsThroughItsArrayForm(): void {
		$result = $this->validate(
			[
				'type'       => 'nope',
				'name'       => 'movie',
				'taxonomies' => [],
			]
		);

		$restored = \Saltus\WP\Framework\Models\ConfigValidationResult::from_array( $result->to_array() );

		$this->assertSame( $result->is_valid(), $restored->is_valid() );
		$this->assertCount( count( $result->get_errors() ), $restored->get_errors() );
		$this->assertCount( count( $result->get_warnings() ), $restored->get_warnings() );
		$this->assertSame( $result->get_errors()[0]->get_rule(), $restored->get_errors()[0]->get_rule() );
	}

	/**
	 * A cache entry from another version may carry a severity this class does not
	 * know. Dropping it is safer than inventing an error that was never found.
	 */
	public function testUnknownSeverityIsDroppedOnRestore(): void {
		$restored = \Saltus\WP\Framework\Models\ConfigValidationResult::from_array(
			[
				'model'  => 'movie',
				'errors' => [ [ 'severity' => 'catastrophe', 'path' => 'x', 'rule' => 'r', 'message' => 'm' ] ],
			]
		);

		$this->assertTrue( $restored->is_valid() );
		$this->assertSame( [], $restored->get_problems() );
	}

	// --- Accumulation ---

	public function testAllProblemsAreReportedNotJustTheFirst(): void {
		$result = $this->validate(
			[
				'type'         => 'nope',
				'name'         => str_repeat( 'a', 30 ),
				'taxonomies'   => [],
				'associations' => [ 'genre' ],
			]
		);

		$rules = $this->rules( $result );

		$this->assertContains( 'type_unrecognized', $rules );
		$this->assertContains( 'name_too_long', $rules );
		$this->assertContains( 'unknown_key', $rules );
		$this->assertGreaterThanOrEqual( 3, count( $rules ), 'Validation must not stop at the first problem.' );
	}
}
