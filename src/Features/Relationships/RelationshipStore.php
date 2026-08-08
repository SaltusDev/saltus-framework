<?php

namespace Saltus\WP\Framework\Features\Relationships;

use Saltus\WP\Framework\MCP\Audit\AuditDatabase;
use Saltus\WP\Framework\MCP\Audit\WpdbAuditDatabase;

/**
 * Persists relationship rows in a dedicated table, or in process when absent.
 *
 * Rows live in their own table rather than post meta because every read this
 * feature performs is a join across two posts: meta storage would need one
 * query per post to answer "what is related to these fifty posts", which is
 * the N+1 pattern the eager-loading reads here exist to avoid.
 *
 * @api
 */
final class RelationshipStore {

	use PostIdListTrait;

	/** @var list<array<string, mixed>> */
	private array $memory = [];

	private int $next_id      = 1;
	private bool $initialized = false;
	private ?AuditDatabase $database;

	/** @param AuditDatabase|null $database Optional database adapter. */
	public function __construct( ?AuditDatabase $database = null ) {
		global $wpdb;
		$this->database = $database ?? ( $wpdb instanceof \wpdb ? new WpdbAuditDatabase( $wpdb ) : null );
	}

	/** Whether rows are persisted to a database rather than held in process. */
	public function is_persistent(): bool {
		return $this->database instanceof AuditDatabase;
	}

	/**
	 * Insert a relationship row, or update the existing one for the same pair.
	 *
	 * The pair (key, from, to) is unique, so re-attaching an existing pair
	 * updates its pivot payload and ordering instead of creating a duplicate.
	 *
	 * @param array<string, mixed> $row Row values.
	 * @return int Row id, or 0 when the write failed.
	 */
	public function upsert( array $row ): int {
		$values = $this->encode_values( $row );

		$existing = $this->find( (string) $values['relationship_key'], (int) $values['from_post_id'], (int) $values['to_post_id'] );
		if ( is_array( $existing ) ) {
			$id      = (int) ( $existing['id'] ?? 0 );
			$changes = [ 'order_index' => $values['order_index'] ];

			// Only rewrite the pivot payload when the caller supplied one.
			// Reordering a set must not silently discard stored pivot values.
			if ( array_key_exists( 'pivot_data', $row ) ) {
				$changes['pivot_data'] = $row['pivot_data'];
			}

			$this->update( $id, $changes );

			return $id;
		}

		if ( $this->database instanceof AuditDatabase ) {
			$this->ensure_table();
			$this->database->insert( $this->table_name(), $values, [ '%s', '%d', '%d', '%s', '%d', '%s', '%s' ] );
			$rows = $this->database->get_results( 'SELECT LAST_INSERT_ID() AS id', $this->output_format() );
			$last = is_array( $rows ) && is_array( $rows[0] ?? null ) ? $rows[0] : [];

			return (int) ( $last['id'] ?? 0 );
		}

		$values['id']   = $this->next_id++;
		$this->memory[] = $values;

		return (int) $values['id'];
	}

	/**
	 * Find one row by its unique pair.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( string $key, int $from_post_id, int $to_post_id ): ?array {
		if ( $this->database instanceof AuditDatabase ) {
			$this->ensure_table();
			$sql  = $this->database->prepare(
				'SELECT * FROM ' . $this->table_name() . ' WHERE relationship_key = %s AND from_post_id = %d AND to_post_id = %d LIMIT 1',
				$key,
				$from_post_id,
				$to_post_id
			);
			$rows = $this->database->get_results( $sql, $this->output_format() );
			$row  = is_array( $rows ) && is_array( $rows[0] ?? null ) ? $rows[0] : null;

			return is_array( $row ) ? $this->decode_values( $row ) : null;
		}

		foreach ( $this->memory as $row ) {
			if ( (string) $row['relationship_key'] === $key
				&& (int) $row['from_post_id'] === $from_post_id
				&& (int) $row['to_post_id'] === $to_post_id ) {
				return $this->decode_values( $row );
			}
		}

		return null;
	}

	/**
	 * Rows for one relationship key where the given column matches any post id.
	 *
	 * Taking a list of post ids is what makes eager loading possible: one query
	 * answers the relationship for a whole result set.
	 *
	 * @param list<int> $post_ids Post ids to match.
	 * @return list<array<string, mixed>>
	 */
	public function get_by_posts( string $key, string $column, array $post_ids ): array {
		$post_ids = self::post_id_list( $post_ids );
		if ( $post_ids === [] || ! $this->is_column( $column ) ) {
			return [];
		}

		if ( $this->database instanceof AuditDatabase ) {
			$this->ensure_table();
			$placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );
			$sql          = $this->database->prepare(
				'SELECT * FROM ' . $this->table_name() . ' WHERE relationship_key = %s AND ' . $column . ' IN (' . $placeholders . ') ORDER BY order_index ASC, id ASC',
				$key,
				...$post_ids
			);
			$rows         = $this->database->get_results( $sql, $this->output_format() );

			return is_array( $rows ) ? array_values( array_map( [ $this, 'decode_values' ], array_filter( $rows, 'is_array' ) ) ) : [];
		}

