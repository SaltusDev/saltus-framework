<?php

namespace Saltus\WP\Framework\Features\Relationships;

/**
 * Immutable description of one named relationship declared by a model.
 *
 * A relationship is stored exactly once, as a row owned by the declaring
 * side. The reciprocal definition shares the same storage key and flips the
 * query direction instead of writing a second row, so neither side can drift
 * out of sync with the other.
 *
 * @api
 */
final class RelationshipDefinition {

	public const HAS_ONE      = 'has_one';
	public const HAS_MANY     = 'has_many';
	public const BELONGS_TO   = 'belongs_to';
	public const MANY_TO_MANY = 'many_to_many';

	/** Cardinalities that accept more than one target for the same post. */
	private const MULTIPLE = [ self::HAS_MANY, self::BELONGS_TO, self::MANY_TO_MANY ];

	/** Reciprocal cardinality for each declared cardinality. */
	private const INVERSE_TYPES = [
		self::HAS_ONE      => self::BELONGS_TO,
		self::HAS_MANY     => self::BELONGS_TO,
		self::BELONGS_TO   => self::HAS_MANY,
		self::MANY_TO_MANY => self::MANY_TO_MANY,
	];

	private string $name;
	private string $from;
	private string $to;
	private string $type;
	private string $key;
	private bool $inverse;
	private ?string $reciprocal;
	private bool $cascade_delete;
	private ?string $capability;

	/**
	 * Per-operation capability rules, keyed by `read`/`write`.
	 *
	 * Absent means unrestricted. An operation present with a non-empty list means
	 * the caller needs one of those capabilities. Distinct from `$capability`,
	 * which is an admin-UI affordance no programmatic surface reads.
	 *
	 * @var array<string, list<string>>
	 */
	private array $capabilities;

	/** @var array<string, array<string, mixed>> */
	private array $pivot;

	/**
	 * @param array<string, mixed> $attributes Normalized definition attributes.
	 */
	public function __construct( array $attributes ) {
		$this->name           = (string) ( $attributes['name'] ?? '' );
		$this->from           = (string) ( $attributes['from'] ?? '' );
		$this->to             = (string) ( $attributes['to'] ?? '' );
		$this->type           = (string) ( $attributes['type'] ?? self::HAS_MANY );
		$this->key            = (string) ( $attributes['key'] ?? '' );
		$this->inverse        = (bool) ( $attributes['inverse'] ?? false );
		$this->cascade_delete = (bool) ( $attributes['cascade_delete'] ?? false );
		$this->pivot          = $this->normalize_pivot( $attributes['pivot'] ?? null );
		$this->reciprocal     = $this->optional_string( $attributes, 'reciprocal' );
		$this->capability     = $this->optional_string( $attributes, 'capability' );
		$this->capabilities   = $this->normalize_capabilities( $attributes['capabilities'] ?? null );
	}

	/**
	 * Keep only defined operations declaring at least one usable capability string.
	 *
	 * An unparseable rule is dropped rather than kept as an empty list. An empty
	 * list would satisfy nothing and deny everyone, turning a config typo into a
	 * lockout; dropping it preserves current access and leaves the complaint to
	 * `RelationshipConfigRules`, where the author can act on it.
	 *
	 * An operation the policy does not define is dropped for the same reason it is
	 * not kept: a stored `delete` rule reads as protection that is never consulted,
	 * so the author believes the relationship is gated when nothing gates it. Keeping
	 * only `read` and `write` makes what is stored exactly what is enforced.
	 *
	 * Accepts a bare string (`read: editor`) as well as a list, because a
	 * single-capability rule is the common case and requiring a one-item list for it
	 * is the kind of friction that gets the key spelled wrong.
	 *
	 * @param mixed $capabilities Raw `capabilities` declaration.
	 * @return array<string, list<string>>
	 */
	private function normalize_capabilities( $capabilities ): array {
		if ( ! is_array( $capabilities ) ) {
			return [];
		}

		$normalized = [];
		foreach ( $capabilities as $operation => $declared ) {
			if ( ! is_string( $operation ) || ! in_array( $operation, RelationshipPermissionPolicy::OPERATIONS, true ) ) {
				continue;
			}

			if ( is_string( $declared ) ) {
				$declared = [ $declared ];
			}

			if ( ! is_array( $declared ) ) {
				continue;
			}

			$list = [];
			foreach ( $declared as $capability ) {
				if ( is_string( $capability ) && $capability !== '' ) {
					$list[] = $capability;
				}
			}

			if ( $list !== [] ) {
				$normalized[ $operation ] = $list;
			}
		}

		return $normalized;
	}

