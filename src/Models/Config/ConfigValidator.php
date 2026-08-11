<?php

namespace Saltus\WP\Framework\Models\Config;

use Noodlehaus\AbstractConfig;
use Saltus\WP\Framework\Features\Relationships\RelationshipDefinition;
use Saltus\WP\Framework\Models\ConfigError;
use Saltus\WP\Framework\Models\ConfigValidationResult;

/**
 * Checks a model configuration before the framework tries to register it.
 *
 * Two severities, and the split is the whole design. An **error** means the
 * config would corrupt data or half-register a model, so registration stops. A
 * **warning** means the config will do something the author probably did not
 * intend — a key that is silently inert, a value that disables what it looks like
 * it enables — so it is reported and registration continues. An existing site
 * must never stop working because a new rule was added here.
 *
 * Rules are grouped one method per config section, both to keep each testable in
 * isolation and to stay under the complexity limit the repo enforces.
 *
 * @internal
 */
final class ConfigValidator {

	/**
	 * Maximum edit distance for a "did you mean" suggestion.
	 *
	 * Two lets `has_meny` reach `has_many` and `taxonomys` reach `taxonomies`
	 * without `meta` suggesting `beta`. Above this, no suggestion is offered — a
	 * wrong guess is worse than none, because it sends the author to the wrong key.
	 */
	private const SUGGESTION_MAX_DISTANCE = 2;

	private SchemaBuilder $schema_builder;

	/** @var array<string, mixed>|null */
	private ?array $schema = null;

	public function __construct( ?SchemaBuilder $schema_builder = null ) {
		$this->schema_builder = $schema_builder ?? new SchemaBuilder();
	}

	/**
	 * Validate one model config.
	 *
	 * @param AbstractConfig|array<string|int, mixed> $config     Model config.
	 * @param string                                  $model_name Name for reporting; falls back to the config's own.
	 */
	public function validate( $config, string $model_name = '' ): ConfigValidationResult {
		$data = $config instanceof AbstractConfig ? $config->all() : $config;
		$name = $model_name !== '' ? $model_name : $this->resolve_name( $data );

		$problems = array_merge(
			$this->check_type( $data, $name ),
			$this->check_name( $data, $name ),
			$this->check_active( $data, $name ),
			$this->check_unknown_top_level_keys( $data, $name ),
			$this->check_relationships( $data, $name ),
			$this->check_features( $data, $name ),
			$this->check_meta( $data, $name ),
			$this->check_associations( $data, $name )
		);

		return new ConfigValidationResult( $name, $problems );
	}

	/**
	 * `type` must be present and one of the aliases ModelFactory accepts.
	 *
	 * Both are errors: without a recognized type, `ModelFactory::create()` returns
	 * null and the model silently does not exist.
	 *
	 * @param array<string|int, mixed> $data
	 * @return list<ConfigError>
	 */
	private function check_type( array $data, string $model_name ): array {
		$accepted = $this->schema()['type']['accepted'];

		if ( ! array_key_exists( 'type', $data ) ) {
			return [
				ConfigError::error(
					$model_name,
					'type',
					'type_missing',
					'No "type" declared, so this model cannot be registered.',
					null,
					$accepted
				),
			];
		}

		$type = $data['type'];

		if ( ! is_string( $type ) || ! in_array( $type, $accepted, true ) ) {
			$found = is_scalar( $type ) ? (string) $type : gettype( $type );

			return [
				ConfigError::error(
					$model_name,
					'type',
					'type_unrecognized',
					sprintf( '"%s" is not a model type this framework recognizes.', $found ),
					$type,
					$accepted,
					$this->nearest( $found, $accepted )
				),
			];
		}

		return [];
	}

	/**
	 * `name` must fit the column WordPress stores it in.
	 *
	 * An over-long name currently throws from `BaseModel`, so catching it here
	 * turns a stack trace into a message naming the key and the limit.
	 *
	 * @param array<string|int, mixed> $data
	 * @return list<ConfigError>
	 */
	private function check_name( array $data, string $model_name ): array {
		if ( ! array_key_exists( 'name', $data ) || ! is_string( $data['name'] ) ) {
			return [];
		}

		$name      = $data['name'];
		$is_tax    = $this->is_taxonomy( $data );
		$limit     = $is_tax ? $this->schema()['name']['taxonomy_max'] : $this->schema()['name']['post_type_max'];
		$kind      = $is_tax ? 'taxonomy' : 'post type';
		$sanitized = strlen( $name );

		if ( $sanitized <= $limit ) {
			return [];
		}

		return [
			ConfigError::error(
				$model_name,
				'name',
				'name_too_long',
				sprintf(
					'"%s" is %d characters; a %s name may be at most %d.',
					$name,
					$sanitized,
					$kind,
					$limit
				),
				$name
			),
		];
	}

