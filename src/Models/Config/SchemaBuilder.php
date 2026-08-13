<?php

namespace Saltus\WP\Framework\Models\Config;

use Saltus\WP\Framework\Core;
use Saltus\WP\Framework\Features\Meta\CodestarMeta;
use Saltus\WP\Framework\Features\Meta\FieldPermissionPolicy;
use Saltus\WP\Framework\Features\Relationships\RelationshipDefinition;

/**
 * Describes the config structure a model may declare.
 *
 * Where the consuming code exposes its rule, this reads it rather than keeping a
 * copy: meta field types come from `CodestarMeta::match_fields()`, relationship
 * cardinalities from `RelationshipDefinition`'s constants, field permission
 * operations from `FieldPermissionPolicy`. Those cannot drift.
 *
 * The rest are mirrors, because the rule lives in a local variable or is applied
 * by coercion rather than declared — `ModelFactory`'s `$type_map` is the notable
 * one. Each mirror says so, and the type list is pinned by a behavioural test
 * (`ModelFactoryTypeAliasTest`) that fails if the two ever disagree. Descriptive
 * key lists for `features`, `webmcp`, and `ai_context` shape suggestions only and
 * are not enforced as enums.
 *
 * @internal
 */
final class SchemaBuilder {

	/**
	 * Build the schema for a single or multi-model config.
	 *
	 * @return array<string, mixed>
	 */
	public function build(): array {
		return [
			'type'                 => $this->type_schema(),
			'name'                 => $this->name_schema(),
			'active'               => $this->active_schema(),
			'labels'               => $this->labels_schema(),
			'meta'                 => $this->meta_schema(),
			'relationships'        => $this->relationships_schema(),
			'features'             => $this->features_schema(),
			'webmcp'               => $this->webmcp_schema(),
			'ai_context'           => $this->ai_context_schema(),
			'associations'         => $this->associations_schema(),
			'supported_post_types' => $this->supported_post_types_schema(),
			'known_top_level_keys' => $this->known_top_level_keys(),
		];
	}

	/**
	 * Type aliases accepted by ModelFactory::create().
	 *
	 * @return array{accepted: list<string>, description: string}
	 */
	private function type_schema(): array {
		// Mirrors the $type_map in ModelFactory::create(). That map is a local
		// variable inside the method, so it cannot be read from here — this list
		// must be updated alongside it. ModelFactoryTypeAliasTest asserts the two
		// agree, so a divergence fails rather than silently accepting a bad type.
		return [
			'accepted'    => [
				'post-type',
				'cpt',
				'posttype',
				'post_type',
				'taxonomy',
				'tax',
				'category',
				'cat',
				'tag',
			],
			'description' => 'Model type. Post type aliases resolve to "post", taxonomy aliases to "taxonomy".',
		];
	}

	/**
	 * Name length limits enforced by BaseModel::validate_name().
	 *
	 * @return array{post_type_max: int, taxonomy_max: int, description: string}
	 */
	private function name_schema(): array {
		// Mirrors the limits BaseModel enforces when sanitizing a name.
		return [
			'post_type_max' => 20,
			'taxonomy_max'  => 32,
			'description'   => 'Model name, subject to WordPress database column limits.',
		];
	}

	/**
	 * Active flag: only strict `true` enables the model.
	 *
	 * @return array{description: string, warning: string}
	 */
	private function active_schema(): array {
		// Mirrors BaseModel's strict-true check for the active flag.
		return [
			'description' => 'Whether to register this model. Defaults to true when absent.',
			'warning'     => 'Truthy non-true values (1, "yes") are treated as false and disable the model.',
		];
	}

	/**
	 * Labels structure, including the `overrides.ui` subset.
	 *
	 * @return array{description: string, ui_keys: list<string>}
	 */
	private function labels_schema(): array {
		return [
			'description' => 'Display strings for the post type or taxonomy.',
			'ui_keys'     => [
				'enter_title_here',
				// Only this one key is actually read; others are inert.
			],
		];
	}

	/**
	 * Meta field structure derived from MetaFieldProvider.
	 *
	 * @return array{description: string, box_structure: array<string, mixed>, field_types: list<string>}
	 */
	private function meta_schema(): array {
		// Read from the same map CodestarMeta uses to resolve field types, so a new
		// or renamed type cannot leave the validator behind. The map passes through
		// `saltus/framework/meta/matched_fields`, so a site's custom types are
		// included — which is also why an unrecognized type warns, never errors.
		$field_types = array_keys( ( new CodestarMeta( '', [] ) )->match_fields() );

		return [
			'description'   => 'Meta boxes and fields. Each box key must have "fields" or "sections", not both.',
			'box_structure' => [
				'data_type'         => [
					'description' => 'Serialization strategy: "serialize" stores all fields under one key; "unserialize" stores each separately.',
					'accepted'    => [ 'serialize', 'unserialize' ],
				],
				'register_rest_api' => [
					'description' => 'Expose to REST. Must be strict true; truthy non-true values are treated as false.',
					'type'        => 'bool',
				],
				'fields'            => [
					'description' => 'Field list. Mutually exclusive with "sections".',
				],
				'sections'          => [
					'description' => 'Grouped fields. Mutually exclusive with "fields". Each section must have its own "fields".',
				],
			],
			'field_types'   => $field_types,
		];
	}

