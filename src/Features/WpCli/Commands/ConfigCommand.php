<?php
namespace Saltus\WP\Framework\Features\WpCli\Commands;

use Saltus\WP\Framework\Models\ConfigValidationResult;
use Saltus\WP\Framework\Models\ConfigValidationSummary;

/**
 * Validates model configuration files.
 */
final class ConfigCommand extends AbstractCommand {
	/**
	 * Validate all model configuration files.
	 *
	 * ## OPTIONS
	 *
	 * [--model=<name>]
	 * : Validate only this model, by name. Unknown names are an error.
	 *
	 * [--strict]
	 * : Exit non-zero on warnings too, and list them beside the errors.
	 *
	 * [--format=<format>]
	 * : Output format (table, json, yaml, csv).
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp saltus config validate
	 *     wp saltus config validate --format=json
	 *     wp saltus config validate --model=book
	 *     wp saltus config validate --strict
	 *
	 * @param list<string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function validate( array $args, array $assoc_args ): void {
		$summary = $this->modeler()->get_config_validation();

		if ( $summary === null ) {
			$this->cli->error( 'Config validation is not available. No models have been loaded yet.' );
			return;
		}

		$summary = $this->narrow( $summary, $assoc_args );
		$strict  = isset( $assoc_args['strict'] ) && $assoc_args['strict'] !== false;

		$this->print_summary( $summary, $strict );

		// Under --strict a warning is a failure, which is the whole point of the
		// flag: CI needs one exit status that covers everything the validator
		// found, not just the problems that block registration.
		if ( $summary->has_errors() || ( $strict && $summary->has_warnings() ) ) {
			$this->print_problems( $summary, $strict, $assoc_args );
			$this->cli->halt( 1 );

			// `WP_CLI::halt()` exits, so this return is unreachable in production. It
			// matters anyway: without it a gateway that records instead of exiting
			// falls through and reports the config valid in the same breath as
			// printing its errors.
			return;
		}

		$this->cli->success( 'All model configurations are valid.' );
	}

	/**
	 * The summary restricted to `--model`, or the whole summary when it is absent.
	 *
	 * An unknown name fails rather than reporting zero problems: "validated 0
	 * model(s), all valid" is the same output as a clean site, so a typo in a CI
	 * invocation would pass silently forever.
	 *
	 * @param array<string, mixed> $assoc_args
	 */
	private function narrow( ConfigValidationSummary $summary, array $assoc_args ): ConfigValidationSummary {
		$name = isset( $assoc_args['model'] ) ? trim( (string) $assoc_args['model'] ) : '';
		if ( $name === '' ) {
			return $summary;
		}

		$matches = array_values( array_filter(
			$summary->get_results(),
			static fn( ConfigValidationResult $result ): bool => $result->get_model_name() === $name
		) );

		if ( $matches === [] ) {
			$this->fail( 'Model not found: ' . $name );
		}

		return new ConfigValidationSummary( $matches );
	}

	private function print_summary( ConfigValidationSummary $summary, bool $strict ): void {
		$line = sprintf(
			'Validated %d model(s): %d passed, %d failed',
			$summary->total_count(),
			$summary->valid_count(),
			$summary->error_count()
		);

		// Only under --strict, where the warning count is what decides the exit
		// status and so has to be visible next to the counts that usually do.
		if ( $strict ) {
			$line .= sprintf( ', %d with warnings', $summary->warning_count() );
		}

		$this->cli->line( $line );
	}

	/**
	 * @param array<string, mixed> $assoc_args
	 */
	private function print_problems( ConfigValidationSummary $summary, bool $strict, array $assoc_args ): void {
		$problems = $summary->all_errors();
		if ( $strict ) {
			$problems = array_merge( $problems, $summary->all_warnings() );
		}

		$rows = [];
		foreach ( $problems as $problem ) {
			$row = [
				'model' => $problem->get_model_name(),
				'path'  => $problem->get_path(),
				'error' => $problem->get_message(),
			];

			// Strict runs mix both severities into one table, so each row has to say
			// which it is; otherwise a warning reads as a registration-blocking error.
			if ( $strict ) {
				$row = [ 'severity' => $problem->get_severity() ] + $row;
			}

			$rows[] = $row;
		}

		if ( $rows === [] ) {
			return;
		}

		$fields = $strict ? [ 'severity', 'model', 'path', 'error' ] : [ 'model', 'path', 'error' ];

		$this->cli->line( '' );
		$this->cli->format_items( $this->format( $assoc_args ), $rows, $fields );
	}
}
