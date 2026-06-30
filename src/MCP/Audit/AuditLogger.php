<?php
namespace Saltus\WP\Framework\MCP\Audit;

use Saltus\WP\Framework\MCP\Support\Json;

class AuditLogger {

	private bool $enabled;
	private int $max_memory_entries;
	/** @var list<AuditEntry> */
	private array $entries = [];
	private bool $log_to_stderr;
	private ?string $log_file;
	/** @var resource|null */
	private $file_handle;

	public function __construct(
		bool $enabled = true,
		bool $log_to_stderr = true,
		?string $log_file = null,
		int $max_memory_entries = 1000
	) {
		$this->enabled            = $enabled;
		$this->log_to_stderr      = $log_to_stderr;
		$this->log_file           = $log_file;
		$this->max_memory_entries = $max_memory_entries;
		$this->file_handle        = null;
	}

	public function __destruct() {
		if ( $this->file_handle !== null ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- MCP audit logging uses an optional CLI file handle.
			fclose( $this->file_handle );
		}
	}

	public function record( AuditEntry $entry ): void {
		if ( ! $this->enabled ) {
			return;
		}

		$this->entries[] = $entry;

		if ( count( $this->entries ) > $this->max_memory_entries ) {
			array_shift( $this->entries );
		}

		$line = Json::encode( $entry->to_array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";

		if ( $this->log_to_stderr ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- STDERR is the expected MCP CLI diagnostics stream.
			fwrite( STDERR, $line );
		}

		if ( $this->log_file !== null ) {
			$this->write_file( $line );
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function get_recent_entries( int $limit = 100 ): array {
		$result = [];
		$count  = count( $this->entries );
		$start  = max( 0, $count - $limit );

		for ( $i = $start; $i < $count; $i++ ) {
			$result[] = $this->entries[ $i ]->to_array();
		}

		return $result;
	}

	/**
	 * @return array{total: int, recent: list<array<string, mixed>>}
	 */
	public function get_stats(): array {
		$error_count = 0;
		foreach ( $this->entries as $entry ) {
			$arr = $entry->to_array();
			if ( $arr['status'] !== 'success' ) {
				++$error_count;
			}
		}

		return [
			'total'  => count( $this->entries ),
			'errors' => $error_count,
			'recent' => $this->get_recent_entries( 10 ),
		];
	}

	private function write_file( string $line ): void {
		if ( $this->file_handle === null ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- MCP audit logging may run before WP_Filesystem is available.
			$handle = fopen( $this->log_file, 'a' );
			if ( $handle === false ) {
				return;
			}
			$this->file_handle = $handle;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- MCP audit logging uses the opened CLI file handle.
		fwrite( $this->file_handle, $line );
	}
}
