<?php

namespace Saltus\WP\Framework\Models;

/**
 * A single validation error encountered while checking a model configuration.
 *
 * Immutable value object carrying the path to the invalid value, the rule that
 * failed, and a message suitable for display or logging. The path is a
 * dot-separated string like `fields.author.type` rather than a structured
 * array, so it can be logged or shown without parsing.
 *
 * @internal
 */
final class ConfigError {

	private string $path;
	private string $rule;
	private string $message;

	public function __construct( string $path, string $rule, string $message ) {
		$this->path    = $path;
		$this->rule    = $rule;
		$this->message = $message;
	}

	public function get_path(): string {
		return $this->path;
	}

	public function get_rule(): string {
		return $this->rule;
	}

	public function get_message(): string {
		return $this->message;
	}

	/**
	 * @return array{path: string, rule: string, message: string}
	 */
	public function to_array(): array {
		return [
			'path'    => $this->path,
			'rule'    => $this->rule,
			'message' => $this->message,
		];
	}
}
