<?php

namespace Saltus\WP\Framework\Features\Relationships;

use Saltus\WP\Framework\Models\Config\ConfigValidationContributor;
use Saltus\WP\Framework\Models\Config\SuggestsNearestKey;
use Saltus\WP\Framework\Models\ConfigError;

/**
 * Validation rules for the `relationships` config section.
 *
 * These rules lived in `ConfigValidator`, which had to import
 * `RelationshipDefinition` to reach the cardinality constants. They now sit beside
 * the code that enforces them: a new cardinality is added to
 * `RelationshipDefinition` and this reads it, so the two cannot disagree.
 *
 * Separate from the `Relationships` service on purpose. The service is
 * `Registerable` and wires up hooks, stores, and REST routes; these rules must be
 * constructible with no WordPress at all, because `wp saltus config validate` and
 * CI run them without a boot.
 *
 * @internal
 */
final class RelationshipConfigRules implements ConfigValidationContributor {

	use SuggestsNearestKey;

	public function get_config_section(): string {
		return 'relationships';
	}

	/**
	 * @param mixed $value Raw `relationships` value.
	 * @return list<ConfigError>
	 */
	public function validate_config_section( $value, string $model_name ): array {
		if ( ! is_array( $value ) ) {
			return [
				ConfigError::error(
					$model_name,
					'relationships',
					'relationships_not_an_object',
					'The relationships key must be a set of named relationships.',
					$value
				),
			];
		}

		$problems = [];

		foreach ( $value as $relationship => $declaration ) {
			$path = 'relationships.' . (string) $relationship;

			if ( ! is_array( $declaration ) ) {
				$problems[] = ConfigError::error(
					$model_name,
					$path,
					'relationship_not_an_object',
					'A relationship must be declared as a set of keys, including "type" and "model".',
					$declaration
				);
				continue;
			}

			foreach ( $this->check_declaration( $declaration, $path, $model_name ) as $problem ) {
				$problems[] = $problem;
			}
		}

		return $problems;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_config_schema(): array {
		return [
			'cardinalities' => $this->cardinalities(),
			'description'   => 'Named relationships between models. Each relationship requires "type", "model", and optionally "reciprocal".',
		];
	}

	/**
	 * One relationship declaration.
	 *
	 * @param array<string, mixed> $declaration
	 * @return list<ConfigError>
	 */
	private function check_declaration( array $declaration, string $path, string $model_name ): array {
		$accepted = $this->cardinalities();
		$problems = [];

		// `cardinality` is a plausible-looking name for this key and appears in some
		// older notes, but the code reads `type`. Worth saying explicitly, since the
		// symptom is a relationship that simply is not there.
		if ( ! array_key_exists( 'type', $declaration ) && array_key_exists( 'cardinality', $declaration ) ) {
			$problems[] = ConfigError::error(
				$model_name,
				$path . '.cardinality',
				'relationship_cardinality_key',
				'The cardinality key is named "type". Nothing reads "cardinality".',
				$declaration['cardinality'],
				$accepted,
				'type'
			);
		}

		if ( array_key_exists( 'type', $declaration ) ) {
			$type = $declaration['type'];

			if ( ! is_string( $type ) || ! RelationshipDefinition::is_valid_type( $type ) ) {
				$found = is_scalar( $type ) ? (string) $type : gettype( $type );

				$problems[] = ConfigError::error(
					$model_name,
					$path . '.type',
					'relationship_type_unrecognized',
					sprintf( '"%s" is not a relationship type, so this relationship will not exist.', $found ),
					$type,
					$accepted,
					$this->nearest_key( $found, $accepted )
				);
			}
		}

		if ( ! array_key_exists( 'model', $declaration ) ) {
			$problems[] = ConfigError::error(
				$model_name,
				$path . '.model',
				'relationship_model_missing',
				'No target "model" declared, so there is nothing to relate to.'
			);
		}

		return $problems;
	}

	/**
	 * Read from `RelationshipDefinition`'s own constants, never restated.
	 *
	 * @return list<string>
	 */
	private function cardinalities(): array {
		return [
			RelationshipDefinition::HAS_ONE,
			RelationshipDefinition::HAS_MANY,
			RelationshipDefinition::BELONGS_TO,
			RelationshipDefinition::MANY_TO_MANY,
		];
	}
}
