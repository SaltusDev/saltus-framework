<?php

namespace Saltus\WP\Framework\Models;

/**
 * Every model's verdict for one site, aggregated.
 *
 * `ConfigValidationResult` answers "is this one config valid". Both the health
 * endpoint and `wp saltus config validate` ask a different question — "is this
 * *site* configured correctly, and if not, which models are wrong" — so the
 * aggregate is its own type rather than counters bolted onto the per-model
 * result. A single result cannot know how many models exist beside it.
 *
 * Immutable, and built by accumulation: `Modeler` records each verdict as it
 * validates, so the summary reflects exactly the configs that were loaded.
 *
 * @internal
 */
final class ConfigValidationSummary {

	/** @var list<ConfigValidationResult> */
	private array $results;

	/**
	 * @param array<int, ConfigValidationResult> $results Reindexed to a list internally.
	 */
	public function __construct( array $results = [] ) {
		$this->results = array_values( $results );
	}

	/**
	 * The same summary plus one more verdict.
	 *
	 * Returns a new instance rather than mutating, so a caller holding a summary
	 * cannot have it change underneath them.
	 */
	public function with( ConfigValidationResult $result ): self {
		$results   = $this->results;
		$results[] = $result;

		return new self( $results );
	}

	/** @return list<ConfigValidationResult> */
	public function get_results(): array {
		return $this->results;
	}

	/** How many models were validated. */
	public function total_count(): int {
		return count( $this->results );
	}

	/** How many models carry no error. Warnings do not make a model invalid. */
	public function valid_count(): int {
		return count( array_filter( $this->results, static fn( ConfigValidationResult $r ): bool => $r->is_valid() ) );
	}

	/**
	 * How many *models* have at least one error — not how many errors exist.
	 *
	 * The distinction matters for the health payload: `total`, `valid_count`, and
	 * `error_count` have to sum consistently, and a model with three errors is
	 * still one failing model.
	 */
	public function error_count(): int {
		return count( array_filter( $this->results, static fn( ConfigValidationResult $r ): bool => $r->has_errors() ) );
	}

	/** How many models carry at least one warning. */
	public function warning_count(): int {
		return count( array_filter( $this->results, static fn( ConfigValidationResult $r ): bool => $r->has_warnings() ) );
	}

	public function has_errors(): bool {
		return $this->error_count() > 0;
	}

	public function has_warnings(): bool {
		return $this->warning_count() > 0;
	}

	/** Whether every loaded model may register. */
	public function is_valid(): bool {
		return ! $this->has_errors();
	}

	/**
	 * Every error across every model, flattened in discovery order.
	 *
	 * @return list<ConfigError>
	 */
	public function all_errors(): array {
		$errors = [];
		foreach ( $this->results as $result ) {
			foreach ( $result->get_errors() as $error ) {
				$errors[] = $error;
			}
		}

		return $errors;
	}

	/**
	 * Every warning across every model, flattened in discovery order.
	 *
	 * @return list<ConfigError>
	 */
	public function all_warnings(): array {
		$warnings = [];
		foreach ( $this->results as $result ) {
			foreach ( $result->get_warnings() as $warning ) {
				$warnings[] = $warning;
			}
		}

		return $warnings;
	}

	/**
	 * @return array{total: int, valid: int, errors: int, warnings: int, models: list<array<string, mixed>>}
	 */
	public function to_array(): array {
		return [
			'total'    => $this->total_count(),
			'valid'    => $this->valid_count(),
			'errors'   => $this->error_count(),
			'warnings' => $this->warning_count(),
			'models'   => array_map(
				static fn( ConfigValidationResult $r ): array => $r->to_array(),
				$this->results
			),
		];
	}
}
