<?php
namespace Saltus\WP\Framework\MCP\Error;

class McpError {

	private string $appCode;
	private string $message;
	/** @var list<string> */
	private array $hints;
	/** @var array<string, mixed>|null */
	private ?array $wpError;
	/** @var array<string, mixed>|null */
	private ?array $data;

	/**
	 * @param list<string> $hints
	 * @param array<string, mixed>|null $wpError
	 * @param array<string, mixed>|null $data
	 */
	private function __construct(
		string $appCode,
		string $message,
		array $hints = [],
		?array $wpError = null,
		?array $data = null
	) {
		$this->appCode = $appCode;
		$this->message = $message;
		$this->hints   = $hints;
		$this->wpError = $wpError;
		$this->data    = $data;
	}

	/**
	 * @param list<string> $errors
	 */
	public static function fromValidation( array $errors ): self {
		return new self(
			ErrorCode::INVALID_PARAMS,
			'Invalid parameters: ' . implode( '; ', $errors ),
			ErrorCode::getHints( ErrorCode::INVALID_PARAMS )
		);
	}

	/**
	 * @param array<string, mixed> $wpError
	 */
	public static function fromApiError( array $wpError ): self {
		$code    = $wpError['code'] ?? 'unknown';
		$message = $wpError['message'] ?? 'Unknown WordPress API error';

		$appCode = $code === 'rest_forbidden' || str_starts_with( (string) $code, 'rest_' )
			? ErrorCode::API_ERROR
			: ErrorCode::API_ERROR;

		if ( str_starts_with( (string) $code, 'rest_forbidden' ) || str_starts_with( (string) $code, 'rest_cannot' ) ) {
			$appCode = ErrorCode::AUTH_ERROR;
		}

		return new self(
			$appCode,
			$message,
			ErrorCode::getHints( $appCode ),
			$wpError
		);
	}

	public static function fromRateLimit( int $retryAfter, int $remaining ): self {
		return new self(
			ErrorCode::RATE_LIMITED,
			sprintf( 'Rate limit exceeded. Retry after %d seconds', $retryAfter ),
			ErrorCode::getHints( ErrorCode::RATE_LIMITED ),
			null,
			[
				'retryAfter' => $retryAfter,
				'remaining'  => $remaining,
			]
		);
	}

	public static function fromThrowable( \Throwable $e ): self {
		return new self(
			ErrorCode::TOOL_EXCEPTION,
			$e->getMessage(),
			ErrorCode::getHints( ErrorCode::TOOL_EXCEPTION )
		);
	}

	public static function notFound( string $type, string $name ): self {
		$appCode = match ( $type ) {
			'tool'     => ErrorCode::TOOL_NOT_FOUND,
			'resource' => ErrorCode::RESOURCE_NOT_FOUND,
			'prompt'   => ErrorCode::RESOURCE_NOT_FOUND,
			default    => ErrorCode::RESOURCE_NOT_FOUND,
		};

		return new self(
			$appCode,
			sprintf( '%s not found: %s', ucfirst( $type ), $name ),
			ErrorCode::getHints( $appCode )
		);
	}

	public static function internalError( string $message ): self {
		return new self(
			ErrorCode::INTERNAL_ERROR,
			$message,
			ErrorCode::getHints( ErrorCode::INTERNAL_ERROR )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$data = [
			'appCode' => $this->appCode,
			'detail'  => $this->message,
			'hints'   => $this->hints,
		];

		if ( $this->wpError !== null ) {
			$data['wpError'] = $this->wpError;
		}

		if ( $this->data !== null ) {
			foreach ( $this->data as $key => $value ) {
				$data[ $key ] = $value;
			}
		}

		return [
			'code'    => ErrorCode::getJsonRpcCode( $this->appCode ),
			'message' => $this->message,
			'data'    => $data,
		];
	}

	public function getAppCode(): string {
		return $this->appCode;
	}
}