	/**
	 * `active` is only honored as a strict `true`.
	 *
	 * A truthy non-true value — `1`, `"yes"` — reads as false and *disables* the
	 * model, which is the opposite of what the author wrote. A warning, because the
	 * behavior is long-standing and some site may rely on it.
	 *
	 * @param array<string|int, mixed> $data
	 * @return list<ConfigError>
	 */
	private function check_active( array $data, string $model_name ): array {
		if ( ! array_key_exists( 'active', $data ) ) {
			return [];
		}

		$active = $data['active'];

		if ( $active === true || $active === false || $active === null || $active === '' ) {
			return [];
		}

		return [
			ConfigError::warning(
				$model_name,
				'active',
				'active_not_strict_true',
				'Only a literal true enables a model. This value is treated as false, so the model will not register.',
				$active,
				[ 'true', 'false' ]
			),
		];
	}

	/**
	 * Top-level keys nothing reads.
	 *
	 * A warning with a suggestion where one is close. `taxonomies` is the case
	 * worth naming: it looks obvious but nothing reads it — a taxonomy declares its
	 * post types from its own side, via `associations`.
	 *
	 * @param array<string|int, mixed> $data
	 * @return list<ConfigError>
	 */
	private function check_unknown_top_level_keys( array $data, string $model_name ): array {
		$known    = $this->schema()['known_top_level_keys'];
		$problems = [];

		foreach ( array_keys( $data ) as $key ) {
			$key = (string) $key;

			if ( in_array( $key, $known, true ) ) {
				continue;
			}

			$problems[] = ConfigError::warning(
				$model_name,
				$key,
				'unknown_key',
				sprintf( 'Nothing reads "%s", so this key has no effect.', $key ),
				$data[ $key ] ?? null,
				[],
				$this->nearest( $key, $known )
			);
		}

		return $problems;
	}

	/**
	 * Each relationship needs a recognized cardinality and a target model.
	 *
	 * An unrecognized cardinality is an error: `RelationshipRegistry` skips the
	 * declaration entirely, so the relationship silently does not exist.
	 *
	 * @param array<string|int, mixed> $data
	 * @return list<ConfigError>
	 */
	private function check_relationships( array $data, string $model_name ): array {
		$section = $data['relationships'] ?? null;
		if ( ! is_array( $section ) ) {
			return [];
		}

		$accepted = $this->schema()['relationships']['cardinalities'];
		$problems = [];

		foreach ( $section as $relationship => $declaration ) {
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

			$problems = array_merge( $problems, $this->check_relationship( $declaration, $path, $model_name, $accepted ) );
		}

		return $problems;
	}

