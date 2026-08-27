<?php

namespace Saltus\WP\Framework\Features\Relationships;

use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\ConfigError;

/**
 * Builds relationship definitions from the `relationships` section of model config.
 *
 * Both sides of a relationship resolve the same storage key by sorting the two
 * endpoint names and taking the lower one, so a definition declared on one
 * model and a reciprocal synthesized on the other agree on where rows live
 * without depending on the order models happened to load in.
 *
 * A relationship may be declared four ways, and all four are supported:
 *
 * 1. One side only. A single one-way relationship.
 * 2. The other side only. The mirror of 1, and a distinct relationship.
 * 3. One side naming `reciprocal`. The far side is synthesized as the inverse of
 *    this one, sharing its row.
 * 4. Both sides declared. Two independent one-way relationships when neither names
 *    `reciprocal` — a legitimate declaration with two keys and two row sets.
 *
 * The trap is 4 combined with `reciprocal` on both sides. Those two derive the
 * *same* key, because the key is built from the sorted endpoint pair, but each is
 * declared as a forward side, so neither flips the query direction. They then write
 * one keyspace while both reading `from_post_id`: an attach through one side is
 * invisible to the other, a detach through the far side silently does nothing, and
 * one pair can occupy two rows. `reconcile_mutual_declarations()` resolves that into
 * the shape the author asked for, keeping the first declaration as the owning side.
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

	/**
	 * Cross-model declaration problems found while loading.
	 *
	 * Collected rather than reported, because `RelationshipConfigRules` validates one
	 * model at a time and cannot see the far side of a relationship. These are the
	 * problems only visible with every model in hand.
	 *
	 * @var list<ConfigError>
	 */
	private array $warnings = [];

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

	/**
	 * Cross-model declaration problems found while loading.
	 *
	 * Every one is a warning: an existing site sitting on a confused declaration must
	 * keep working after an upgrade, so these describe rather than block.
	 *
	 * @return list<ConfigError>
	 */
	public function get_warnings(): array {
		$this->ensure_loaded();

		return $this->warnings;
	}

	/** Discard cached definitions so the next read parses model config again. */
	public function flush(): void {
		$this->definitions = [];
		$this->warnings    = [];
		$this->loaded      = false;
	}

	/**
	 * Parse every model once, reconcile mutual declarations, then fill in the far
	 * side of each remaining declaration.
	 *
	 * Three passes rather than two. Reconciliation has to run after every model is
	 * parsed, because a mutual pair is only visible with both sides in hand, and
	 * before synthesis, because reconciliation decides which side is the inverse and
	 * synthesis would otherwise skip the pair entirely.
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

		$declared = $this->reconcile_mutual_declarations( $declared );

		foreach ( $declared as $definition ) {
			$this->add_reciprocal( $definition );
		}
	}

	/**
	 * Resolve pairs where both sides are declared and both name the other.
	 *
	 * Two such declarations derive one storage key but are both forward sides, so
	 * left alone they share a keyspace without sharing a row. The first declaration
	 * wins: it keeps its orientation and owns the row, and the second is rebuilt as
	 * its inverse, which is what naming `reciprocal` on both sides asks for.
	 *
	 * "First" is the lower-sorting endpoint model, not whichever model happened to
	 * load first. Row orientation decides how stored rows are read, so tying it to
	 * load order would let a new plugin or a changed hook priority silently reverse
	 * every existing row. `storage_key()` already sorts the endpoint pair for exactly
	 * this reason. Within one model - a self-referential pair - the endpoints are
	 * equal and config order is the tiebreaker, which is stable in the file.
	 *
	 * The loser keeps its own name, cardinality, cascade rule and UI capability.
	 * `capabilities` is the exception: it gates the row, one row now has one gate, and a
	 * second gate set cannot be honoured. The row owner's rule applies when it declared
	 * one, otherwise the far side's does - a declared rule is never dropped, because the
	 * side left without one would read around it. Both sides then carry the surviving
	 * rule, and a declaration that lost is reported.
	 *
	 * @param list<RelationshipDefinition> $declared Every explicitly declared definition.
	 * @return list<RelationshipDefinition> Definitions still needing a synthesized far side.
	 */
	private function reconcile_mutual_declarations( array $declared ): array {
		$remaining = [];

		/** @var list<string> Addresses of sides that kept their forward orientation. */
		$owners = [];

		foreach ( $declared as $definition ) {
			$far = $this->mutual_counterpart( $definition );

			if ( ! $far instanceof RelationshipDefinition ) {
				$remaining[] = $definition;
				continue;
			}

			if ( ! $this->owns_row( $definition, $far ) ) {
				$this->definitions[ $definition->get_from() ][ $definition->get_name() ] =
					$this->as_inverse_of( $far, $definition );

				// The rule that survived gates the shared row, so the owning side has to
				// answer with it too. `RelationshipPermissionPolicy` resolves per
				// definition, so a side still carrying no rule is a read path around it.
				$capabilities = $this->definitions[ $definition->get_from() ][ $definition->get_name() ]
					->get_capabilities();

				if ( $capabilities !== $far->get_capabilities() ) {
					$this->definitions[ $far->get_from() ][ $far->get_name() ] =
						$far->with_capabilities( $capabilities );
				}

				continue;
			}

			$owners[] = $definition->get_from() . "\0" . $definition->get_name();
		}

		// Re-read the owning sides, since a reconciled pair may have replaced one after it
		// was already visited.
		foreach ( $owners as $address ) {
			[ $model, $name ] = explode( "\0", $address, 2 );

			$current = $this->definitions[ $model ][ $name ] ?? null;

			if ( $current instanceof RelationshipDefinition ) {
				$remaining[] = $current;
			}
		}

		return $remaining;
	}

	/**
	 * The far side of a mutual declaration, when there is one.
	 *
	 * Mutual means both sides are explicitly declared, each names the other as its
	 * `reciprocal`, and the two derived the same storage key. The key test is what
	 * distinguishes a genuine collision from two independent relationships that merely
	 * point at each other's models.
	 */
	private function mutual_counterpart( RelationshipDefinition $definition ): ?RelationshipDefinition {
		$name = $definition->get_reciprocal();
		if ( $name === null || $name === '' || $definition->is_inverse() ) {
			return null;
		}

		$far = $this->definitions[ $definition->get_to() ][ $name ] ?? null;
		if ( ! $far instanceof RelationshipDefinition || $far->is_inverse() ) {
			return null;
		}

		if ( $far->get_reciprocal() !== $definition->get_name() ) {
			// The far side does not name this one back, so nothing was promised about
			// sharing a row. Reported separately by `add_reciprocal()`.
			return null;
		}

		if ( $far->get_key() === $definition->get_key() ) {
			return $far;
		}

		// A self-referential pair cannot agree on a derived key: both endpoints are the
		// same model, so the endpoint sort cannot order the name pair and each side orders
		// it its own way. The two are still one relationship - each names the other - so
		// the mismatch is an artifact of derivation, not a statement about storage. Only
		// derived keys qualify; an explicit `key` on either side is the author placing the
		// row deliberately and is left alone.
		if ( $definition->get_from() !== $definition->get_to() ) {
			return null;
		}

		$own_derived = $this->derived_key(
			$definition->get_from(),
			$definition->get_to(),
			$definition->get_name(),
			$name
		);
		$far_derived = $this->derived_key(
			$far->get_from(),
			$far->get_to(),
			$far->get_name(),
			$definition->get_name()
		);

		if ( $definition->get_key() !== $own_derived || $far->get_key() !== $far_derived ) {
			return null;
		}

		return $far;
	}

	/**
	 * Which side of a mutual pair keeps its forward orientation.
	 *
	 * Decided by the endpoint pair the key was already built from, so the answer does
	 * not move when models load in a different order. Equal endpoints mean a
	 * self-referential pair, where config order decides and the definition already
	 * present in the map was written first.
	 */
	private function owns_row( RelationshipDefinition $definition, RelationshipDefinition $far ): bool {
		$own = $definition->get_from();
		$to  = $definition->get_to();

		if ( $own !== $to ) {
			return strcmp( $own, $to ) <= 0;
		}

		foreach ( $this->definitions[ $own ] ?? [] as $name => $_ ) {
			if ( $name === $definition->get_name() ) {
				return true;
			}

			if ( $name === $far->get_name() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Rebuild a declaration as the inverse side of the definition owning its row.
	 *
	 * Keeps everything that is the loser's own business - its name, its endpoints, its
	 * cascade rule, its UI-only `capability` - and takes from the winner only what one
	 * shared row can have exactly one of.
	 */
	private function as_inverse_of(
		RelationshipDefinition $winner,
		RelationshipDefinition $loser
	): RelationshipDefinition {
		$expected = RelationshipDefinition::inverse_type( $winner->get_type() );
		$type     = $loser->get_type();

		if ( $type !== $expected ) {
			$this->warn(
				$loser,
				'type',
				sprintf(
					'"%1$s" on "%2$s" and "%3$s" on "%4$s" name each other as reciprocals, so they share one stored row, but "%5$s" is not the inverse of "%6$s". The inverse "%7$s" applies instead.',
					$loser->get_name(),
					$loser->get_from(),
					$winner->get_name(),
					$winner->get_from(),
					$type,
					$winner->get_type(),
					$expected
				),
				$type,
				[ $expected ]
			);

			$type = $expected;
		}

		// One row, one gate. The owner's rule applies when it declared one; otherwise the
		// far side's does, because discarding a declared rule would leave the shared row
		// ungated and reachable through the side that never restricted it - the same
		// bypass `add_reciprocal()` inherits capabilities to close.
		$capabilities = $winner->get_capabilities() !== [] ? $winner->get_capabilities() : $loser->get_capabilities();

		if ( $loser->get_capabilities() !== [] && $loser->get_capabilities() !== $capabilities ) {
			$this->warn(
				$loser,
				'capabilities',
				sprintf(
					'"%1$s" on "%2$s" and "%3$s" on "%4$s" name each other as reciprocals, so they share one stored row and one access rule. The "capabilities" declared on "%3$s" applies to both; the one declared here is not used. Declare the rule on one side only.',
					$loser->get_name(),
					$loser->get_from(),
					$winner->get_name(),
					$winner->get_from()
				),
				$loser->get_capabilities()
			);
		}

		$this->warn(
			$loser,
			'reciprocal',
			sprintf(
				'"%1$s" on "%2$s" and "%3$s" on "%4$s" both declare the other as "reciprocal". They describe one relationship, so "%3$s" owns the stored row and "%1$s" reads it in reverse. Declaring "reciprocal" on one side only, and letting the other be generated, states this directly.',
				$loser->get_name(),
				$loser->get_from(),
				$winner->get_name(),
				$winner->get_from()
			)
		);

		return new RelationshipDefinition(
			[
				'name'           => $loser->get_name(),
				'from'           => $loser->get_from(),
				'to'             => $loser->get_to(),
				'type'           => $type,
				'key'            => $winner->get_key(),
				'inverse'        => true,
				'reciprocal'     => $winner->get_name(),
				'cascade_delete' => $loser->cascades_delete(),
				'capability'     => $loser->get_capability(),
				'capabilities'   => $capabilities,
				'pivot'          => $loser->get_pivot_fields(),
			]
		);
	}

	/**
	 * Record one cross-model declaration problem.
	 *
	 * @param mixed        $found
	 * @param list<string> $accepted
	 */
	private function warn(
		RelationshipDefinition $definition,
		string $key,
		string $message,
		$found = null,
		array $accepted = []
	): void {
		$this->warnings[] = ConfigError::warning(
			$definition->get_from(),
			'relationships.' . $definition->get_name() . '.' . $key,
			'relationships_reciprocal_reconciled',
			$message,
			$found,
			$accepted
		);
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

		return $this->derived_key( $model, $target, $name, (string) ( $settings['reciprocal'] ?? '' ) );
	}

	/**
	 * The key a declaration derives when it does not name one explicitly.
	 *
	 * Split out so a derived key can be recomputed later and compared against the one a
	 * definition carries, which is how `mutual_counterpart()` tells a derived key from an
	 * author-supplied one without storing that distinction on every definition.
	 */
	private function derived_key( string $model, string $target, string $name, string $reciprocal ): string {
		$endpoints = [ $model, $target ];
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
	 * explicit declaration is never overwritten by a generated one — but not
	 * silently. Reaching here with the name taken means `reciprocal` asked for a
	 * shared row and did not get one: the two sides derived different keys and are
	 * two independent relationships. That is a legitimate thing to want, and the way
	 * to say it is to omit `reciprocal`, so a declaration that asked for the opposite
	 * is reported.
	 *
	 * Mutual declarations never reach this state: `reconcile_mutual_declarations()`
	 * has already rebuilt one side as the inverse and dropped it from this pass.
	 */
	private function add_reciprocal( RelationshipDefinition $definition ): void {
		$name = $definition->get_reciprocal();
		if ( $name === null || $name === '' ) {
			return;
		}

		$target   = $definition->get_to();
		$existing = $this->definitions[ $target ][ $name ] ?? null;

		if ( $existing instanceof RelationshipDefinition ) {
			// A reconciled pair already shares this row: the far side was rebuilt as the
			// inverse and carries this key. Nothing was dropped, so nothing to report.
			if ( ! ( $existing->is_inverse() && $existing->get_key() === $definition->get_key() ) ) {
				$this->warn(
					$definition,
					'reciprocal',
					sprintf(
						'"%1$s" on "%2$s" declares "%3$s" as its reciprocal, but "%4$s" already declares "%3$s" itself and the two derived different storage keys. They are independent one-way relationships: a row written through one is not visible from the other. Remove "reciprocal" to state that, or remove the "%3$s" declaration on "%4$s" to let this one generate it.',
						$definition->get_name(),
						$definition->get_from(),
						$name,
						$target
					)
				);
			}

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
