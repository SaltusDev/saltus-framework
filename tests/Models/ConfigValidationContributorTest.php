<?php

namespace Saltus\WP\Framework\Tests\Models;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Models\Config\ConfigValidationContributor;
use Saltus\WP\Framework\Models\Config\ConfigValidator;
use Saltus\WP\Framework\Models\ConfigError;

/**
 * A contributor's rules must actually run, and only for its own section.
 *
 * @covers \Saltus\WP\Framework\Models\Config\ConfigValidator
 */
class ConfigValidationContributorTest extends TestCase {

	public function testAContributorsErrorReachesTheResult(): void {
		$validator = new ConfigValidator( null, [ $this->contributor( 'relationships', 'error' ) ] );

		$result = $validator->validate(
			[ 'type' => 'cpt', 'name' => 'movie', 'relationships' => [ 'actors' => [] ] ]
		);

		$this->assertTrue( $result->has_errors(), 'A contributed error must invalidate the config.' );
		$this->assertSame( 'relationships', $result->get_errors()[0]->get_path() );
	}

	public function testAContributorsWarningDoesNotInvalidateTheConfig(): void {
		$validator = new ConfigValidator( null, [ $this->contributor( 'relationships', 'warning' ) ] );

		$result = $validator->validate(
			[ 'type' => 'cpt', 'name' => 'movie', 'relationships' => [ 'actors' => [] ] ]
		);

		$this->assertFalse( $result->has_errors() );
		$this->assertTrue( $result->has_warnings() );
		$this->assertTrue( $result->is_valid(), 'A contributed warning must still let the model register.' );
	}

	/**
	 * A section nobody declared is not that section's problem.
	 *
	 * Offering an absent key would force every contributor to tell "absent" from
	 * "empty" on each call, and a model declaring no relationships would start
	 * reporting relationship errors.
	 */
	public function testAContributorIsNotCalledForAnAbsentSection(): void {
		$contributor = $this->contributor( 'relationships', 'error' );
		$validator   = new ConfigValidator( null, [ $contributor ] );

		$result = $validator->validate( [ 'type' => 'cpt', 'name' => 'movie' ] );

		$this->assertSame( 0, $contributor->calls, 'An absent section must not be handed to its contributor.' );
		$this->assertFalse( $result->has_errors() );
	}

	public function testAContributorReceivesItsOwnRawValueAndTheModelName(): void {
		$contributor = $this->contributor( 'webmcp', 'none' );
		$validator   = new ConfigValidator( null, [ $contributor ] );

		$validator->validate(
			[ 'type' => 'cpt', 'name' => 'movie', 'webmcp' => [ 'enabled' => true ] ]
		);

		$this->assertSame( 1, $contributor->calls );
		$this->assertSame( [ 'enabled' => true ], $contributor->received_value );
		$this->assertSame( 'movie', $contributor->received_model );
	}

	/** A declared-but-scalar section is exactly what a contributor should see. */
	public function testAContributorReceivesANonArrayValueRatherThanBeingSkipped(): void {
		$contributor = $this->contributor( 'webmcp', 'error' );
		$validator   = new ConfigValidator( null, [ $contributor ] );

		$result = $validator->validate( [ 'type' => 'cpt', 'name' => 'movie', 'webmcp' => 'yes' ] );

		$this->assertSame( 1, $contributor->calls );
		$this->assertSame( 'yes', $contributor->received_value );
		$this->assertTrue( $result->has_errors() );
	}

	public function testEachContributorOnlySeesItsOwnSection(): void {
		$rel  = $this->contributor( 'relationships', 'none' );
		$meta = $this->contributor( 'meta', 'none' );

		( new ConfigValidator( null, [ $rel, $meta ] ) )->validate(
			[ 'type' => 'cpt', 'name' => 'movie', 'relationships' => [ 'a' => 1 ] ]
		);

		$this->assertSame( 1, $rel->calls );
		$this->assertSame( 0, $meta->calls, 'A contributor must not be offered another section.' );
	}

	/**
	 * Two features claiming one section is a bug that must not resolve silently.
	 *
	 * Last-wins would stop the losing feature's rules from running while every
	 * config still reported valid — the worst outcome available.
	 */
	public function testTwoContributorsCannotClaimTheSameSection(): void {
		$this->expectException( \LogicException::class );
		$this->expectExceptionMessageMatches( '/already validated by/' );

		new ConfigValidator(
			null,
			[ $this->contributor( 'relationships', 'none' ), $this->contributor( 'relationships', 'none' ) ]
		);
	}

