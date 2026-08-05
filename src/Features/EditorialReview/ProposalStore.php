<?php

namespace Saltus\WP\Framework\Features\EditorialReview;

use Saltus\WP\Framework\MCP\Audit\AuditDatabase;
use Saltus\WP\Framework\MCP\Audit\WpdbAuditDatabase;

/** Stores AI change proposals in WordPress or an in-process fallback. */
final class ProposalStore {

	/** @var list<array<string, mixed>> */
	private array $memory     = [];
	private int $next_id      = 1;
	private bool $initialized = false;
	private ?AuditDatabase $database;

	/** @param AuditDatabase|null $database Optional database adapter. */
	public function __construct( ?AuditDatabase $database = null ) {
		global $wpdb;
		$this->database = $database ?? ( $wpdb instanceof \wpdb ? new WpdbAuditDatabase( $wpdb ) : null );
	}

	/** @param array<string, mixed> $proposal */
	public function insert( array $proposal ): int {
		$proposal['created_at'] = (string) ( $proposal['created_at'] ?? gmdate( 'Y-m-d H:i:s' ) );
		$proposal['status']     = (string) ( $proposal['status'] ?? 'pending' );
		if ( $this->database instanceof AuditDatabase ) {
			$this->ensure_table();
			$this->database->insert(
				$this->table_name(),
				$this->encode_values( $proposal ),
				[ '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d' ]
			);
			$rows = $this->database->get_results( 'SELECT LAST_INSERT_ID() AS id', ARRAY_A );
			$row  = is_array( $rows ) && is_array( $rows[0] ?? null ) ? $rows[0] : [];
			return (int) ( $row['id'] ?? 0 );
		}
		$proposal['id'] = $this->next_id++;
		$this->memory[] = $proposal;
		return (int) $proposal['id'];
	}

	/** @return array<string, mixed>|null */
	public function get( int $id ): ?array {
		if ( $this->database instanceof AuditDatabase ) {
			$this->ensure_table();
			$rows = $this->database->get_results( $this->database->prepare( 'SELECT * FROM ' . $this->table_name() . ' WHERE id = %d LIMIT 1', $id ), ARRAY_A );
			$row  = is_array( $rows ) && is_array( $rows[0] ?? null ) ? $rows[0] : null;
			return is_array( $row ) ? $this->decode_values( $row ) : null;
		}
		foreach ( $this->memory as $proposal ) {
			if ( (int) ( $proposal['id'] ?? 0 ) === $id ) {
				return $proposal;
			}
		}
		return null;
	}

	/** @return list<array<string, mixed>> */
	public function list( string $status = '', int $limit = 50 ): array {
		$limit = max( 1, min( 100, $limit ) );
		if ( $this->database instanceof AuditDatabase ) {
			$this->ensure_table();
			$sql  = 'SELECT * FROM ' . $this->table_name();
			$args = [];
			if ( $status !== '' ) {
				$sql   .= ' WHERE status = %s';
				$args[] = $status;
			}
			$sql   .= ' ORDER BY id DESC LIMIT %d';
			$args[] = $limit;
			$rows   = $this->database->get_results( $this->database->prepare( $sql, ...$args ), ARRAY_A );
			return is_array( $rows ) ? array_values( array_map( [ $this, 'decode_values' ], array_filter( $rows, 'is_array' ) ) ) : [];
		}
		$rows = array_reverse( $this->memory );
		if ( $status !== '' ) {
			$rows = array_values( array_filter( $rows, static fn( array $row ): bool => ( $row['status'] ?? '' ) === $status ) );
		}
		return array_slice( $rows, 0, $limit );
	}