		$matched = array_filter(
			$this->memory,
			static function ( array $row ) use ( $key, $column, $post_ids ): bool {
				return (string) $row['relationship_key'] === $key && in_array( (int) $row[ $column ], $post_ids, true );
			}
		);

		return $this->sort_rows( array_map( [ $this, 'decode_values' ], array_values( $matched ) ) );
	}

	/**
	 * Every row referencing a post on either side.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function get_all_for_post( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return [];
		}

		if ( $this->database instanceof AuditDatabase ) {
			$this->ensure_table();
			$sql  = $this->database->prepare(
				'SELECT * FROM ' . $this->table_name() . ' WHERE from_post_id = %d OR to_post_id = %d ORDER BY order_index ASC, id ASC',
				$post_id,
				$post_id
			);
			$rows = $this->database->get_results( $sql, $this->output_format() );

			return is_array( $rows ) ? array_values( array_map( [ $this, 'decode_values' ], array_filter( $rows, 'is_array' ) ) ) : [];
		}

		$matched = array_filter(
			$this->memory,
			static function ( array $row ) use ( $post_id ): bool {
				return (int) $row['from_post_id'] === $post_id || (int) $row['to_post_id'] === $post_id;
			}
		);

		return $this->sort_rows( array_map( [ $this, 'decode_values' ], array_values( $matched ) ) );
	}

	/**
	 * Update mutable columns on one row.
	 *
	 * @param array<string, mixed> $changes Column values to write.
	 */
	public function update( int $id, array $changes ): bool {
		$sets = [];
		$args = [];
		foreach ( [ 'pivot_data', 'order_index' ] as $column ) {
			if ( ! array_key_exists( $column, $changes ) ) {
				continue;
			}

			$numeric         = $column === 'order_index';
			$sets[]          = $column . ' = ' . ( $numeric ? '%d' : '%s' );
			$args[ $column ] = $numeric ? (int) $changes[ $column ] : $this->encode( $this->to_array( $changes[ $column ] ) );
		}

		if ( $sets === [] ) {
			return false;
		}

		if ( $this->database instanceof AuditDatabase ) {
			$this->ensure_table();
			$values   = array_values( $args );
			$values[] = gmdate( 'Y-m-d H:i:s' );
			$values[] = $id;

			return $this->database->query(
				$this->database->prepare(
					'UPDATE ' . $this->table_name() . ' SET ' . implode( ', ', $sets ) . ', updated_at = %s WHERE id = %d',
					...$values
				)
			) !== false;
		}

		foreach ( $this->memory as $index => $row ) {
			if ( (int) ( $row['id'] ?? 0 ) !== $id ) {
				continue;
			}

			$this->memory[ $index ]               = array_merge( $row, $args );
			$this->memory[ $index ]['updated_at'] = gmdate( 'Y-m-d H:i:s' );

			return true;
		}

		return false;
	}

	/** Delete one specific pair. Returns the number of rows removed. */
	public function delete( string $key, int $from_post_id, int $to_post_id ): int {
		if ( $this->database instanceof AuditDatabase ) {
			$this->ensure_table();
			$result = $this->database->query(
				$this->database->prepare(
					'DELETE FROM ' . $this->table_name() . ' WHERE relationship_key = %s AND from_post_id = %d AND to_post_id = %d',
					$key,
					$from_post_id,
					$to_post_id
				)
			);

			return is_int( $result ) ? $result : 0;
		}

		return $this->forget(
			static function ( array $row ) use ( $key, $from_post_id, $to_post_id ): bool {
				return (string) $row['relationship_key'] === $key
					&& (int) $row['from_post_id'] === $from_post_id
					&& (int) $row['to_post_id'] === $to_post_id;
			}
		);
	}

	/**
	 * Delete every row for one relationship key anchored on one post.
	 *
	 * @param list<int> $except Related post ids to keep.
	 */
	public function delete_for_post( string $key, string $column, int $post_id, array $except = [] ): int {
		if ( $post_id <= 0 || ! $this->is_column( $column ) ) {
			return 0;
		}

		$other  = $column === 'from_post_id' ? 'to_post_id' : 'from_post_id';
		$except = self::post_id_list( $except );

		if ( $this->database instanceof AuditDatabase ) {
			$this->ensure_table();
			$sql  = 'DELETE FROM ' . $this->table_name() . ' WHERE relationship_key = %s AND ' . $column . ' = %d';
			$args = [ $key, $post_id ];
			if ( $except !== [] ) {
				$sql .= ' AND ' . $other . ' NOT IN (' . implode( ', ', array_fill( 0, count( $except ), '%d' ) ) . ')';
				$args = array_merge( $args, $except );
			}

			$result = $this->database->query( $this->database->prepare( $sql, ...$args ) );

			return is_int( $result ) ? $result : 0;
		}

		return $this->forget(
			static function ( array $row ) use ( $key, $column, $other, $post_id, $except ): bool {
				return (string) $row['relationship_key'] === $key
					&& (int) $row[ $column ] === $post_id
					&& ! in_array( (int) $row[ $other ], $except, true );
			}
		);
	}

	/** Delete every row referencing a post on either side. */
	public function delete_all_for_post( int $post_id ): int {
		if ( $post_id <= 0 ) {
			return 0;
		}

		if ( $this->database instanceof AuditDatabase ) {
			$this->ensure_table();
			$result = $this->database->query(
				$this->database->prepare(
					'DELETE FROM ' . $this->table_name() . ' WHERE from_post_id = %d OR to_post_id = %d',
					$post_id,
					$post_id
				)
			);

			return is_int( $result ) ? $result : 0;
		}

		return $this->forget(
			static function ( array $row ) use ( $post_id ): bool {
				return (int) $row['from_post_id'] === $post_id || (int) $row['to_post_id'] === $post_id;
			}
		);
	}

	/**
	 * Drop in-process rows matching a predicate.
	 *
	 * @param callable(array<string, mixed>): bool $matches Predicate.
	 * @return int Number of rows removed.
	 */
	private function forget( callable $matches ): int {
		$kept    = [];
		$removed = 0;
		foreach ( $this->memory as $row ) {
			if ( $matches( $row ) ) {
				++$removed;
				continue;
			}

			$kept[] = $row;
		}

		$this->memory = $kept;

		return $removed;
	}

	/**
	 * Order rows the way the database does, so both backends agree.
	 *
	 * @param list<array<string, mixed>> $rows Rows to sort.
	 * @return list<array<string, mixed>>
	 */
	private function sort_rows( array $rows ): array {
		usort(
			$rows,
			static function ( array $first, array $second ): int {
				return [ (int) $first['order_index'], (int) ( $first['id'] ?? 0 ) ]
					<=> [ (int) $second['order_index'], (int) ( $second['id'] ?? 0 ) ];
			}
		);

		return $rows;
	}

	/** Whether a name is one of the two post id columns. */
	private function is_column( string $column ): bool {
		return in_array( $column, [ 'from_post_id', 'to_post_id' ], true );
	}


	/** Create the relationships table once per request. */
	private function ensure_table(): void {
		if ( $this->initialized || ! $this->database instanceof AuditDatabase ) {
			return;
		}

		$table = $this->table_name();
		$sql   = "CREATE TABLE IF NOT EXISTS {$table} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, relationship_key varchar(64) NOT NULL, from_post_id bigint(20) unsigned NOT NULL, to_post_id bigint(20) unsigned NOT NULL, pivot_data longtext NULL, order_index int(11) NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (id), UNIQUE KEY unique_rel (relationship_key, from_post_id, to_post_id), KEY from_post (from_post_id, relationship_key), KEY to_post (to_post_id, relationship_key)) " . $this->database->get_charset_collate();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Internal table name and DDL.
		$this->database->query( $sql );
		$this->initialized = true;
	}

	private function table_name(): string {
		return $this->database instanceof AuditDatabase ? $this->database->prefix() . 'saltus_relationships' : 'saltus_relationships';
	}

	/**
	 * Row output format constant, guarded for non-WordPress contexts.
	 *
	 * @return mixed
	 */
	private function output_format() {
		return defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A';
	}

	/**
	 * Normalize a row for storage.
	 *
	 * @param array<string, mixed> $row Raw row values.
	 * @return array<string, mixed>
	 */
	private function encode_values( array $row ): array {
		$now = gmdate( 'Y-m-d H:i:s' );

		return [
			'relationship_key' => (string) ( $row['relationship_key'] ?? '' ),
			'from_post_id'     => (int) ( $row['from_post_id'] ?? 0 ),
			'to_post_id'       => (int) ( $row['to_post_id'] ?? 0 ),
			'pivot_data'       => $this->encode( $this->to_array( $row['pivot_data'] ?? [] ) ),
			'order_index'      => (int) ( $row['order_index'] ?? 0 ),
			'created_at'       => (string) ( $row['created_at'] ?? $now ),
			'updated_at'       => (string) ( $row['updated_at'] ?? $now ),
		];
	}

	/**
	 * Decode the JSON pivot payload back into an array.
	 *
	 * @param array<string, mixed> $row Stored row.
	 * @return array<string, mixed>
	 */
	private function decode_values( array $row ): array {
		$decoded           = json_decode( (string) ( $row['pivot_data'] ?? '' ), true );
		$row['pivot_data'] = is_array( $decoded ) ? $decoded : [];

		return $row;
	}

	/**
	 * Coerce a pivot payload to an array.
	 *
	 * @param mixed $value Raw pivot payload.
	 * @return array<string, mixed>
	 */
	private function to_array( $value ): array {
		return is_array( $value ) ? $value : [];
	}

	/** @param array<string, mixed> $value Payload to encode. */
	private function encode( array $value ): string {
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Non-WordPress fallback.

		return is_string( $encoded ) ? $encoded : '{}';
	}
}
