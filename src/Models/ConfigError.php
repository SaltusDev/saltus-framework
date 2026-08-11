<?php

namespace Saltus\WP\Framework\Models;

/**
 * One problem found while validating a model configuration.
 *
 * Immutable. Carries enough to locate and fix the problem without the source
 * file: the model it belongs to, a dotted path to the offending key, what was
 * found there, and what would have been accepted. The source path is genuinely
 * unavailable — configs are loaded into plain arrays and filter-injected models
 * have no file at all — so `render_excerpt()` marks the key within a rendering
 * of its own subtree instead.
 *
 * Severity decides whether registration proceeds. An error means the config
 * would corrupt data or half-register a model; a warning means it will behave in
 * some way the author probably did not intend. Warnings never block, because an
 * existing site must not stop working on upgrade.
 *
 * @internal
 */
final class ConfigError {

	public const SEVERITY_ERROR   = 'error';
	public const SEVERITY_WARNING = 'warning';

	private string $severity;
	private string $model_name;
	private string $path;
	private string $rule;
	private string $message;

	/** @var mixed */
	private $found;

	/** @var list<string> */
	private array $accepted;

	private ?string $suggestion;

	/**
	 * @param mixed             $found      Value actually present at the path.
	 * @param array<int, string> $accepted   Values that would have been accepted; reindexed internally.
	 * @param string|null       $suggestion Nearest accepted value, when one is close enough to name.
	 */
	private function __construct(
		string $severity,
		string $model_name,
		string $path,
		string $rule,
		string $message,
		$found = null,
		array $accepted = [],
		?string $suggestion = null
	) {
		$this->severity   = $severity;
		$this->model_name = $model_name;
		$this->path       = $path;
		$this->rule       = $rule;
		$this->message    = $message;
		$this->found      = $found;
		$this->accepted   = array_values( $accepted );
		$this->suggestion = $suggestion;
	}

	/**
	 * A problem that stops the model registering.
	 *
	 * @param mixed        $found
	 * @param list<string> $accepted
	 */
	public static function error(
		string $model_name,
		string $path,
		string $rule,
		string $message,
		$found = null,
		array $accepted = [],
		?string $suggestion = null
	): self {
		return new self( self::SEVERITY_ERROR, $model_name, $path, $rule, $message, $found, $accepted, $suggestion );
	}

	/**
	 * A problem worth reporting that still lets the model register.
	 *
	 * @param mixed        $found
	 * @param list<string> $accepted
	 */
	public static function warning(
		string $model_name,
		string $path,
		string $rule,
		string $message,
		$found = null,
		array $accepted = [],
		?string $suggestion = null
	): self {
		return new self( self::SEVERITY_WARNING, $model_name, $path, $rule, $message, $found, $accepted, $suggestion );
	}

	public function get_severity(): string {
		return $this->severity;
	}

	public function is_error(): bool {
		return $this->severity === self::SEVERITY_ERROR;
	}

	public function is_warning(): bool {
		return $this->severity === self::SEVERITY_WARNING;
	}

	public function get_model_name(): string {
		return $this->model_name;
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

	/** @return mixed */
	public function get_found() {
		return $this->found;
	}

	/** @return list<string> */
	public function get_accepted(): array {
		return $this->accepted;
	}

	public function get_suggestion(): ?string {
		return $this->suggestion;
	}

	/**
	 * A one-line description naming the model, the path, and what to do.
	 *
	 * This is what reaches a log or a CLI table, so it has to stand alone.
	 */
	public function describe(): string {
		$line = sprintf( '%s: %s — %s', $this->model_name, $this->path, $this->message );

		if ( $this->suggestion !== null ) {
			$line .= sprintf( ' Did you mean "%s"?', $this->suggestion );
		} elseif ( $this->accepted !== [] ) {
			$line .= sprintf( ' Accepted: %s.', implode( ', ', $this->accepted ) );
		}

		return $line;
	}

	/**
	 * Render the config around the offending key, with that key marked.
	 *
	 * Stands in for a file and line number, which are not available. Only the
	 * subtree containing the key is rendered, and only one level of it, so a large
	 * config does not bury the point.
	 *
	 * @param array<string|int, mixed> $config Full model config.
	 */
	public function render_excerpt( array $config ): string {
		$segments = explode( '.', $this->path );
		$leaf     = (string) array_pop( $segments );
		$subtree  = $this->resolve_subtree( $config, $segments );

		if ( ! is_array( $subtree ) ) {
			return sprintf( '  %s: %s', $leaf, $this->render_value( $subtree ) );
		}

		$prefix = $segments === [] ? '' : implode( '.', $segments ) . ':';
		$lines  = $prefix === '' ? [] : [ '  ' . $prefix ];

		foreach ( $subtree as $key => $value ) {
			$marker  = (string) $key === $leaf ? '> ' : '  ';
			$indent  = $prefix === '' ? '' : '  ';
			$lines[] = sprintf( '%s%s%s: %s', $marker, $indent, $key, $this->render_value( $value ) );
		}

		// A key that is absent entirely — a missing required key — has nothing to
		// mark, so say so rather than rendering a subtree that looks fine.
		if ( ! array_key_exists( $leaf, $subtree ) ) {
			$lines[] = sprintf( '> %s%s: (missing)', $prefix === '' ? '' : '  ', $leaf );
		}

		return implode( "\n", $lines );
	}

	/**
	 * @return array{severity: string, model: string, path: string, rule: string, message: string, found: mixed, accepted: list<string>, suggestion: string|null}
	 */
	public function to_array(): array {
		return [
			'severity'   => $this->severity,
			'model'      => $this->model_name,
			'path'       => $this->path,
			'rule'       => $this->rule,
			'message'    => $this->message,
			'found'      => $this->found,
			'accepted'   => $this->accepted,
			'suggestion' => $this->suggestion,
		];
	}

	/**
	 * Walk down to the container holding the offending key.
	 *
	 * @param array<string|int, mixed> $config
	 * @param list<string>             $segments
	 * @return mixed
	 */
	private function resolve_subtree( array $config, array $segments ) {
		$current = $config;

		foreach ( $segments as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return null;
			}

			$current = $current[ $segment ];
		}

		return $current;
	}

	/**
	 * A short, single-line rendering of a config value.
	 *
	 * Nested structures collapse to a shape rather than expanding, because the
	 * excerpt exists to locate a key, not to dump the config.
	 *
	 * @param mixed $value
	 */
	private function render_value( $value ): string {
		if ( is_array( $value ) ) {
			return sprintf( '{%d key%s}', count( $value ), count( $value ) === 1 ? '' : 's' );
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( $value === null ) {
			return 'null';
		}

		if ( is_string( $value ) ) {
			return sprintf( '"%s"', $value );
		}

		return (string) ( is_scalar( $value ) ? $value : gettype( $value ) );
	}
}
