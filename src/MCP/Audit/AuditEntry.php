<?php
namespace Saltus\WP\Framework\MCP\Audit;

class AuditEntry {

	private string $toolName;
	/** @var array<string, mixed> */
	private array $arguments;
	private float $startedAt;
	private ?float $completedAt;
	private string $status;
	private ?string $errorCode;
	private ?string $errorMessage;

	/**
	 * @param array<string, mixed> $arguments
	 */
	public function __construct( string $toolName, array $arguments ) {
		$this->toolName  = $toolName;
		$this->arguments = $arguments;
		$this->startedAt = microtime( true );
		$this->completedAt = null;
		$this->status     = 'started';
		$this->errorCode  = null;
		$this->errorMessage = null;
	}

	public function complete( string $status, ?string $errorCode = null, ?string $errorMessage = null ): void {
		$this->completedAt   = microtime( true );
		$this->status        = $status;
		$this->errorCode     = $errorCode;
		$this->errorMessage  = $errorMessage;
	}

	public function getDuration(): ?float {
		if ( $this->completedAt === null ) {
			return null;
		}
		return ( $this->completedAt - $this->startedAt ) * 1000;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'timestamp'    => gmdate( 'Y-m-d\TH:i:s.v\Z', (int) $this->startedAt ),
			'tool'         => $this->toolName,
			'arguments'    => $this->arguments,
			'status'       => $this->status,
			'duration_ms'  => $this->getDuration(),
			'error_code'   => $this->errorCode,
			'error_message' => $this->errorMessage,
		];
	}
}