	/**
	 * Read an attribute that is only meaningful when non-empty.
	 *
	 * @param array<string, mixed> $attributes Definition attributes.
	 * @param string               $key        Attribute name.
	 */
	private function optional_string( array $attributes, string $key ): ?string {
		$value = isset( $attributes[ $key ] ) ? (string) $attributes[ $key ] : '';

		return $value === '' ? null : $value;
	}

	/**
	 * Keep only pivot fields that declare a usable name.
	 *
	 * @param mixed $pivot Raw pivot field configuration.
	 * @return array<string, array<string, mixed>>
	 */
	private function normalize_pivot( $pivot ): array {
		if ( ! is_array( $pivot ) ) {
			return [];
		}

		$fields = [];
		foreach ( $pivot as $field => $settings ) {
			if ( ! is_string( $field ) || $field === '' ) {
				continue;
			}

			$fields[ $field ] = is_array( $settings ) ? $settings : [ 'type' => (string) $settings ];
		}

		return $fields;
	}

	/** Whether a cardinality string is one this framework understands. */
	public static function is_valid_type( string $type ): bool {
		return array_key_exists( $type, self::INVERSE_TYPES );
	}

	/** Resolve the reciprocal cardinality for a declared cardinality. */
	public static function inverse_type( string $type ): string {
		return self::INVERSE_TYPES[ $type ] ?? self::BELONGS_TO;
	}

	public function get_name(): string {
		return $this->name;
	}

	/** Model this side is queried from. */
	public function get_from(): string {
		return $this->from;
	}

	/** Model on the far end of the relationship. */
	public function get_to(): string {
		return $this->to;
	}

	public function get_type(): string {
		return $this->type;
	}

	/** Storage key shared by both sides of the relationship. */
	public function get_key(): string {
		return $this->key;
	}

	/** Whether this side reads rows in the reverse direction. */
	public function is_inverse(): bool {
		return $this->inverse;
	}

	public function get_reciprocal(): ?string {
		return $this->reciprocal;
	}

	public function cascades_delete(): bool {
		return $this->cascade_delete;
	}

	/**
	 * Capability recorded for admin-UI gating.
	 *
	 * Read only by `RelationshipMetabox`, `RelationshipColumn`, and
	 * `RelationshipBulkActions`. No programmatic surface enforces it — see
	 * `get_capabilities_for()` for the key that is a security boundary.
	 */
	public function get_capability(): ?string {
		return $this->capability;
	}

	/**
	 * Capabilities granting one operation, or null when unrestricted.
	 *
	 * @return list<string>|null
	 */
	public function get_capabilities_for( string $operation ): ?array {
		return $this->capabilities[ $operation ] ?? null;
	}

	/**
	 * Every declared per-operation rule, keyed by operation.
	 *
	 * @return array<string, list<string>>
	 */
	public function get_capabilities(): array {
		return $this->capabilities;
	}

	/** @return array<string, array<string, mixed>> */
	public function get_pivot_fields(): array {
		return $this->pivot;
	}

	/** Whether this side accepts more than one related post. */
	public function allows_multiple(): bool {
		return in_array( $this->type, self::MULTIPLE, true );
	}

	/** Column holding the post id for this side of the relationship. */
	public function own_column(): string {
		return $this->inverse ? 'to_post_id' : 'from_post_id';
	}

	/** Column holding the related post id for this side. */
	public function related_column(): string {
		return $this->inverse ? 'from_post_id' : 'to_post_id';
	}

	/**
	 * Public description used by REST discovery and MCP tool output.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'name'           => $this->name,
			'from'           => $this->from,
			'to'             => $this->to,
			'type'           => $this->type,
			'key'            => $this->key,
			'inverse'        => $this->inverse,
			'reciprocal'     => $this->reciprocal,
			'cascade_delete' => $this->cascade_delete,
			'multiple'       => $this->allows_multiple(),
			'pivot_fields'   => array_keys( $this->pivot ),
		];
	}
}
