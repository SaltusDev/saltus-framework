<?php
namespace Saltus\WP\Framework\MCP\Audit;

class AuditLogger {

	private const TABLE_SUFFIX = 'saltus_mcp_audit';

	/** @var list<string> */
	private const VALID_STATUSES = [
		'started',
		'success',
		'error',
		'cache_hit',
		'validation_error',
		'rate_limited',
		'exception',
	];

	public function record( AuditEntry $entry ): void {
		if ( ! $this->enabled() ) {
			return;
		}

		$this->ensure_table();

		$wpdb = $this->wpdb();
		if ( $wpdb === null ) {
			return;
		}

		$data = $entry->to_array();
		$wpdb->insert(
			$this->table_name(),
			[
				'created_at'    => $data['timestamp'],
				'user_id'       => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
				'identifier'    => $data['identifier'] !== null ? $this->sanitize( $data['identifier'], 191 ) : null,
				'ability'       => $this->sanitize( $data['tool'], 191 ),
				'arguments'     => $this->encode( is_array( $data['arguments'] ) ? $data['arguments'] : [] ),
				'status'        => $this->validate_status( $data['status'] ),
				'duration_ms'   => $data['duration_ms'],
				'error_code'    => $data['error_code'] !== null ? $this->sanitize( $data['error_code'], 191 ) : null,
				'error_message' => $data['error_message'] !== null ? $this->sanitize( $data['error_message'], 65535 ) : null,
			],
			[ '%s', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s' ]
		);

		$this->cleanup();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function get_recent_entries( int $limit = 100 ): array {
		$wpdb = $this->wpdb();
		if ( $wpdb === null ) {
			return [];
		}

		$sql = 'SELECT * FROM ' . $this->table_name() . ' ORDER BY id DESC LIMIT ' . max( 1, $limit );

		$output = defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is assembled from an internal table name and integer limit.
		$rows = $wpdb->get_results( $sql, $output );

		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : [];
	}

	private function ensure_table(): void {
		$wpdb = $this->wpdb();
		if ( $wpdb === null ) {
			return;
		}

		$table           = $this->table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE IF NOT EXISTS {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at varchar(32) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			identifier varchar(191) NULL,
			ability varchar(191) NOT NULL,
			arguments longtext NULL,
			status varchar(32) NOT NULL,
			duration_ms double NULL,
			error_code varchar(191) NULL,
			error_message text NULL,
			PRIMARY KEY  (id),
			KEY ability (ability),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) {$charset_collate}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL uses the internal audit table name.
		$wpdb->query( $sql );
	}

	private function cleanup(): void {
		$days = (int) $this->filter( 'saltus/framework/mcp/audit/retention_days', 30 );
		if ( $days <= 0 ) {
			return;
		}

		$wpdb = $this->wpdb();
		if ( $wpdb === null ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d\TH:i:s\Z', time() - ( $days * 86400 ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Cutoff is gmdate output and table name is internal.
		$wpdb->query( 'DELETE FROM ' . $this->table_name() . " WHERE created_at < '{$cutoff}'" );
	}

	private function enabled(): bool {
		return (bool) $this->filter( 'saltus/framework/mcp/audit/enabled', true );
	}

	private function table_name(): string {
		$wpdb   = $this->wpdb();
		$prefix = $wpdb !== null ? $wpdb->prefix() : '';

		return $prefix . self::TABLE_SUFFIX;
	}

	private function wpdb(): ?AuditDatabase {
		global $wpdb;

		if ( $wpdb instanceof AuditDatabase ) {
			return $wpdb;
		}

		if ( $wpdb instanceof \wpdb ) {
			return new WpdbAuditDatabase( $wpdb );
		}

		return null;
	}

	/**
	 * @param positive-int $max_length
	 */
	private function sanitize( string $value, int $max_length ): string {
		$value = str_replace( "\0", '', $value );

		if ( function_exists( 'sanitize_text_field' ) ) {
			$value = sanitize_text_field( $value );
		}

		if ( mb_strlen( $value ) > $max_length ) {
			$value = mb_substr( $value, 0, $max_length );
		}

		return $value;
	}

	private function validate_status( string $status ): string {
		$status = $this->sanitize( $status, 32 );

		if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
			return 'error';
		}

		return $status;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function encode( array $data ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			$encoded = wp_json_encode( $data );
			return is_string( $encoded ) ? $encoded : '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fallback for non-WordPress contexts.
		$encoded = json_encode( $data );
		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * @param non-empty-string $hook
	 */
	private function filter( string $hook, mixed $value ): mixed {
		if ( function_exists( 'apply_filters' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook names are internal constants passed through this helper.
			return apply_filters( $hook, $value );
		}

		return $value;
	}
}
