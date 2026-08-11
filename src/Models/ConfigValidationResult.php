<?php

namespace Saltus\WP\Framework\Models;

/**
 * The outcome of validating one model configuration.
 *
 * Immutable. Holds every problem found, and separates the two severities because
 * they have different consequences: errors stop the model registering, warnings
 * are reported and the model registers anyway. `is_valid()` therefore asks only
 * about errors — a config with warnings is still valid enough to load, which is
 * what keeps an existing site working after an upgrade.
 *
 * Serializable to a plain array so a verdict can be cached without storing an
 * object, which would break the moment this class changes.
 *
 * @internal
 */
final class ConfigValidationResult {

	private string $model_name;

	/** @var list<ConfigError> */
	private array $problems;

	/**
	 * @param array<int, ConfigError> $problems Reindexed to a list internally.
	 */
	public function __construct( string $model_name, array $problems = [] ) {
		$this->model_name = $model_name;
		$this->problems   = array_values( $problems );
	}

	public function get_model_name(): string {
		return $this->model_name;
	}

	/**
	 * Every problem found, errors and warnings together, in discovery order.
	 *
	 * @return list<ConfigError>
	 */
	public function get_problems(): array {
		return $this->problems;
	}

	/** @return list<ConfigError> */
	public function get_errors(): array {
		return array_values( array_filter( $this->problems, static fn( ConfigError $p ): bool => $p->is_error() ) );
	}

	/** @return list<ConfigError> */
	public function get_warnings(): array {
		return array_values( array_filter( $this->problems, static fn( ConfigError $p ): bool => $p->is_warning() ) );
	}

	public function has_errors(): bool {
		return $this->get_errors() !== [];
	}

	public function has_warnings(): bool {
		return $this->get_warnings() !== [];
	}

	/**
	 * Whether the model may register.
	 *
	 * Warnings do not make a config invalid. Only an error does.
	 */
	public function is_valid(): bool {
		return ! $this->has_errors();
	}

	/**
	 * @return array{model: string, valid: bool, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>}
	 */
	public function to_array(): array {
		return [
			'model'    => $this->model_name,
			'valid'    => $this->is_valid(),
			'errors'   => array_map( static fn( ConfigError $p ): array => $p->to_array(), $this->get_errors() ),
			'warnings' => array_map( static fn( ConfigError $p ): array => $p->to_array(), $this->get_warnings() ),
		];
	}

	/**
	 * Rebuild a result from its array form.
	 *
	 * The cache stores plain arrays, so this is how a cached verdict becomes a
	 * result again. Unrecognized severities are dropped rather than guessed at: a
	 * cache entry written by a different version should degrade to "no problem
	 * recorded", not to a fabricated error.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function from_array( array $data ): self {
		$model    = isset( $data['model'] ) ? (string) $data['model'] : '';
		$problems = [];

		foreach ( [ 'errors', 'warnings' ] as $bucket ) {
			$entries = $data[ $bucket ] ?? [];
			if ( ! is_array( $entries ) ) {
				continue;
			}

			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				$problem = self::problem_from_array( $model, $entry );
				if ( $problem instanceof ConfigError ) {
					$problems[] = $problem;
				}
			}
		}

		return new self( $model, $problems );
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function problem_from_array( string $model, array $entry ): ?ConfigError {
		$severity = isset( $entry['severity'] ) ? (string) $entry['severity'] : '';
		$path     = isset( $entry['path'] ) ? (string) $entry['path'] : '';
		$rule     = isset( $entry['rule'] ) ? (string) $entry['rule'] : '';
		$message  = isset( $entry['message'] ) ? (string) $entry['message'] : '';
		$accepted = isset( $entry['accepted'] ) && is_array( $entry['accepted'] )
			? array_values( array_map( 'strval', $entry['accepted'] ) )
			: [];

		$suggestion = isset( $entry['suggestion'] ) && is_string( $entry['suggestion'] )
			? $entry['suggestion']
			: null;

		$found = $entry['found'] ?? null;

		if ( $severity === ConfigError::SEVERITY_ERROR ) {
			return ConfigError::error( $model, $path, $rule, $message, $found, $accepted, $suggestion );
		}

		if ( $severity === ConfigError::SEVERITY_WARNING ) {
			return ConfigError::warning( $model, $path, $rule, $message, $found, $accepted, $suggestion );
		}

		return null;
	}
}
