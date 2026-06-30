<?php
namespace Saltus\WP\Framework\MCP\Audit;

class AuditLogger {

	private bool $enabled;
	private int $maxMemoryEntries;
	/** @var list<AuditEntry> */
	private array $entries = [];
	private bool $logToStderr;
	private ?string $logFile;
	/** @var resource|null */
	private $fileHandle;

	public function __construct(
		bool $enabled = true,
		bool $logToStderr = true,
		?string $logFile = null,
		int $maxMemoryEntries = 1000
	) {
		$this->enabled          = $enabled;
		$this->logToStderr      = $logToStderr;
		$this->logFile          = $logFile;
		$this->maxMemoryEntries = $maxMemoryEntries;
		$this->fileHandle       = null;
	}

	public function __destruct() {
		if ( $this->fileHandle !== null ) {
			fclose( $this->fileHandle );
		}
	}

	public function record( AuditEntry $entry ): void {
		if ( ! $this->enabled ) {
			return;
		}

		$this->entries[] = $entry;

		if ( count( $this->entries ) > $this->maxMemoryEntries ) {
			array_shift( $this->entries );
		}

		$line = json_encode( $entry->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";

		if ( $this->logToStderr ) {
			fwrite( STDERR, $line );
		}

		if ( $this->logFile !== null ) {
			$this->writeFile( $line );
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function getRecentEntries( int $limit = 100 ): array {
		$result = [];
		$count  = count( $this->entries );
		$start  = max( 0, $count - $limit );

		for ( $i = $start; $i < $count; $i++ ) {
			$result[] = $this->entries[ $i ]->toArray();
		}

		return $result;
	}

	/**
	 * @return array{total: int, recent: list<array<string, mixed>>}
	 */
	public function getStats(): array {
		$errorCount = 0;
		foreach ( $this->entries as $entry ) {
			$arr = $entry->toArray();
			if ( $arr['status'] !== 'success' ) {
				$errorCount++;
			}
		}

		return [
			'total'       => count( $this->entries ),
			'errors'      => $errorCount,
			'recent'      => $this->getRecentEntries( 10 ),
		];
	}

	private function writeFile( string $line ): void {
		if ( $this->fileHandle === null ) {
			$handle = fopen( $this->logFile, 'a' );
			if ( $handle === false ) {
				trigger_error( 'AuditLogger: could not open log file: ' . $this->logFile, E_USER_WARNING );
				return;
			}
			$this->fileHandle = $handle;
		}

		fwrite( $this->fileHandle, $line );
	}
}
