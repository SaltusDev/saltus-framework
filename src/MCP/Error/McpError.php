<?php
namespace Saltus\WP\Framework\MCP\Error;

class McpError {

	private string $app_code;
	private string $message;
	/** @var list<string> */
	private array $hints;
	/** @var array<string, mixed>|null */
	private ?array $wp_error;
	/** @var array<string, mixed>|null */
	private ?array $data;

	/**
	 * @param list<string> $hints
	 * @param array<string, mixed>|null $wp_error
	 * @param array<string, mixed>|null $data
	 */
	private function __construct(
		string $app_code,
		string $message,
		array $hints = [],
		?array $wp_error = null,
		?array $data = null
	) {
		$this->app_code = $app_code;
		$this->message  = $message;
		$this->hints    = $hints;
		$this->wp_error = $wp_error;
		$this->data     = $data;
	}

	/**
	 * @param list<string> $errors
	 */
	public static function from_validation( array $errors ): self {
		return new self(
			ErrorCode::INVALID_PARAMS,
			'Invalid parameters: ' . implode( '; ', $errors ),
			ErrorCode::get_hints( ErrorCode::INVALID_PARAMS )
		);
	}

	/**
	 * @param array<string, mixed> $wp_error
	 */
	public static function from_api_error( array $wp_error ): self {
		$code    = $wp_error['code'] ?? 'unknown';
		$message = $wp_error['message'] ?? 'Unknown WordPress API error';

		$app_code = ErrorCode::API_ERROR;

		if ( str_starts_with( (string) $code, 'rest_forbidden' ) || str_starts_with( (string) $code, 'rest_cannot' ) ) {
			$app_code = ErrorCode::AUTH_ERROR;
		}

		return new self(
			$app_code,
			$message,
			ErrorCode::get_hints( $app_code ),
			$wp_error
		);
	}

	public static function from_rate_limit( int $retry_after, int $remaining ): self {
		return new self(
			ErrorCode::RATE_LIMITED,
			sprintf( 'Rate limit exceeded. Retry after %d seconds', $retry_after ),
			ErrorCode::get_hints( ErrorCode::RATE_LIMITED ),
			null,
			[
				'retry_after' => $retry_after,
				'remaining'   => $remaining,
			]
		);
	}

	public static function from_throwable( \Throwable $e ): self {
		return new self(
			ErrorCode::TOOL_EXCEPTION,
			$e->getMessage(),
			ErrorCode::get_hints( ErrorCode::TOOL_EXCEPTION )
		);
	}

	public static function not_found( string $type, string $name ): self {
		$app_code = match ( $type ) {
			'tool'     => ErrorCode::TOOL_NOT_FOUND,
			'resource' => ErrorCode::RESOURCE_NOT_FOUND,
			'prompt'   => ErrorCode::RESOURCE_NOT_FOUND,
			default    => ErrorCode::RESOURCE_NOT_FOUND,
		};

		return new self(
			$app_code,
			sprintf( '%s not found: %s', ucfirst( $type ), $name ),
			ErrorCode::get_hints( $app_code )
		);
	}

	public static function internal_error( string $message ): self {
		return new self(
			ErrorCode::INTERNAL_ERROR,
			$message,
			ErrorCode::get_hints( ErrorCode::INTERNAL_ERROR )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$data = [
			'app_code' => $this->app_code,
			'detail'   => $this->message,
			'hints'    => $this->hints,
		];

		if ( $this->wp_error !== null ) {
			$data['wp_error'] = $this->wp_error;
		}

		if ( $this->data !== null ) {
			foreach ( $this->data as $key => $value ) {
				$data[ $key ] = $value;
			}
		}

		return [
			'code'    => ErrorCode::get_json_rpc_code( $this->app_code ),
			'message' => $this->message,
			'data'    => $data,
		];
	}

	public function get_app_code(): string {
		return $this->app_code;
	}
}
