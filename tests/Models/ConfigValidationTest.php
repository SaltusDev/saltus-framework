<?php

namespace Saltus\WP\Framework\Tests\Models;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Models\ConfigError;
use Saltus\WP\Framework\Models\ConfigValidationResult;

/**
 * The value objects themselves, independent of any validation rule.
 *
 * Rules and severity decisions are covered by ConfigValidatorTest; this asserts
 * the containers behave — that severity is readable, that a result separates the
 * two kinds, and that the array form round-trips well enough to cache.
 *
 * @covers \Saltus\WP\Framework\Models\ConfigError
 * @covers \Saltus\WP\Framework\Models\ConfigValidationResult
 */
class ConfigValidationTest extends TestCase {

	public function testErrorCarriesItsDetails(): void {
		$problem = ConfigError::error(
			'movie',
			'relationships.actors.type',
			'relationship_type_unrecognized',
			'Not a relationship type.',
			'has_meny',
			[ 'has_one', 'has_many' ],
			'has_many'
		);

		$this->assertSame( 'movie', $problem->get_model_name() );
		$this->assertSame( 'relationships.actors.type', $problem->get_path() );
		$this->assertSame( 'relationship_type_unrecognized', $problem->get_rule() );
		$this->assertSame( 'Not a relationship type.', $problem->get_message() );
		$this->assertSame( 'has_meny', $problem->get_found() );
		$this->assertSame( [ 'has_one', 'has_many' ], $problem->get_accepted() );
		$this->assertSame( 'has_many', $problem->get_suggestion() );
	}

	public function testSeverityIsReadableBothWays(): void {
		$error   = ConfigError::error( 'movie', 'type', 'r', 'm' );
		$warning = ConfigError::warning( 'movie', 'type', 'r', 'm' );

		$this->assertTrue( $error->is_error() );
		$this->assertFalse( $error->is_warning() );
		$this->assertSame( ConfigError::SEVERITY_ERROR, $error->get_severity() );

		$this->assertTrue( $warning->is_warning() );
		$this->assertFalse( $warning->is_error() );
		$this->assertSame( ConfigError::SEVERITY_WARNING, $warning->get_severity() );
	}

	/** A suggestion is more useful than a list, so it wins when both exist. */
	public function testDescribePrefersASuggestionOverTheAcceptedList(): void {
		$with_suggestion = ConfigError::error( 'movie', 'type', 'r', 'Bad type.', 'cpts', [ 'cpt', 'taxonomy' ], 'cpt' );
		$without         = ConfigError::error( 'movie', 'type', 'r', 'Bad type.', 'zzz', [ 'cpt', 'taxonomy' ] );

		$this->assertStringContainsString( 'Did you mean "cpt"?', $with_suggestion->describe() );
		$this->assertStringNotContainsString( 'Accepted:', $with_suggestion->describe() );

		$this->assertStringContainsString( 'Accepted: cpt, taxonomy.', $without->describe() );
	}

	public function testDescribeOmitsBothWhenNeitherIsAvailable(): void {
		$line = ConfigError::warning( 'movie', 'associations', 'r', 'Only read on a taxonomy.' )->describe();

		$this->assertStringContainsString( 'movie', $line );
		$this->assertStringContainsString( 'associations', $line );
		$this->assertStringNotContainsString( 'Accepted', $line );
		$this->assertStringNotContainsString( 'Did you mean', $line );
	}

	// --- Excerpt rendering ---

	public function testExcerptMarksTheKeyAndCollapsesNestedValues(): void {
		$config = [
			'type'   => 'nope',
			'name'   => 'movie',
			'labels' => [ 'singular' => 'Movie', 'plural' => 'Movies' ],
		];

		$excerpt = ConfigError::error( 'movie', 'type', 'r', 'm' )->render_excerpt( $config );

		$this->assertStringContainsString( '> type: "nope"', $excerpt );
		$this->assertStringContainsString( '  name: "movie"', $excerpt );
		$this->assertStringContainsString( '{2 keys}', $excerpt, 'A nested value collapses rather than expanding.' );
	}

	public function testExcerptReachesANestedPath(): void {
		$config = [
			'type'          => 'cpt',
			'relationships' => [
				'actors' => [ 'type' => 'has_meny', 'model' => 'person' ],
			],
		];

		$excerpt = ConfigError::error( 'movie', 'relationships.actors.type', 'r', 'm' )->render_excerpt( $config );

		$this->assertStringContainsString( 'relationships.actors:', $excerpt );
		$this->assertStringContainsString( '> ', $excerpt );
		$this->assertStringContainsString( 'has_meny', $excerpt );
	}

	public function testExcerptSaysMissingWhenTheKeyIsAbsent(): void {
		$excerpt = ConfigError::error( 'movie', 'type', 'r', 'm' )->render_excerpt( [ 'name' => 'movie' ] );

		$this->assertStringContainsString( '> type: (missing)', $excerpt );
	}

	public function testExcerptHandlesAPathThatDoesNotExistAtAll(): void {
		$excerpt = ConfigError::error( 'movie', 'a.b.c', 'r', 'm' )->render_excerpt( [ 'name' => 'movie' ] );

		$this->assertNotSame( '', $excerpt, 'An unreachable path must still render something.' );
	}

	public function testExcerptRendersScalarKinds(): void {
		$config = [
			'active'   => true,
			'disabled' => false,
			'nothing'  => null,
			'count'    => 3,
		];

		$excerpt = ConfigError::warning( 'movie', 'active', 'r', 'm' )->render_excerpt( $config );

		$this->assertStringContainsString( '> active: true', $excerpt );
		$this->assertStringContainsString( 'disabled: false', $excerpt );
		$this->assertStringContainsString( 'nothing: null', $excerpt );
		$this->assertStringContainsString( 'count: 3', $excerpt );
	}

