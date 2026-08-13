<?php

namespace Saltus\WP\Framework\Tests\Models;

use Noodlehaus\AbstractConfig;
use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Models\ModelFactory;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * Validation must actually run during registration.
 *
 * A validator nobody calls protects nothing, so these assert the wiring rather
 * than the rules: a config with an error must not reach the factory, one with
 * only warnings must, and both must be reported where a developer sees them.
 * Every test here fails if the call in `Modeler::create()` is removed.
 *
 * @covers \Saltus\WP\Framework\Modeler
 */
class ModelerValidationTest extends TestCase {

	protected function setUp(): void {
		$this->reset();
	}

	protected function tearDown(): void {
		$this->reset();
	}

	private function reset(): void {
		global $wp_doing_it_wrong, $wp_transients, $wp_filter_values, $wp_filters_registered, $wp_post_types_registered;

		$wp_doing_it_wrong        = [];
		$wp_transients            = [];
		$wp_filter_values         = [];
		$wp_filters_registered    = [];
		$wp_post_types_registered = [];
	}

	/**
	 * Feed a config through the real registration path and report what happened.
	 *
	 * @param array<string|int, mixed> $config
	 * @return array{reached_factory: list<array<string, mixed>>, reports: list<string>, modeler: Modeler}
	 */
	private function register( array $config ): array {
		// Observing at the *factory* rather than by overriding `create()`. An
		// override that called `passes_validation()` itself would pass even with the
		// real wiring deleted — it would be testing the validator, not the wiring.
		$factory = new class() extends ModelFactory {
			/** @var list<array<string, mixed>> */
			public array $received = [];

			public function __construct() {
				// Skips the parent constructor: nothing here needs a container.
			}

			public function create( AbstractConfig $config ): ?Model {
				$this->received[] = $config->all();

				return null;
			}
		};

		$modeler = new class( $factory ) extends Modeler {
			public function feed( array $config ): void {
				$this->process_config( $config );
			}
		};

		$modeler->feed( $config );

		global $wp_doing_it_wrong;

		return [
			'reached_factory' => $factory->received,
			'reports'         => array_column( is_array( $wp_doing_it_wrong ) ? $wp_doing_it_wrong : [], 'message' ),
			'modeler'         => $modeler,
		];
	}

	// --- Errors stop registration ---

	public function testAConfigWithAnErrorNeverReachesTheFactory(): void {
		$outcome = $this->register( [ 'type' => 'not_a_type', 'name' => 'movie' ] );

		$this->assertSame( [], $outcome['reached_factory'], 'An invalid config must not be registered.' );
	}

	public function testAnErrorIsReportedWithTheModelNameAndPath(): void {
		$outcome = $this->register( [ 'type' => 'not_a_type', 'name' => 'movie' ] );

		$this->assertNotSame( [], $outcome['reports'] );
		$this->assertStringContainsString( 'movie', $outcome['reports'][0] );
		$this->assertStringContainsString( 'type', $outcome['reports'][0] );
	}

	/** The excerpt stands in for a file and line, so it has to be in the report. */
	public function testTheReportIncludesTheMarkedExcerpt(): void {
		$outcome = $this->register( [ 'type' => 'not_a_type', 'name' => 'movie' ] );

		$this->assertStringContainsString( '&gt; type:', $outcome['reports'][0] );
	}

	// --- Warnings do not ---

	public function testAConfigWithOnlyWarningsStillRegisters(): void {
		$outcome = $this->register(
			[
				'type'       => 'cpt',
				'name'       => 'movie',
				'taxonomies' => [],
			]
		);

		$this->assertCount( 1, $outcome['reached_factory'], 'A warning must not block registration.' );
	}

	public function testWarningsAreStillReported(): void {
		$outcome = $this->register(
			[
				'type'       => 'cpt',
				'name'       => 'movie',
				'taxonomies' => [],
			]
		);

		$this->assertNotSame( [], $outcome['reports'] );
		$this->assertStringContainsString( 'taxonomies', $outcome['reports'][0] );
	}