	/**
	 * @param array<string|int, mixed> $declaration
	 * @param list<string>             $accepted
	 * @return list<ConfigError>
	 */
	private function check_relationship( array $declaration, string $path, string $model_name, array $accepted ): array {
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
					$this->nearest( $found, $accepted )
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
	 * The `drag_and_drop` / `draganddrop` pair.
	 *
	 * These are two different keys, not spellings of one. `draganddrop` loads the
	 * admin reordering UI; `drag_and_drop` is read only by the permission policies
	 * as a capability declaration. Feature dispatch lowercases but does not strip
	 * underscores, so neither can ever resolve to the other. Declaring one alone is
	 * usually a mistake, so each gets a warning naming what is missing — never an
	 * auto-correction, since both spellings are legitimate.
	 *
	 * @param array<string|int, mixed> $data
	 * @return list<ConfigError>
	 */
	private function check_features( array $data, string $model_name ): array {
		$features = $data['features'] ?? null;
		if ( ! is_array( $features ) ) {
			return [];
		}

		$has_ui         = array_key_exists( 'draganddrop', $features );
		$has_capability = array_key_exists( 'drag_and_drop', $features );

		if ( $has_ui === $has_capability ) {
			return [];
		}

		if ( $has_ui ) {
			return [
				ConfigError::warning(
					$model_name,
					'features.draganddrop',
					'drag_and_drop_pair',
					'This loads the reordering UI but declares no reorder capability, so REST and MCP callers cannot reorder. Add "drag_and_drop" for that.',
					true,
					[ 'draganddrop', 'drag_and_drop' ]
				),
			];
		}

		return [
			ConfigError::warning(
				$model_name,
				'features.drag_and_drop',
				'drag_and_drop_pair',
				'This declares the reorder capability but loads no UI, so nothing appears in the admin. Add "draganddrop" for that.',
				true,
				[ 'draganddrop', 'drag_and_drop' ]
			),
		];
	}

	/**
	 * Meta boxes: field container shape, REST opt-in, and field types.
	 *
	 * @param array<string|int, mixed> $data
	 * @return list<ConfigError>
	 */
	private function check_meta( array $data, string $model_name ): array {
		$meta = $data['meta'] ?? null;
		if ( ! is_array( $meta ) ) {
			return [];
		}

		$problems = [];

		foreach ( $meta as $box_id => $box ) {
			if ( ! is_array( $box ) ) {
				continue;
			}

			$path     = 'meta.' . (string) $box_id;
			$problems = array_merge(
				$problems,
				$this->check_meta_box_shape( $box, $path, $model_name ),
				$this->check_meta_rest_flag( $box, $path, $model_name ),
				$this->check_meta_field_types( $box, $path, $model_name )
			);
		}

		return $problems;
	}

	/**
	 * A box holds `fields` or `sections`, and a section holds its own `fields`.
	 *
	 * A section without `fields` is an error: it reaches a method typed to receive
	 * an array and raises a TypeError.
	 *
	 * @param array<string|int, mixed> $box
	 * @return list<ConfigError>
	 */
	private function check_meta_box_shape( array $box, string $path, string $model_name ): array {
		$problems    = [];
		$has_fields  = array_key_exists( 'fields', $box );
		$has_section = array_key_exists( 'sections', $box );

		if ( $has_fields && $has_section ) {
			$problems[] = ConfigError::warning(
				$model_name,
				$path,
				'meta_box_fields_and_sections',
				'A box declaring both "fields" and "sections" only uses "fields"; the sections are ignored.',
				null,
				[ 'fields', 'sections' ]
			);
		}

		if ( ! $has_fields && ! $has_section ) {
			$problems[] = ConfigError::warning(
				$model_name,
				$path,
				'meta_box_empty',
				'A box with neither "fields" nor "sections" registers nothing.',
				null,
				[ 'fields', 'sections' ]
			);
		}

		if ( $has_section ) {
			$sections = is_array( $box['sections'] ) ? $box['sections'] : [];

			foreach ( $sections as $index => $section ) {
				if ( is_array( $section ) && ! array_key_exists( 'fields', $section ) ) {
					$problems[] = ConfigError::error(
						$model_name,
						sprintf( '%s.sections.%s.fields', $path, (string) $index ),
						'meta_section_without_fields',
						'A section must declare "fields". Without it, registering this box raises a TypeError.'
					);
				}
			}
		}

		return $problems;
	}

	/**
	 * `register_rest_api` is compared with `===  true`.
	 *
	 * A truthy non-true value reads as false, so the box quietly stays out of REST.
	 *
	 * @param array<string|int, mixed> $box
	 * @return list<ConfigError>
	 */
	private function check_meta_rest_flag( array $box, string $path, string $model_name ): array {
		if ( ! array_key_exists( 'register_rest_api', $box ) ) {
			return [];
		}

		$flag = $box['register_rest_api'];

		if ( $flag === true || $flag === false || $flag === null ) {
			return [];
		}

		return [
			ConfigError::warning(
				$model_name,
				$path . '.register_rest_api',
				'rest_flag_not_strict_true',
				'Only a literal true opts a box into REST. This value is treated as false.',
				$flag,
				[ 'true', 'false' ]
			),
		];
	}

	/**
	 * Field types not in the known map.
	 *
	 * Always a warning: the map passes through a filter, so a site may legitimately
	 * register a custom type this validator cannot see. Erroring would break those
	 * sites to catch a typo.
	 *
	 * @param array<string|int, mixed> $box
	 * @return list<ConfigError>
	 */
	private function check_meta_field_types( array $box, string $path, string $model_name ): array {
		$known    = $this->schema()['meta']['field_types'];
		$problems = [];

		foreach ( $this->collect_fields( $box ) as $field_path => $field ) {
			if ( ! array_key_exists( 'type', $field ) || ! is_string( $field['type'] ) ) {
				continue;
			}

			$type = $field['type'];
			if ( in_array( $type, $known, true ) ) {
				continue;
			}

			$problems[] = ConfigError::warning(
				$model_name,
				sprintf( '%s.%s.type', $path, $field_path ),
				'field_type_unknown',
				sprintf( '"%s" is not a field type this framework ships. If a filter registers it, ignore this.', $type ),
				$type,
				[],
				$this->nearest( $type, $known )
			);
		}

		return $problems;
	}

	/**
	 * Every field in a box, keyed by a path relative to the box.
	 *
	 * Sections and nested field groups both nest, so this flattens them into
	 * `sections.0.fields.title` style paths for reporting.
	 *
	 * @param array<string|int, mixed> $box
	 * @return array<string, array<string|int, mixed>>
	 */
	private function collect_fields( array $box ): array {
		$collected = [];

		if ( isset( $box['fields'] ) && is_array( $box['fields'] ) ) {
			$collected = $this->flatten_fields( $box['fields'], 'fields' );
		}

		if ( isset( $box['sections'] ) && is_array( $box['sections'] ) ) {
			foreach ( $box['sections'] as $index => $section ) {
				if ( ! is_array( $section ) || ! isset( $section['fields'] ) || ! is_array( $section['fields'] ) ) {
					continue;
				}

				$collected = array_merge(
					$collected,
					$this->flatten_fields( $section['fields'], sprintf( 'sections.%s.fields', (string) $index ) )
				);
			}
		}

		return $collected;
	}

	/**
	 * @param array<string|int, mixed> $fields
	 * @return array<string, array<string|int, mixed>>
	 */
	private function flatten_fields( array $fields, string $prefix ): array {
		$flat = [];

		foreach ( $fields as $key => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$path          = $prefix . '.' . (string) $key;
			$flat[ $path ] = $field;

			if ( isset( $field['fields'] ) && is_array( $field['fields'] ) ) {
				$flat = array_merge( $flat, $this->flatten_fields( $field['fields'], $path . '.fields' ) );
			}
		}

		return $flat;
	}

	/**
	 * `associations` only means something on a taxonomy.
	 *
	 * On a post type it is read by nothing, and the author probably meant to
	 * declare the relationship from the taxonomy side.
	 *
	 * @param array<string|int, mixed> $data
	 * @return list<ConfigError>
	 */
	private function check_associations( array $data, string $model_name ): array {
		if ( ! array_key_exists( 'associations', $data ) || $this->is_taxonomy( $data ) ) {
			return [];
		}

		return [
			ConfigError::warning(
				$model_name,
				'associations',
				'associations_on_post_type',
				'"associations" is only read on a taxonomy. Declare it on the taxonomy that should attach to this post type.',
				$data['associations']
			),
		];
	}

	/**
	 * The closest accepted value, when one is close enough to be worth naming.
	 *
	 * Beyond the distance threshold this returns null. Suggesting a distant key
	 * sends the author to the wrong place, which costs more than saying nothing.
	 *
	 * @param list<string> $candidates
	 */
	private function nearest( string $value, array $candidates ): ?string {
		if ( $value === '' || $candidates === [] ) {
			return null;
		}

		$best     = null;
		$shortest = PHP_INT_MAX;

		foreach ( $candidates as $candidate ) {
			$distance = levenshtein( strtolower( $value ), strtolower( $candidate ) );

			if ( $distance > 0 && $distance < $shortest ) {
				$shortest = $distance;
				$best     = $candidate;
			}
		}

		return $shortest <= self::SUGGESTION_MAX_DISTANCE ? $best : null;
	}

	/**
	 * Whether this config declares a taxonomy.
	 *
	 * @param array<string|int, mixed> $data
	 */
	private function is_taxonomy( array $data ): bool {
		$type = $data['type'] ?? null;

		return is_string( $type ) && in_array( $type, [ 'taxonomy', 'tax', 'category', 'cat', 'tag' ], true );
	}

	/**
	 * A name to report against when the caller supplied none.
	 *
	 * @param array<string|int, mixed> $data
	 */
	private function resolve_name( array $data ): string {
		$name = $data['name'] ?? null;

		return is_string( $name ) && $name !== '' ? $name : '(unnamed)';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function schema(): array {
		if ( $this->schema === null ) {
			$this->schema = $this->schema_builder->build();
		}

		return $this->schema;
	}
}