	public function testContributedSectionsAreReported(): void {
		$validator = new ConfigValidator(
			null,
			[ $this->contributor( 'relationships', 'none' ), $this->contributor( 'meta', 'none' ) ]
		);

		$this->assertSame( [ 'relationships', 'meta' ], $validator->get_contributed_sections() );
	}

	/** A contributor's schema fragment must win for its own section. */
	public function testAContributorsSchemaOverridesTheBuiltInFragment(): void {
		$validator = new ConfigValidator( null, [ $this->contributor( 'relationships', 'none' ) ] );

		$reflection = new \ReflectionMethod( $validator, 'schema' );
		$reflection->setAccessible( true );
		$schema = $reflection->invoke( $validator );

		$this->assertSame( [ 'contributed' => true ], $schema['relationships'] );
	}

	/**
	 * A validator built with no registered contributors still checks every section.
	 *
	 * The CLI and CI construct a validator directly, without `Core`'s registry, so
	 * the framework's own rule classes are defaults rather than optional extras. A
	 * section nobody claims would not be checked at all, and an invalid config would
	 * report valid — the one outcome worse than a false positive. Emptying
	 * `default_contributors()` fails this.
	 */
	public function testABareValidatorStillChecksTheSectionsFeaturesOwn(): void {
		$validator = new ConfigValidator();

		$this->assertContains( 'relationships', $validator->get_contributed_sections() );

		$result = $validator->validate(
			[
				'type'          => 'cpt',
				'name'          => 'movie',
				'relationships' => [ 'actors' => [ 'type' => 'has_meny', 'model' => 'person' ] ],
			]
		);

		$this->assertTrue( $result->has_errors(), 'A bad cardinality must still be caught without a registry.' );
		$this->assertSame( 'has_many', $result->get_errors()[0]->get_suggestion() );
	}

	/** The model-shape rules the validator itself owns are unaffected. */
	public function testTheValidatorStillOwnsTheModelShapeRules(): void {
		$validator = new ConfigValidator();

		$this->assertTrue( $validator->validate( [ 'type' => 'cpt', 'name' => 'movie' ] )->is_valid() );
		$this->assertTrue( $validator->validate( [ 'type' => 'nope', 'name' => 'movie' ] )->has_errors() );
	}

	/** A registered contributor replaces the default for its section, not adds to it. */
	public function testARegisteredContributorReplacesTheDefaultForItsSection(): void {
		$validator = new ConfigValidator( null, [ $this->contributor( 'relationships', 'error' ) ] );

		$result = $validator->validate(
			[
				'type'          => 'cpt',
				'name'          => 'movie',
				'relationships' => [ 'actors' => [ 'type' => 'has_meny', 'model' => 'person' ] ],
			]
		);

		// One problem, from the injected contributor — not two, which is what running
		// the default alongside it would produce.
		$this->assertCount( 1, $result->get_errors() );
		$this->assertSame( 'contributed error', $result->get_errors()[0]->get_message() );
	}

	/**
	 * A recording contributor whose verdict is fixed up front.
	 *
	 * @param 'error'|'warning'|'none' $verdict
	 */
	private function contributor( string $section, string $verdict ): ConfigValidationContributor {
		return new class( $section, $verdict ) implements ConfigValidationContributor {
			public int $calls = 0;
			/** @var mixed */
			public $received_value = null;
			public string $received_model = '';

			private string $section;
			private string $verdict;

			public function __construct( string $section, string $verdict ) {
				$this->section = $section;
				$this->verdict = $verdict;
			}

			public function get_config_section(): string {
				return $this->section;
			}

			public function validate_config_section( $value, string $model_name ): array {
				++$this->calls;
				$this->received_value = $value;
				$this->received_model = $model_name;

				if ( $this->verdict === 'error' ) {
					return [ ConfigError::error( $model_name, $this->section, 'contributed', 'contributed error' ) ];
				}

				if ( $this->verdict === 'warning' ) {
					return [ ConfigError::warning( $model_name, $this->section, 'contributed', 'contributed warning' ) ];
				}

				return [];
			}

			public function get_config_schema(): array {
				return [ 'contributed' => true ];
			}
		};
	}
}
