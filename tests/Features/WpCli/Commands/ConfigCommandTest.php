<?php

namespace Saltus\WP\Framework\Tests\Features\WpCli\Commands;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\WpCli\Commands\ConfigCommand;
use Saltus\WP\Framework\Models\ConfigError;
use Saltus\WP\Framework\Models\ConfigValidationResult;
use Saltus\WP\Framework\Models\ConfigValidationSummary;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Tests\Features\TestCliGateway;

require_once dirname( __DIR__, 2 ) . '/WpCliFeatureTest.php';

/**
 * @covers \Saltus\WP\Framework\Features\WpCli\Commands\ConfigCommand
 */
class ConfigCommandTest extends TestCase {

	public function testValidateSucceedsWithNoErrors(): void {
		$cli = new TestCliGateway();

		$this->run_validate(
			$cli,
			( new ConfigValidationSummary() )
				->with( new ConfigValidationResult( 'book', [] ) )
				->with( new ConfigValidationResult( 'author', [] ) )
				->with( new ConfigValidationResult( 'movie', [] ) )
		);

		$this->assertStringContainsString( '3 passed, 0 failed', $cli->output() );
		$this->assertStringContainsString( 'All model configurations are valid', $cli->output() );
		$this->assertSame( 0, $cli->exit_code(), 'Valid configs must exit 0.' );
	}

	public function testValidateFailsWithErrorsAndNamesEachOne(): void {
		$cli = new TestCliGateway();

		$this->run_validate(
			$cli,
			( new ConfigValidationSummary() )
				->with(
					new ConfigValidationResult(
						'book',
						[ ConfigError::error( 'book', 'type', 'type.unknown', 'Unknown type alias "posts"' ) ]
					)
				)
				->with(
					new ConfigValidationResult(
						'author',
						[ ConfigError::error( 'author', 'name', 'name.too_long', 'Name exceeds 20 characters' ) ]
					)
				)
		);

		$this->assertStringContainsString( '0 passed, 2 failed', $cli->output() );
		$this->assertSame( 1, $cli->exit_code(), 'Invalid configs must exit 1.' );

		// The errors go through format_items, not the message stream, so the table
		// rows are where the detail has to appear.
		$rows = $cli->formats[0]['items'];
		$this->assertSame( [ 'book', 'author' ], array_column( $rows, 'model' ) );
		$this->assertSame( [ 'type', 'name' ], array_column( $rows, 'path' ) );
		$this->assertStringContainsString( 'Unknown type alias', $rows[0]['error'] );
		$this->assertStringContainsString( 'exceeds 20 characters', $rows[1]['error'] );
	}

	/**
	 * A failing run must not also claim success.
	 *
	 * `WP_CLI::halt()` exits, so production never reaches the success line — but the
	 * command must not depend on that. Without the return after `halt()`, this run
	 * prints its errors and then reports the config valid.
	 */
	public function testValidateDoesNotReportSuccessAfterHalting(): void {
		$cli = new TestCliGateway();

		$this->run_validate(
			$cli,
			( new ConfigValidationSummary() )->with(
				new ConfigValidationResult(
					'book',
					[ ConfigError::error( 'book', 'type', 'type.unknown', 'Unknown type' ) ]
				)
			)
		);

		$this->assertStringNotContainsString( 'All model configurations are valid', $cli->output() );
	}

	/** One model with several problems is still one failing model. */
	public function testTheSummaryCountsFailingModelsNotIndividualErrors(): void {
		$cli = new TestCliGateway();

		$this->run_validate(
			$cli,
			( new ConfigValidationSummary() )
				->with(
					new ConfigValidationResult(
						'book',
						[
							ConfigError::error( 'book', 'type', 'type.unknown', 'Unknown type' ),
							ConfigError::error( 'book', 'name', 'name.too_long', 'Name too long' ),
						]
					)
				)
				->with( new ConfigValidationResult( 'movie', [] ) )
		);

		$this->assertStringContainsString( 'Validated 2 model(s): 1 passed, 1 failed', $cli->output() );
		$this->assertCount( 2, $cli->formats[0]['items'], 'Both errors still get a row.' );
	}

