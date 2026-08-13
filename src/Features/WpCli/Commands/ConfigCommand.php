<?php
namespace Saltus\WP\Framework\Features\WpCli\Commands;

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

		$this->print_summary( $summary );

		if ( $summary->has_errors() ) {
			$this->print_errors( $summary, $assoc_args );
			$this->cli->halt( 1 );

			// `WP_CLI::halt()` exits, so this return is unreachable in production. It
			// matters anyway: without it a gateway that records instead of exiting
			// falls through and reports the config valid in the same breath as
			// printing its errors.
			return;
		}

		$this->cli->success( 'All model configurations are valid.' );
	}

	private function print_summary( ConfigValidationSummary $summary ): void {
		$this->cli->line( sprintf(
			'Validated %d model(s): %d passed, %d failed',
			$summary->total_count(),
			$summary->valid_count(),
			$summary->error_count()
		) );
	}

	/**
	 * @param array<string, mixed> $assoc_args
	 */
	private function print_errors( ConfigValidationSummary $summary, array $assoc_args ): void {
		$rows = [];
		foreach ( $summary->all_errors() as $error ) {
			$rows[] = [
				'model' => $error->get_model_name(),
				'path'  => $error->get_path(),
				'error' => $error->get_message(),
			];
		}

		if ( $rows === [] ) {
			return;
		}

		$this->cli->line( '' );
		$this->cli->format_items( $this->format( $assoc_args ), $rows, [ 'model', 'path', 'error' ] );
	}
}
