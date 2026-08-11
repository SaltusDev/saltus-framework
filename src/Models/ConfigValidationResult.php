<?php

namespace Saltus\WP\Framework\Models;

/**
 * Validation result for a model configuration.
 *
 * Immutable value object carrying the model name and any errors found. A valid
 * config has zero errors; an invalid one carries one or more. The result is
 * serializable so it can be cached or logged without keeping the full validator
 * in memory.
 *
 * @internal
 */
final class ConfigValidationResult {

	private string $model_name;

	/** @var list<ConfigError> */
	private array $errors;

	/**
	 * @param string                   $model_name
	 * @param array<int, ConfigError> $errors Reindexed to a list internally.
	 */
	public function __construct( string $model_name, array $errors = [] ) {
		$this->model_name = $model_name;
		$this->errors     = array_values( $errors );
	}

	public function get_model_name(): string {
		return $this->model_name;
	}

	/**
	 * @return list<ConfigError>
	 */
	public function get_errors(): array {
		return $this->errors;
	}

	public function is_valid(): bool {
		return $this->errors === [];
	}

	/**
	 * @return array{model: string, valid: bool, errors: list<array{path: string, rule: string, message: string}>}
	 */
	public function to_array(): array {
		return [
			'model'  => $this->model_name,
			'valid'  => $this->is_valid(),
			'errors' => array_map(
				static function ( ConfigError $e ) {
					return $e->to_array();
				},
				$this->errors
			),
		];
	}
}
