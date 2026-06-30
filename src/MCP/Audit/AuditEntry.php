<?php
namespace Saltus\WP\Framework\MCP\Audit;

class AuditEntry {

	private string $tool_name;
	/** @var array<string, mixed> */
	private array $arguments;
	private float $started_at;
	private ?float $completed_at;
	private string $status;
	private ?string $error_code;
	private ?string $error_message;

	/**
	 * @param array<string, mixed> $arguments
	 */
	public function __construct( string $tool_name, array $arguments ) {
		$this->tool_name     = $tool_name;
		$this->arguments     = $arguments;
		$this->started_at    = microtime( true );
		$this->completed_at  = null;
		$this->status        = 'started';
		$this->error_code    = null;
		$this->error_message = null;
	}

	public function complete( string $status, ?string $error_code = null, ?string $error_message = null ): void {
		$this->completed_at  = microtime( true );
		$this->status        = $status;
		$this->error_code    = $error_code;
		$this->error_message = $error_message;
	}

	public function get_duration(): ?float {
		if ( $this->completed_at === null ) {
			return null;
		}
		return ( $this->completed_at - $this->started_at ) * 1000;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'timestamp'     => gmdate( 'Y-m-d\TH:i:s.v\Z', (int) $this->started_at ),
			'tool'          => $this->tool_name,
			'arguments'     => $this->arguments,
			'status'        => $this->status,
			'duration_ms'   => $this->get_duration(),
			'error_code'    => $this->error_code,
			'error_message' => $this->error_message,
		];
	}
}