	// --- Result ---

	public function testResultWithNoProblemsIsValid(): void {
		$result = new ConfigValidationResult( 'movie' );

		$this->assertTrue( $result->is_valid() );
		$this->assertFalse( $result->has_errors() );
		$this->assertFalse( $result->has_warnings() );
		$this->assertSame( 'movie', $result->get_model_name() );
	}

	/**
	 * The central rule: warnings do not invalidate a config. An existing site must
	 * keep working when a new warning is added.
	 */
	public function testWarningsAloneLeaveTheResultValid(): void {
		$result = new ConfigValidationResult(
			'movie',
			[
				ConfigError::warning( 'movie', 'taxonomies', 'unknown_key', 'Nothing reads this.' ),
				ConfigError::warning( 'movie', 'active', 'active_not_strict_true', 'Treated as false.' ),
			]
		);

		$this->assertTrue( $result->is_valid() );
		$this->assertTrue( $result->has_warnings() );
		$this->assertFalse( $result->has_errors() );
		$this->assertCount( 2, $result->get_warnings() );
	}

	public function testASingleErrorInvalidatesTheResult(): void {
		$result = new ConfigValidationResult(
			'movie',
			[
				ConfigError::warning( 'movie', 'taxonomies', 'unknown_key', 'Nothing reads this.' ),
				ConfigError::error( 'movie', 'type', 'type_missing', 'No type.' ),
			]
		);

		$this->assertFalse( $result->is_valid() );
		$this->assertCount( 1, $result->get_errors() );
		$this->assertCount( 1, $result->get_warnings() );
		$this->assertCount( 2, $result->get_problems() );
	}

	public function testProblemsKeepDiscoveryOrder(): void {
		$result = new ConfigValidationResult(
			'movie',
			[
				ConfigError::error( 'movie', 'first', 'r', 'm' ),
				ConfigError::warning( 'movie', 'second', 'r', 'm' ),
				ConfigError::error( 'movie', 'third', 'r', 'm' ),
			]
		);

		$this->assertSame(
			[ 'first', 'second', 'third' ],
			array_map( static fn( ConfigError $p ): string => $p->get_path(), $result->get_problems() )
		);
	}

	public function testSparseProblemArrayIsReindexed(): void {
		$result = new ConfigValidationResult(
			'movie',
			[
				5 => ConfigError::error( 'movie', 'a', 'r', 'm' ),
				9 => ConfigError::error( 'movie', 'b', 'r', 'm' ),
			]
		);

		$problems = $result->get_problems();

		$this->assertArrayHasKey( 0, $problems );
		$this->assertArrayHasKey( 1, $problems );
		$this->assertArrayNotHasKey( 5, $problems );
	}

	// --- Array form, which is what gets cached ---

	public function testArrayFormSeparatesSeverities(): void {
		$result = new ConfigValidationResult(
			'movie',
			[
				ConfigError::error( 'movie', 'type', 'type_missing', 'No type.' ),
				ConfigError::warning( 'movie', 'taxonomies', 'unknown_key', 'Nothing reads this.' ),
			]
		);

		$array = $result->to_array();

		$this->assertSame( 'movie', $array['model'] );
		$this->assertFalse( $array['valid'] );
		$this->assertCount( 1, $array['errors'] );
		$this->assertCount( 1, $array['warnings'] );
		$this->assertSame( 'type_missing', $array['errors'][0]['rule'] );
	}

	public function testRoundTripPreservesSeverityAndDetail(): void {
		$original = new ConfigValidationResult(
			'movie',
			[
				ConfigError::error( 'movie', 'type', 'type_missing', 'No type.', null, [ 'cpt' ] ),
				ConfigError::warning( 'movie', 'active', 'active_not_strict_true', 'Treated as false.', 1 ),
			]
		);

		$restored = ConfigValidationResult::from_array( $original->to_array() );

		$this->assertSame( $original->is_valid(), $restored->is_valid() );
		$this->assertCount( 1, $restored->get_errors() );
		$this->assertCount( 1, $restored->get_warnings() );
		$this->assertSame( 'type_missing', $restored->get_errors()[0]->get_rule() );
		$this->assertSame( [ 'cpt' ], $restored->get_errors()[0]->get_accepted() );
	}

	/**
	 * A cache entry written by a different version may carry a severity this class
	 * does not know. Dropping it beats inventing a problem that was never found.
	 */
	public function testUnknownSeverityIsDroppedOnRestore(): void {
		$restored = ConfigValidationResult::from_array(
			[
				'model'  => 'movie',
				'errors' => [
					[ 'severity' => 'catastrophe', 'path' => 'x', 'rule' => 'r', 'message' => 'm' ],
					[ 'severity' => 'error', 'path' => 'y', 'rule' => 'real', 'message' => 'm' ],
				],
			]
		);

		$this->assertCount( 1, $restored->get_problems() );
		$this->assertSame( 'real', $restored->get_errors()[0]->get_rule() );
	}

	public function testMalformedCacheEntriesAreIgnored(): void {
		$restored = ConfigValidationResult::from_array(
			[
				'model'    => 'movie',
				'errors'   => 'not-an-array',
				'warnings' => [ 'also-not-an-array', [ 'severity' => 'warning', 'path' => 'x', 'rule' => 'r', 'message' => 'm' ] ],
			]
		);

		$this->assertCount( 1, $restored->get_problems() );
		$this->assertTrue( $restored->is_valid() );
	}

	public function testEmptyArrayFormRestoresToAValidResult(): void {
		$restored = ConfigValidationResult::from_array( [] );

		$this->assertTrue( $restored->is_valid() );
		$this->assertSame( '', $restored->get_model_name() );
	}
}
