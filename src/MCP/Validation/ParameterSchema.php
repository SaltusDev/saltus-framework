<?php
namespace Saltus\WP\Framework\MCP\Validation;

/**
 * Projects a Saltus parameter map into a JSON Schema object.
 *
 * Tools describe their arguments as a bare `name => rules` map, which suits
 * {@see Validator} but is not itself a schema. Anything that publishes those
 * arguments to a client must wrap them first, because a consumer reads the
 * top level as keyword slots: a parameter called `type`, `items`, `title`, or
 * `description` is then read as the schema's own keyword and every other
 * parameter is discarded as an unknown keyword.
 *
 * WordPress 7.1 made that concrete. `wp_prepare_json_schema_for_client()`
 * keeps only recognised keywords, and `rest_validate_value_from_schema()`
 * reads a `type` slot as a type declaration.
 *
 * @api
 */
final class ParameterSchema {

	/**
	 * Wrap a parameter map in a JSON Schema object.
	 *
	 * Saltus states `required` as a per-parameter boolean; JSON Schema states it
	 * as a sibling array of names, so the flag is lifted out of each property.
	 * `additionalProperties` is deliberately left unset: tools accept arguments
	 * their parameter map does not enumerate, and closing the object here would
	 * strip them during input sanitization.
	 *
	 * A top-level `default` of `[]` is what makes an argument-free call work.
	 * `WP_Ability::normalize_input()` substitutes the schema's `default` only
	 * when the caller sent nothing, and validation then runs on whatever came
	 * back. Without a `default` the input stays `null`, which is not an object,
	 * so every ability rejected a call that omitted `input` - including the
	 * tools that take no arguments at all. The empty object satisfies the type
	 * without satisfying `required`, so a tool that does need arguments still
	 * refuses the call and now names the property it wanted.
	 *
	 * @param array<string, mixed> $parameters Saltus parameter definitions.
	 * @return array<string, mixed> JSON Schema object describing the arguments.
	 */
	public static function to_json_schema( array $parameters ): array {
		$properties = [];
		$required   = [];

		foreach ( $parameters as $name => $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			if ( ! empty( $definition['required'] ) ) {
				$required[] = (string) $name;
			}
			unset( $definition['required'] );

			$properties[ $name ] = $definition;
		}

		$schema = [
			'type'       => 'object',
			'properties' => $properties,
			'default'    => [],
		];

		if ( $required !== [] ) {
			$schema['required'] = $required;
		}

		return $schema;
	}
}
