<?php

namespace Saltus\WP\Framework\Features\Meta;

use Saltus\WP\Framework\Modeler;

/**
 * Rejects tool calls that would query, sort, or filter on an encrypted field.
 *
 * `FieldEncryptionPolicy::reject_query_arguments()` answers "is this field name
 * queryable". This decides *which names a given tool call references*, which is
 * the part that has to know about tool argument shapes. Keeping the two separate
 * means the policy stays surface-agnostic and this stays the only place that
 * knows `orderby` is a sort and `meta_key` is a filter.
 *
 * Only argument keys that reach a database comparison are inspected. `search` is
 * excluded deliberately: WordPress searches post title and content, not meta, so
 * it cannot touch an encrypted value and rejecting it would be a false positive.
 *
 * @api
 */
final class FieldQueryGuard {

	/**
	 * Tool arguments whose value names a field used in a comparison or sort.
	 *
	 * `orderby` sorts, `meta_key` filters, `meta_compare` operates on whatever
	 * `meta_key` named. Each is a scalar field name in the argument payload.
	 */
	private const FIELD_NAME_ARGS = [
		'orderby',
		'meta_key',
		'order_by',
	];

	private FieldEncryptionPolicy $encryption;

	private ?Modeler $modeler;

	/** @var callable():Modeler|null */
	private $modeler_resolver;

	/**
	 * @param Modeler|callable|null       $modeler    Model registry or lazy resolver,
	 *                                                matching `AiContextProvider` so the
	 *                                                registry can be built after this is.
	 * @param FieldEncryptionPolicy|null  $encryption Shared encryption policy.
	 */
	public function __construct( $modeler = null, ?FieldEncryptionPolicy $encryption = null ) {
		$this->modeler          = $modeler instanceof Modeler ? $modeler : null;
		$this->modeler_resolver = is_callable( $modeler ) ? $modeler : null;
		$this->encryption       = $encryption ?? new FieldEncryptionPolicy();
	}

	/**
	 * Reject a tool call referencing an encrypted field, or return null.
	 *
	 * @param array<string, mixed> $args Tool arguments as submitted.
	 */
	public function check( array $args ): ?\WP_Error {
		$post_type = $this->post_type( $args );
		if ( $post_type === '' ) {
			return null;
		}

		$referenced = $this->referenced_fields( $args );
		if ( $referenced === [] ) {
			return null;
		}

		$modeler = $this->modeler();
		if ( $modeler === null ) {
			return null;
		}

		return $this->encryption->reject_query_arguments( $modeler, $post_type, $referenced );
	}

	/** Resolve the model registry, lazily on first use. */
	private function modeler(): ?Modeler {
		if ( $this->modeler instanceof Modeler ) {
			return $this->modeler;
		}

		if ( $this->modeler_resolver === null ) {
			return null;
		}

		$this->modeler = ( $this->modeler_resolver )();

		return $this->modeler;
	}

	/**
	 * Field names this call would compare or sort on.
	 *
	 * @param array<string, mixed> $args
	 * @return list<string>
	 */
	private function referenced_fields( array $args ): array {
		$names = [];

		foreach ( self::FIELD_NAME_ARGS as $key ) {
			$value = $args[ $key ] ?? null;
			if ( is_string( $value ) && $value !== '' ) {
				$names[] = $value;
			}
		}

		// A `meta_query` carries its field names one level down, in each clause's
		// `key`. Nested relations are walked because a clause can hold clauses.
		$meta_query = $args['meta_query'] ?? null;
		if ( is_array( $meta_query ) ) {
			$names = array_merge( $names, $this->meta_query_keys( $meta_query ) );
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Every `key` in a meta query, at any nesting depth.
	 *
	 * @param array<mixed> $clauses
	 * @return list<string>
	 */
	private function meta_query_keys( array $clauses ): array {
		$keys = [];

		foreach ( $clauses as $clause ) {
			if ( ! is_array( $clause ) ) {
				continue;
			}

			$key = $clause['key'] ?? null;
			if ( is_string( $key ) && $key !== '' ) {
				$keys[] = $key;
			}

			$keys = array_merge( $keys, $this->meta_query_keys( $clause ) );
		}

		return $keys;
	}

	/**
	 * The post type a call targets.
	 *
	 * @param array<string, mixed> $args
	 */
	private function post_type( array $args ): string {
		$post_type = $args['post_type'] ?? null;

		return is_string( $post_type ) ? $post_type : '';
	}
}