	/** Warnings are not failures: the command reports success and exits 0. */
	public function testWarningsDoNotFailTheCommand(): void {
		$cli = new TestCliGateway();

		$this->run_validate(
			$cli,
			( new ConfigValidationSummary() )->with(
				new ConfigValidationResult(
					'book',
					[ ConfigError::warning( 'book', 'taxonomies', 'unknown_key', 'No such config key' ) ]
				)
			)
		);

		$this->assertStringContainsString( '1 passed, 0 failed', $cli->output() );
		$this->assertSame( 0, $cli->exit_code(), 'A warning must not fail CI.' );
	}

	public function testValidateErrorsWhenValidationIsUnavailable(): void {
		$cli = new TestCliGateway();

		// The real gateway's error() exits; the double throws to model that.
		try {
			$this->run_validate( $cli, null );
			$this->fail( 'An unavailable verdict must not be reported as a pass.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'not available', $e->getMessage() );
			$this->assertStringContainsString( 'No models have been loaded', $e->getMessage() );
		}
	}

	public function testValidateRespectsFormatFlag(): void {
		$cli = new TestCliGateway();

		$this->run_validate(
			$cli,
			( new ConfigValidationSummary() )->with(
				new ConfigValidationResult(
					'book',
					[ ConfigError::error( 'book', 'meta.fields.title.type', 'meta.field_type', 'Unknown field type "rich_text"' ) ]
				)
			),
			[ 'format' => 'json' ]
		);

		$this->assertSame( 'json', $cli->last_format() );
	}

	/** `--strict` is what turns a warning into a CI failure. */
	public function testStrictMakesWarningsFailTheCommand(): void {
		$cli = new TestCliGateway();

		$this->run_validate(
			$cli,
			( new ConfigValidationSummary() )->with(
				new ConfigValidationResult(
					'book',
					[ ConfigError::warning( 'book', 'taxonomies', 'unknown_key', 'No such config key' ) ]
				)
			),
			[ 'strict' => true ]
		);

		$this->assertSame( 1, $cli->exit_code(), 'Under --strict a warning must fail.' );
		$this->assertStringNotContainsString( 'All model configurations are valid', $cli->output() );

		// The warning has to be named, and marked as a warning: a strict run mixes
		// both severities into one table.
		$rows = $cli->formats[0]['items'];
		$this->assertSame( [ 'warning' ], array_column( $rows, 'severity' ) );
		$this->assertSame( [ 'taxonomies' ], array_column( $rows, 'path' ) );
		$this->assertContains( 'severity', $cli->formats[0]['fields'] );
	}

	/** A strict run still passes when nothing at all was found. */
	public function testStrictExitsZeroWithoutProblems(): void {
		$cli = new TestCliGateway();

		$this->run_validate(
			$cli,
			( new ConfigValidationSummary() )->with( new ConfigValidationResult( 'book', [] ) ),
			[ 'strict' => true ]
		);

		$this->assertSame( 0, $cli->exit_code() );
		$this->assertStringContainsString( 'All model configurations are valid', $cli->output() );
	}

	/**
	 * A strict run reports errors and warnings together.
	 *
	 * The default run lists only errors, so a warning alongside one would otherwise
	 * be counted in the exit status but never shown.
	 */
	public function testStrictListsWarningsBesideErrors(): void {
		$cli = new TestCliGateway();

		$this->run_validate(
			$cli,
			( new ConfigValidationSummary() )->with(
				new ConfigValidationResult(
					'book',
					[
						ConfigError::error( 'book', 'type', 'type.unknown', 'Unknown type' ),
						ConfigError::warning( 'book', 'taxonomies', 'unknown_key', 'No such config key' ),
					]
				)
			),
			[ 'strict' => true ]
		);

		$rows = $cli->formats[0]['items'];
		$this->assertSame( [ 'error', 'warning' ], array_column( $rows, 'severity' ) );
		$this->assertStringContainsString( '1 with warnings', $cli->output() );
	}

	/** `--model` narrows both the counts and the error rows to that one model. */
	public function testModelFlagNarrowsValidationToOneModel(): void {
		$cli = new TestCliGateway();

		$this->run_validate(
			$cli,
			( new ConfigValidationSummary() )
				->with(
					new ConfigValidationResult(
						'book',
						[ ConfigError::error( 'book', 'type', 'type.unknown', 'Unknown type' ) ]
					)
				)
				->with(
					new ConfigValidationResult(
						'author',
						[ ConfigError::error( 'author', 'name', 'name.too_long', 'Name too long' ) ]
					)
				),
			[ 'model' => 'author' ]
		);

		$this->assertStringContainsString( 'Validated 1 model(s): 0 passed, 1 failed', $cli->output() );
		$this->assertSame( [ 'author' ], array_column( $cli->formats[0]['items'], 'model' ) );
	}

	/** Narrowing to a clean model passes even while another model is broken. */
	public function testModelFlagIgnoresOtherModelsFailures(): void {
		$cli = new TestCliGateway();

		$this->run_validate(
			$cli,
			( new ConfigValidationSummary() )
				->with( new ConfigValidationResult( 'book', [] ) )
				->with(
					new ConfigValidationResult(
						'author',
						[ ConfigError::error( 'author', 'name', 'name.too_long', 'Name too long' ) ]
					)
				),
			[ 'model' => 'book' ]
		);

		$this->assertSame( 0, $cli->exit_code() );
		$this->assertStringContainsString( 'Validated 1 model(s): 1 passed, 0 failed', $cli->output() );
	}

	/**
	 * An unknown `--model` fails instead of reporting nothing to validate.
	 *
	 * "Validated 0 model(s), all valid" is indistinguishable from a clean site, so
	 * a typo in a CI invocation would pass forever.
	 */
	public function testUnknownModelFlagFails(): void {
		$cli = new TestCliGateway();

		try {
			$this->run_validate(
				$cli,
				( new ConfigValidationSummary() )->with( new ConfigValidationResult( 'book', [] ) ),
				[ 'model' => 'boook' ]
			);
			$this->fail( 'A misspelled model name must not be reported as a pass.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Model not found: boook', $e->getMessage() );
		}

		$this->assertStringNotContainsString( 'All model configurations are valid', $cli->output() );
	}

	/** `--format=csv` is declared in the synopsis, so it must not become a table. */
	public function testValidatePassesCsvFormatThrough(): void {
		$cli = new TestCliGateway();

		$this->run_validate(
			$cli,
			( new ConfigValidationSummary() )->with(
				new ConfigValidationResult(
					'book',
					[ ConfigError::error( 'book', 'type', 'type.unknown', 'Unknown type' ) ]
				)
			),
			[ 'format' => 'csv' ]
		);

		$this->assertSame( 'csv', $cli->last_format() );
	}

	/** A format nobody renders still falls back rather than reaching the formatter. */
	public function testValidateFallsBackForUnsupportedFormat(): void {
		$cli = new TestCliGateway();

		$this->run_validate(
			$cli,
			( new ConfigValidationSummary() )->with(
				new ConfigValidationResult(
					'book',
					[ ConfigError::error( 'book', 'type', 'type.unknown', 'Unknown type' ) ]
				)
			),
			[ 'format' => 'xml' ]
		);

		$this->assertSame( 'table', $cli->last_format() );
	}

	/**
	 * @param array<string, mixed> $assoc_args
	 */
	private function run_validate( TestCliGateway $cli, ?ConfigValidationSummary $summary, array $assoc_args = [] ): void {
		$modeler = $this->modeler_with( $summary );

		( new ConfigCommand( $cli, static fn(): Modeler => $modeler ) )->validate( [], $assoc_args );
	}

	private function modeler_with( ?ConfigValidationSummary $summary ): Modeler {
		return new class( $summary ) extends Modeler {
			private ?ConfigValidationSummary $summary;

			public function __construct( ?ConfigValidationSummary $summary ) {
				// Skips the parent constructor: reporting a verdict needs no container.
				$this->summary = $summary;
			}

			public function get_config_validation(): ?ConfigValidationSummary {
				return $this->summary;
			}
		};
	}
}
