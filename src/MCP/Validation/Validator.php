<?php
namespace Saltus\WP\Framework\MCP\Validation;

/**
 * Static argument validator against JSON Schema-like rule definitions.
 * @api
 */
class Validator {

	/**
	 * Validate arguments against a JSON Schema-like rule definition.
	 *
	 * @param array<string, mixed> $args
	 * @param array<string, mixed> $schema
	 * @return array{valid: bool, errors: list<string>}
	 */
	public static function validate( array $args, array $schema ): array {
		$errors = [];

		foreach ( $schema as $field => $rules ) {
			$has_value = array_key_exists( $field, $args );
			$value     = $args[ $field ] ?? null;

			if ( ! empty( $rules['required'] ) && ! $has_value ) {
				$errors[] = "'{$field}' is required";
				continue;
			}

			if ( ! $has_value ) {
				continue;
			}

			$type = $rules['type'] ?? null;
			if ( $type !== null ) {
				$valid = self::check_type( $value, $type );
				if ( ! $valid ) {
					$errors[] = "'{$field}' must be of type {$type}, got " . gettype( $value );
					continue;
				}
			}

			if ( ! empty( $rules['enum'] ) && ! self::matches_enum( $value, $rules['enum'], $type ) ) {
				$errors[] = "'{$field}' must be one of: " . implode( ', ', $rules['enum'] );
			}
		}

		return [
			'valid'  => empty( $errors ),
			'errors' => $errors,
		];
	}

	/**
	 * Convert accepted scalar spellings to their declared type.
	 *
	 * Accepting a spelling is only safe if what reaches the tool means what the
	 * caller wrote. `'false'` is a valid boolean by the rules below and by
	 * WordPress', but it is a non-empty string, so a tool testing it with
	 * `! empty()` reads it as true and does the opposite of what was asked. Over
	 * REST the ability run route sanitizes to the schema before dispatch and the
	 * problem never appears; a direct `execute()` has no such step, so the
	 * conversion belongs here, beside the check that widened the acceptance.
	 *
	 * Runs after validation and only over values that passed it, which is the
	 * order core uses. Anything absent, untyped, or of an unrecognised type is
	 * returned untouched.
	 *
	 * @param array<string, mixed> $args   Arguments that already validated.
	 * @param array<string, mixed> $schema Saltus parameter rules.
	 * @return array<string, mixed>
	 */
	public static function coerce( array $args, array $schema ): array {
		foreach ( $schema as $field => $rules ) {
			if ( ! array_key_exists( $field, $args ) || ! is_array( $rules ) ) {
				continue;
			}

			$type = $rules['type'] ?? null;
			if ( ! is_string( $type ) ) {
				continue;
			}

			$args[ $field ] = self::to_declared_type( $args[ $field ], $type );
		}

		return $args;
	}

	/**
	 * A value in the type its rules declare.
	 *
	 * @param mixed  $value Value that passed `check_type()` for this type.
	 * @param string $type  Declared JSON Schema type.
	 * @return mixed
	 */
	private static function to_declared_type( $value, string $type ) {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return $value;
		}

		switch ( $type ) {
			case 'boolean':
				if ( is_int( $value ) ) {
					return $value === 1;
				}

				return ! in_array( strtolower( $value ), [ 'false', '0' ], true );
			case 'integer':
				return is_string( $value ) ? (int) $value : $value;
			case 'number':
				if ( ! is_string( $value ) ) {
					return $value;
				}

				// A whole numeric string stays an int so a tool comparing it
				// against an int identity keeps matching.
				return (float) $value === floor( (float) $value ) && strpos( $value, '.' ) === false
					? (int) $value
					: (float) $value;
			default:
				return $value;
		}
	}

	/**
	 * Check whether a value matches the expected type.
	 *
	 * Accepts the same scalar spellings WordPress does. `rest_is_integer()` takes
	 * a canonical integer string, the `number` check is `is_numeric`, and
	 * `rest_is_boolean()` takes `'true'`, `'false'`, `'0'` and `'1'`. Core
	 * validates an ability's input before the tool runs, so anything stricter
	 * here rejects a value core already accepted: over REST the request
	 * sanitizer coerces first and hides it, but a direct
	 * `wp_get_ability( … )->execute()` from WP-CLI or another plugin passes the
	 * raw value straight through and used to fail on `'12'`.
	 *
	 * `object` and `array` stay strict. Core accepts a scalar for an array and
	 * converts it while sanitizing; this validator does not convert, so widening
	 * the check would let a comma-separated string reach a tool expecting a list.
	 *
	 * @param mixed  $value The value to check.
	 * @param string $type  The expected JSON Schema type.
	 */
	private static function check_type( $value, string $type ): bool {
		switch ( $type ) {
			case 'string':
				return is_string( $value );
			case 'integer':
				return self::is_integerish( $value );
			case 'number':
				return is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) );
			case 'boolean':
				return is_bool( $value )
					|| ( is_int( $value ) && ( $value === 0 || $value === 1 ) )
					|| ( is_string( $value ) && in_array( strtolower( $value ), [ 'true', 'false', '0', '1' ], true ) );
			case 'object':
				return is_array( $value ) && ! self::is_list( $value );
			case 'array':
				return is_array( $value ) && self::is_list( $value );
			default:
				return true;
		}
	}

	/**
	 * Whether a value is an integer or spells one exactly.
	 *
	 * Mirrors `rest_is_integer()`: a canonical integer string, or any numeric
	 * value with no fractional part.
	 *
	 * @param mixed $value The value to check.
	 */
	private static function is_integerish( $value ): bool {
		if ( is_int( $value ) ) {
			return true;
		}

		if ( is_string( $value ) && preg_match( '/^\s*[+-]?[0-9]+\s*$/', $value ) === 1 ) {
			return true;
		}

		if ( ! is_numeric( $value ) ) {
			return false;
		}

		return floor( (float) $value ) === (float) $value;
	}

	/**
	 * Whether a value appears in an enum.
	 *
	 * Core sanitizes to the declared type before comparing, so `'12'` satisfies
	 * an enum of `[ 12 ]`. Comparing strictly against the raw value rejected
	 * that. Only a lossless scalar spelling is normalized; the comparison itself
	 * stays strict, so `'1'` never matches `true`.
	 *
	 * @param mixed       $value   The value to look for.
	 * @param list<mixed> $allowed Allowed values.
	 * @param string|null $type    Declared type, when the rules state one.
	 */
	private static function matches_enum( $value, array $allowed, ?string $type ): bool {
		if ( in_array( $value, $allowed, true ) ) {
			return true;
		}

		if ( ! is_string( $value ) || ! in_array( $type, [ 'integer', 'number' ], true ) ) {
			return false;
		}

		if ( ! is_numeric( $value ) ) {
			return false;
		}

		$numeric = $type === 'integer' ? (int) $value : (float) $value;

		return in_array( $numeric, $allowed, true );
	}

	/**
	 * Check whether an array is a list (sequential integer keys from 0).
	 *
	 * Compatible with PHP 7.4 (replaces array_is_list which requires PHP 8.1).
	 *
	 * @param mixed $value  The value to check.
	 * @return bool
	 */
	private static function is_list( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}

		if ( $value === [] ) {
			return true;
		}

		$expected_key = 0;
		foreach ( $value as $key => $val ) {
			if ( $key !== $expected_key ) {
				return false;
			}
			++$expected_key;
		}

		return true;
	}
}
