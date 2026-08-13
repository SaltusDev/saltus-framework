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