	/** @param array<string, mixed> $changes */
	public function update( int $id, array $changes ): bool {
		if ( $this->database instanceof AuditDatabase ) {
			$this->ensure_table();
			$sets = [];
			$args = [];
			foreach ( [ 'status', 'reviewed_at', 'reviewer_id', 'review_note', 'result_post_id' ] as $key ) {
				if ( ! array_key_exists( $key, $changes ) ) {
					continue;
				}
				$sets[] = $key . ' = ' . ( in_array( $key, [ 'reviewer_id', 'result_post_id' ], true ) ? '%d' : '%s' );
				$args[] = $changes[ $key ];
			}
			if ( empty( $sets ) ) {
				return false;
			}
			$args[] = $id;
			return $this->database->query( $this->database->prepare( 'UPDATE ' . $this->table_name() . ' SET ' . implode( ', ', $sets ) . ' WHERE id = %d', ...$args ) ) !== false;
		}
		foreach ( $this->memory as &$proposal ) {
			if ( (int) ( $proposal['id'] ?? 0 ) === $id ) {
				$proposal = array_merge( $proposal, $changes );
				return true;
			}
		}
		return false;
	}

	private function ensure_table(): void {
		if ( $this->initialized || ! $this->database instanceof AuditDatabase ) {
			return;
		}
		$table = $this->table_name();
		$sql   = "CREATE TABLE IF NOT EXISTS {$table} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, created_at datetime NOT NULL, tool varchar(191) NOT NULL, model varchar(191) NOT NULL, post_id bigint(20) unsigned NOT NULL DEFAULT 0, action varchar(32) NOT NULL, status varchar(32) NOT NULL, arguments longtext NOT NULL, changeset longtext NOT NULL, reviewed_at datetime NULL, reviewer_id bigint(20) unsigned NOT NULL DEFAULT 0, review_note text NULL, result_post_id bigint(20) unsigned NOT NULL DEFAULT 0, PRIMARY KEY (id), KEY status (status), KEY model (model)) " . $this->database->get_charset_collate();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Internal table name and DDL.
		$this->database->query( $sql );
		$this->initialized = true;
	}

	private function table_name(): string {
		return $this->database->prefix() . 'saltus_ai_proposals';
	}

	/**
	 * @param array<string, mixed> $proposal
	 * @return array<string, mixed>
	 */
	private function encode_values( array $proposal ): array {
		return [
			'created_at'     => $proposal['created_at'],
			'tool'           => $this->string_value( $proposal, 'tool' ),
			'model'          => $this->string_value( $proposal, 'model' ),
			'post_id'        => $this->integer_value( $proposal, 'post_id' ),
			'action'         => $this->string_value( $proposal, 'action' ),
			'status'         => $this->string_value( $proposal, 'status' ),
			'arguments'      => $this->array_value( $proposal, 'arguments' ),
			'changeset'      => $this->array_value( $proposal, 'changeset' ),
			'reviewed_at'    => $proposal['reviewed_at'] ?? null,
			'reviewer_id'    => $this->integer_value( $proposal, 'reviewer_id' ),
			'review_note'    => $proposal['review_note'] ?? null,
			'result_post_id' => $this->integer_value( $proposal, 'result_post_id' ),
		];
	}

	/** @param array<string, mixed> $proposal */
	private function string_value( array $proposal, string $key ): string {
		return (string) ( $proposal[ $key ] ?? '' );
	}

	/** @param array<string, mixed> $proposal */
	private function integer_value( array $proposal, string $key ): int {
		return (int) ( $proposal[ $key ] ?? 0 );
	}

	/** @param array<string, mixed> $proposal */
	private function array_value( array $proposal, string $key ): string {
		$value = is_array( $proposal[ $key ] ?? null ) ? $proposal[ $key ] : [];
		return $this->encode( $value );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function decode_values( array $row ): array {
		foreach ( [ 'arguments', 'changeset' ] as $key ) {
			$value       = json_decode( (string) ( $row[ $key ] ?? '' ), true );
			$row[ $key ] = is_array( $value ) ? $value : [];
		}
		return $row;
	}

	/** @param array<string, mixed> $value */
	private function encode( array $value ): string {
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Non-WordPress fallback.
		return is_string( $encoded ) ? $encoded : '{}';
	}
}
