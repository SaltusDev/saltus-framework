<?php

namespace Saltus\WP\Framework\Features\Relationships;

use Saltus\WP\Framework\Modeler;

/**
 * Builds relationship definitions from the `relationships` section of model config.
 *
 * Both sides of a relationship resolve the same storage key by sorting the two
 * endpoint names and taking the lower one, so a definition declared on one
 * model and a reciprocal synthesized on the other agree on where rows live
 * without depending on the order models happened to load in.
 *
 * @api
 */
final class RelationshipRegistry {

	/** Maximum length of the stored relationship key column. */
	private const KEY_LIMIT = 64;

	private Modeler $modeler;
	private bool $loaded = false;

	/** @var array<string, array<string, RelationshipDefinition>> */
	private array $definitions = [];

	public function __construct( Modeler $modeler ) {
		$this->modeler = $modeler;
	}

	/**
	 * Definitions for one model, keyed by relationship name.
	 *
	 * @return array<string, RelationshipDefinition>
	 */
	public function get_for_model( string $model ): array {
		$this->ensure_loaded();

		return $this->definitions[ $model ] ?? [];
	}

	/** Resolve one named relationship on a model. */
	public function get( string $model, string $name ): ?RelationshipDefinition {
		return $this->get_for_model( $model )[ $name ] ?? null;
	}

	/**
	 * Every registered definition, keyed by model then relationship name.
	 *
	 * @return array<string, array<string, RelationshipDefinition>>
	 */
	public function get_all(): array {
		$this->ensure_loaded();

		return $this->definitions;
	}

	/** Whether any model declares a relationship. */
	public function has_any(): bool {
		return $this->get_all() !== [];
	}

	/** Discard cached definitions so the next read parses model config again. */
	public function flush(): void {
		$this->definitions = [];
		$this->loaded      = false;
	}

	/**
	 * Parse every model once, then fill in the far side of each declaration.
	 *
	 * Reciprocals are synthesized in a second pass so an explicit declaration
	 * on the target model always wins over a generated one.
	 */
	private function ensure_loaded(): void {
		if ( $this->loaded ) {
			return;
		}

		$this->loaded = true;
		$declared     = [];

		foreach ( $this->modeler->get_models() as $name => $model ) {
			if ( $model->get_type() !== 'post_type' ) {
				continue;
			}

			foreach ( $this->parse_model( (string) $name, $model->get_config() ) as $definition ) {
				$this->definitions[ $definition->get_from() ][ $definition->get_name() ] = $definition;
				$declared[] = $definition;
			}
		}

		foreach ( $declared as $definition ) {
			$this->add_reciprocal( $definition );
		}
	}

	/**
	 * Build definitions from one model's `relationships` config section.
	 *
	 * @param string               $model  Post type slug declaring the relationships.
	 * @param array<string, mixed> $config Full raw model configuration.
	 * @return list<RelationshipDefinition>
	 */
	private function parse_model( string $model, array $config ): array {
		$section = $config['relationships'] ?? null;
		if ( ! is_array( $section ) ) {
			return [];
		}

		$definitions = [];
		foreach ( $section as $name => $settings ) {
			$definition = $this->build( $model, (string) $name, $settings );
			if ( $definition instanceof RelationshipDefinition ) {
				$definitions[] = $definition;
			}
		}

		return $definitions;
	}

	/**
	 * Validate and normalize a single relationship declaration.
	 *
	 * A declaration missing a target model, or naming a cardinality this
	 * framework does not implement, is skipped rather than guessed at: a
	 * silently wrong cardinality would let writes through that the schema is
	 * supposed to reject.
	 *
	 * @param string $model    Post type slug declaring the relationship.
	 * @param string $name     Relationship name as written in config.
	 * @param mixed  $settings Raw declaration body.
	 */
	private function build( string $model, string $name, $settings ): ?RelationshipDefinition {
		if ( ! is_array( $settings ) || $name === '' ) {
			return null;
		}

		$target = (string) ( $settings['model'] ?? '' );
		$type   = (string) ( $settings['type'] ?? RelationshipDefinition::HAS_MANY );
		if ( $target === '' || ! RelationshipDefinition::is_valid_type( $type ) ) {
			return null;
		}

		return new RelationshipDefinition(
			[
				'name'           => $name,
				'from'           => $model,
				'to'             => $target,
				'type'           => $type,
				'key'            => $this->storage_key( $model, $target, $name, $settings ),
				'inverse'        => false,
				'reciprocal'     => $settings['reciprocal'] ?? null,
				'cascade_delete' => $settings['cascade_delete'] ?? false,
				'capability'     => $settings['capability'] ?? null,
				'capabilities'   => $settings['capabilities'] ?? null,
				'pivot'          => $settings['meta'] ?? $settings['pivot'] ?? null,
			]
		);
	}

	/**
	 * Derive the shared storage key for a relationship.
	 *
	 * An explicit `key` is honoured as-is so existing data can be adopted.
	 * Otherwise the key is built from the relationship name plus both endpoint
	 * names in a stable order, making it identical from either side.
	 *
	 * @param array<string, mixed> $settings Raw declaration body.
	 */
	private function storage_key( string $model, string $target, string $name, array $settings ): string {
		$explicit = (string) ( $settings['key'] ?? '' );
		if ( $explicit !== '' ) {
			return substr( $this->slug( $explicit ), 0, self::KEY_LIMIT );
		}

		$reciprocal = (string) ( $settings['reciprocal'] ?? '' );
		$endpoints  = [ $model, $target ];
		sort( $endpoints );

		// Pair the relationship names too, so two different relationships
		// between the same models do not collide on one key.
		$names = $model === $endpoints[0] ? [ $name, $reciprocal ] : [ $reciprocal, $name ];
		$pair  = implode( '_', array_filter( $names ) );

		return substr( $this->slug( $endpoints[0] . '_' . $endpoints[1] . '_' . $pair ), 0, self::KEY_LIMIT );
	}

	/** Reduce a string to the characters allowed in a storage key. */
	private function slug( string $value ): string {
		$slug = strtolower( (string) preg_replace( '/[^A-Za-z0-9_]+/', '_', $value ) );

		return trim( $slug, '_' );
	}

	/**
	 * Register the far side of a declared relationship.
	 *
	 * Skipped when the target model already declares that name itself, so an
	 * explicit declaration is never overwritten by a generated one.
	 */
	private function add_reciprocal( RelationshipDefinition $definition ): void {
		$name = $definition->get_reciprocal();
		if ( $name === null || $name === '' ) {
			return;
		}

		$target = $definition->get_to();
		if ( isset( $this->definitions[ $target ][ $name ] ) ) {
			return;
		}

		$this->definitions[ $target ][ $name ] = new RelationshipDefinition(
			[
				'name'           => $name,
				'from'           => $target,
				'to'             => $definition->get_from(),
				'type'           => RelationshipDefinition::inverse_type( $definition->get_type() ),
				'key'            => $definition->get_key(),
				'inverse'        => true,
				'reciprocal'     => $definition->get_name(),
				// Cascade is a property of the declaring side only. Inheriting it
				// here would delete the declaring posts when a target is removed.
				'cascade_delete' => false,
				'capability'     => $definition->get_capability(),
				// Capabilities, unlike cascade, *are* inherited. Both sides write the
				// same row, so a rule stopping at the declaring side would be bypassed
				// by writing through the reciprocal — the far-end hole that
				// two-directional cardinality enforcement already closes. Cascade is
				// semantics and does not inherit; this is security and must.
				'capabilities'   => $definition->get_capabilities(),
				'pivot'          => $definition->get_pivot_fields(),
			]
		);
	}
}
