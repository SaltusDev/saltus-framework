<?php
namespace Saltus\WP\Framework\MCP\Audit;

/**
 * Value object representing a single MCP audit trail entry.
 */
class AuditEntry {

	private string $tool_name;
	/** @var array<string, mixed> */
	private array $arguments;
	private float $started_at;
	private ?float $completed_at;
	private string $status;
	private ?string $error_code;
	private ?string $error_message;
	private ?string $identifier;

	/**
	 * @param string $tool_name   The name of the tool being executed.
	 * @param array<string, mixed> $arguments  Arguments passed to the tool.
	 * @param string|null $identifier  Optional user or session identifier.
	 */
	public function __construct( string $tool_name, array $arguments, ?string $identifier = null ) {
		$this->tool_name     = $tool_name;
		$this->arguments     = $arguments;
		$this->started_at    = microtime( true );
		$this->completed_at  = null;
		$this->status        = 'started';
		$this->error_code    = null;
		$this->error_message = null;
		$this->identifier    = $identifier;
	}

	/**
	 * Mark the entry as completed with a status and optional error details.
	 *
	 * @param string $status  Result status (success, error, cache_hit, etc.).
	 * @param string|null $error_code  Machine-readable error code.
	 * @param string|null $error_message  Human-readable error message.
	 */
	public function complete( string $status, ?string $error_code = null, ?string $error_message = null ): void {
		$this->completed_at  = microtime( true );
		$this->status        = $status;
		$this->error_code    = $error_code;
		$this->error_message = $error_message;
	}

	/**
	 * Get the elapsed duration in milliseconds, or null if not yet completed.
	 *
	 * @return float|null  Duration in milliseconds, or null if incomplete.
	 */
	public function get_duration(): ?float {
		if ( $this->completed_at === null ) {
			return null;
		}
		return ( $this->completed_at - $this->started_at ) * 1000;
	}

	/**
	 * Convert the audit entry to an array for persistence.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'timestamp'     => gmdate( 'Y-m-d\TH:i:s.v\Z', (int) $this->started_at ),
			'tool'          => $this->tool_name,
			'arguments'     => $this->arguments,
			'identifier'    => $this->identifier,
			'status'        => $this->status,
			'duration_ms'   => $this->get_duration(),
			'error_code'    => $this->error_code,
			'error_message' => $this->error_message,
		];
	}
}