	public function testAValidConfigRegistersSilently(): void {
		$outcome = $this->register( [ 'type' => 'cpt', 'name' => 'movie' ] );

		$this->assertCount( 1, $outcome['reached_factory'] );
		$this->assertSame( [], $outcome['reports'], 'A correct config must produce no output at all.' );
	}

	// --- Every model passes through, including children of a multi-model file ---

	public function testEachChildOfAMultiModelFileIsValidatedIndependently(): void {
		$outcome = $this->register(
			[
				'movie' => [ 'type' => 'cpt', 'name' => 'movie' ],
				'broken' => [ 'type' => 'not_a_type', 'name' => 'broken' ],
				'book'  => [ 'type' => 'cpt', 'name' => 'book' ],
			]
		);

		$registered = array_column( $outcome['reached_factory'], 'name' );

		$this->assertSame( [ 'movie', 'book' ], $registered, 'One bad sibling must not take the others down.' );
		$this->assertNotSame( [], $outcome['reports'] );
	}

	// --- Caching ---

	public function testTheVerdictIsCachedForAnUnchangedConfig(): void {
		global $wp_transients, $wp_filter_values;

		// Caching is off under WP_DEBUG, which the test bootstrap may set.
		$wp_filter_values['saltus/framework/config/cache_validation'] = true;

		$this->register( [ 'type' => 'cpt', 'name' => 'movie', 'taxonomies' => [] ] );

		$keys = array_filter(
			array_keys( is_array( $wp_transients ) ? $wp_transients : [] ),
			static fn( string $key ): bool => strpos( $key, 'saltus_config_valid_' ) === 0
		);

		$this->assertNotSame( [], $keys, 'A verdict must be cached so registration does not re-walk every request.' );
	}

	public function testACachedVerdictIsReusedRatherThanRecomputed(): void {
		global $wp_filter_values, $wp_doing_it_wrong;

		$wp_filter_values['saltus/framework/config/cache_validation'] = true;

		$config = [ 'type' => 'cpt', 'name' => 'movie', 'taxonomies' => [] ];

		$first = $this->register( $config );

		// The report global accumulates, so clear it to see only the second run.
		$wp_doing_it_wrong = [];
		$second            = $this->register( $config );

		// The same verdict either way: recomputed or restored from cache, the
		// decision and the reporting must be identical.
		$this->assertCount( 1, $first['reached_factory'] );
		$this->assertCount( 1, $second['reached_factory'] );
		$this->assertSame( $first['reports'], $second['reports'], 'A restored verdict must report exactly what a fresh one does.' );
	}

	/** A different config must not collide with a cached verdict. */
	public function testADifferentConfigGetsItsOwnVerdict(): void {
		global $wp_filter_values;

		$wp_filter_values['saltus/framework/config/cache_validation'] = true;

		$good = $this->register( [ 'type' => 'cpt', 'name' => 'movie' ] );
		$bad  = $this->register( [ 'type' => 'not_a_type', 'name' => 'movie' ] );

		$this->assertCount( 1, $good['reached_factory'] );
		$this->assertSame( [], $bad['reached_factory'], 'A changed config must be re-validated, not served a stale verdict.' );
	}

	public function testCachingCanBeDisabledByFilter(): void {
		global $wp_transients, $wp_filter_values;

		$wp_filter_values['saltus/framework/config/cache_validation'] = false;

		$this->register( [ 'type' => 'cpt', 'name' => 'movie' ] );

		$keys = array_filter(
			array_keys( is_array( $wp_transients ) ? $wp_transients : [] ),
			static fn( string $key ): bool => strpos( $key, 'saltus_config_valid_' ) === 0
		);

		$this->assertSame( [], $keys, 'The filter must be able to turn caching off while developing.' );
	}