	/**
	 * Relationship cardinalities and constraints.
	 *
	 * @return array{cardinalities: list<string>, description: string}
	 */
	private function relationships_schema(): array {
		// Read from RelationshipDefinition's own constants.
		return [
			'cardinalities' => [
				RelationshipDefinition::HAS_ONE,
				RelationshipDefinition::HAS_MANY,
				RelationshipDefinition::BELONGS_TO,
				RelationshipDefinition::MANY_TO_MANY,
			],
			'description'   => 'Named relationships between models. Each relationship requires "type", "model", and optionally "reciprocal".',
		];
	}

	/**
	 * Feature sections: frontend, blocks, drag_and_drop, etc.
	 *
	 * @return array{sections: array<string, mixed>}
	 */
	private function features_schema(): array {
		// Describes what SaltusFrontend and SaltusBlocks accept. Their
		// normalize_config() methods coerce shapes rather than declaring allowed
		// keys, so there is no list to read; these are documentation for the
		// validator's suggestions, not enforced enums.
		return [
			'sections'    => [
				'frontend'      => [
					'description' => 'Public frontend rendering. Accepts "template" or "callback".',
					'keys'        => [ 'template', 'callback' ],
				],
				'blocks'        => [
					'description' => 'Gutenberg block registration. Accepts "variations" or "callback".',
					'keys'        => [ 'variations', 'callback' ],
				],
				'drag_and_drop' => [
					'description' => 'Post-list reordering capability declaration. Only read by permission policies; no runtime handler.',
					'keys'        => [],
				],
				'draganddrop'   => [
					'description' => 'Admin UI for drag-and-drop reordering. Separate from "drag_and_drop" — they are not aliases.',
					'keys'        => [],
				],
			],
			'description' => 'Feature opt-ins. Unknown feature names are silently ignored.',
		];
	}

	/**
	 * WebMCP policy structure.
	 *
	 * @return array{description: string, keys: list<string>}
	 */
	private function webmcp_schema(): array {
		// Describes what WebMcpPolicy::normalize_config() coerces. Not an enforced
		// enum — it shapes suggestions only.
		return [
			'description' => 'Browser-driven MCP proposals. Accepts "enabled", "policy", "tools", "editorial_review".',
			'keys'        => [ 'enabled', 'policy', 'tools', 'editorial_review' ],
		];
	}

	/**
	 * AI context generation config.
	 *
	 * @return array{description: string, keys: list<string>}
	 */
	private function ai_context_schema(): array {
		// Describes what AiContextProvider's coercion helpers accept. Suggestions
		// only, not an enforced enum.
		return [
			'description' => 'AI context generation via /wp-json/saltus/v1/ai-context/. Accepts "enabled", "policy", "fields".',
			'keys'        => [ 'enabled', 'policy', 'fields' ],
		];
	}

	/**
	 * Taxonomy associations (taxonomy-only key).
	 *
	 * @return array{description: string, taxonomy_only: bool}
	 */
	private function associations_schema(): array {
		return [
			'description'   => 'Post types this taxonomy applies to. Taxonomy-only; raises an error on a post type.',
			'taxonomy_only' => true,
		];
	}

	/**
	 * Post types a taxonomy should attach to (alternate form).
	 *
	 * @return array{description: string}
	 */
	private function supported_post_types_schema(): array {
		return [
			'description' => 'Post types this taxonomy applies to. Same effect as "associations".',
		];
	}

	/**
	 * Every top-level key a model config may declare.
	 *
	 * Three sources, combined rather than transcribed:
	 *
	 * - Every service id in `Core::get_service_classes()`. A service id *is* a
	 *   valid top-level key — `ModelFactory` dispatches `frontend`, `meta`, and
	 *   `settings` from it directly, and the rest are read by the features
	 *   themselves. Deriving means registering a new feature cannot leave this
	 *   list behind.
	 * - Keys the model classes read directly.
	 * - `register_post_type()` / `register_taxonomy()` arguments, which pass
	 *   through to WordPress.
	 *
	 * The first draft of this list was hand-written and omitted `options`,
	 * `settings`, `frontend`, `blocks`, and `admin_cols` — all real, all actively
	 * read — so every normal config emitted spurious "nothing reads this key"
	 * warnings. Under WP-CLI those notices print to stdout and corrupt
	 * `--format=json` output, which is how a cosmetic mistake became a broken CLI.
	 *
	 * @return list<string>
	 */
	private function known_top_level_keys(): array {
		$service_keys = array_keys( Core::get_service_classes() );

		// Read directly by BaseModel / PostType / Taxonomy rather than dispatched.
		$model_keys = [
			'type',
			'name',
			'active',
			'options',
			'supports',
			'associations',
			'supported_post_types',
			'block_editor',
			'labels',
			'slug',
			'description',
			'features',
		];

		// Deliberately no list of register_post_type() arguments here. They are not
		// top-level keys: BaseModel::set_options() reads them from `options` only, so
		// a top-level `public:` or `has_archive:` really is inert and should warn.
		// `taxonomies` is the case worth naming — it looks like a WordPress argument
		// and is not read anywhere; a taxonomy declares its own post types via
		// `associations`.

		$all = array_merge( $service_keys, $model_keys );
		sort( $all );

		return array_values( array_unique( $all ) );
	}

	/**
	 * Field permission operations recognized by FieldPermissionPolicy.
	 *
	 * @return list<string>
	 */
	public function field_permission_operations(): array {
		return [
			FieldPermissionPolicy::OPERATION_READ,
			FieldPermissionPolicy::OPERATION_WRITE,
		];
	}
}
