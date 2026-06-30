<?php
namespace Saltus\WP\Framework\MCP\Validation;

class Validator {

	/**
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

			if ( ! empty( $rules['enum'] ) && ! in_array( $value, $rules['enum'], true ) ) {
				$errors[] = "'{$field}' must be one of: " . implode( ', ', $rules['enum'] );
			}
		}

		return [
			'valid'  => empty( $errors ),
			'errors' => $errors,
		];
	}

	/**
	* @param mixed $value
	*/
	private static function check_type( $value, string $type ): bool {
		switch ( $type ) {
			case 'string':
				return is_string( $value );
			case 'number':
				return is_int( $value ) || is_float( $value );
			case 'boolean':
				return is_bool( $value );
			case 'object':
			case 'array':
				return is_array( $value );
			default:
				return true;
		}
	}
}