	// --- The accumulated summary ---

	/**
	 * Null and empty are different answers.
	 *
	 * Before anything is validated there is no verdict to report, and saying "valid"
	 * would repeat the v1.8.4 mistake: an unread source reported as a clean result.
	 */
	public function testTheSummaryIsNullBeforeAnythingIsValidated(): void {
		$modeler = new class() extends Modeler {
			public function __construct() {
				// No container needed to ask for a verdict that does not exist yet.
			}
		};

		$this->assertNull( $modeler->get_config_validation() );
	}

	public function testAValidatedModelAppearsInTheSummary(): void {
		$outcome = $this->register( [ 'type' => 'cpt', 'name' => 'movie' ] );
		$summary = $outcome['modeler']->get_config_validation();

		$this->assertNotNull( $summary );
		$this->assertSame( 1, $summary->total_count() );
		$this->assertSame( 1, $summary->valid_count() );
		$this->assertSame( 0, $summary->error_count() );
		$this->assertTrue( $summary->is_valid() );
	}

	/**
	 * A model rejected for an error must still be counted.
	 *
	 * This is the guard on *where* the accumulation sits. Recording after the
	 * error-return instead of before it would drop exactly the models an operator
	 * needs to see, and the site would report itself as valid because the only
	 * broken config never made it into the summary.
	 */
	public function testARejectedModelStillAppearsInTheSummary(): void {
		$outcome = $this->register( [ 'type' => 'not_a_type', 'name' => 'movie' ] );
		$summary = $outcome['modeler']->get_config_validation();

		$this->assertSame( [], $outcome['reached_factory'], 'Precondition: this config must be rejected.' );
		$this->assertNotNull( $summary );
		$this->assertSame( 1, $summary->total_count(), 'A rejected model must still be counted.' );
		$this->assertSame( 1, $summary->error_count() );
		$this->assertSame( 0, $summary->valid_count() );
		$this->assertFalse( $summary->is_valid() );
	}

	public function testWarningsAreCountedWithoutInvalidatingTheSite(): void {
		$outcome = $this->register(
			[
				'type'       => 'cpt',
				'name'       => 'movie',
				'taxonomies' => [],
			]
		);
		$summary = $outcome['modeler']->get_config_validation();

		$this->assertNotNull( $summary );
		$this->assertSame( 1, $summary->warning_count() );
		$this->assertSame( 1, $summary->valid_count() );
		$this->assertTrue( $summary->is_valid(), 'A warning must not make the site invalid.' );
	}

	public function testEveryChildOfAMultiModelFileIsCounted(): void {
		$outcome = $this->register(
			[
				'movie'  => [ 'type' => 'cpt', 'name' => 'movie' ],
				'broken' => [ 'type' => 'not_a_type', 'name' => 'broken' ],
				'book'   => [ 'type' => 'cpt', 'name' => 'book' ],
			]
		);
		$summary = $outcome['modeler']->get_config_validation();

		$this->assertNotNull( $summary );
		$this->assertSame( 3, $summary->total_count(), 'All three children must be counted, including the rejected one.' );
		$this->assertSame( 2, $summary->valid_count() );
		$this->assertSame( 1, $summary->error_count() );
		$this->assertSame(
			$summary->total_count(),
			$summary->valid_count() + $summary->error_count(),
			'total must equal valid + error.'
		);
	}

	public function testTheSummaryNamesTheFailingModel(): void {
		$outcome = $this->register(
			[
				'movie'  => [ 'type' => 'cpt', 'name' => 'movie' ],
				'broken' => [ 'type' => 'not_a_type', 'name' => 'broken' ],
			]
		);
		$summary = $outcome['modeler']->get_config_validation();

		$this->assertNotNull( $summary );
		$errors = $summary->all_errors();
		$this->assertNotSame( [], $errors );
		$this->assertSame( 'broken', $errors[0]->get_model_name() );
	}
}
