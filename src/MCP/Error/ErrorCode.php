<?php
namespace Saltus\WP\Framework\MCP\Error;

class ErrorCode {

	public const TOOL_NOT_FOUND      = 'tool_not_found';
	public const INVALID_PARAMS      = 'invalid_params';
	public const RATE_LIMITED        = 'rate_limited';
	public const AUTH_ERROR          = 'auth_error';
	public const API_ERROR           = 'api_error';
	public const RESOURCE_NOT_FOUND  = 'resource_not_found';
	public const INTERNAL_ERROR      = 'internal_error';
	public const TOOL_EXCEPTION      = 'tool_exception';

	private const HINTS = [
		self::TOOL_NOT_FOUND     => [
			'Check the tool name is spelled correctly',
			'Use tools/list to see all available tools',
		],
		self::INVALID_PARAMS     => [
			'Review the tool\'s inputSchema for required fields and types',
			'Use tools/list to inspect parameter specifications',
		],
		self::RATE_LIMITED       => [
			'Reduce request frequency',
			'Configure SALTUS_RATE_LIMIT_MAX for a higher ceiling',
		],
		self::AUTH_ERROR         => [
			'Check SALTUS_WP_USERNAME has the required capabilities',
			'Verify the application password is correct and not expired',
			'Ensure SALTUS_WP_URL points to the correct WordPress installation',
		],
		self::API_ERROR          => [
			'Check the WordPress REST API is accessible',
			'Verify the endpoint and parameters are valid',
		],
		self::RESOURCE_NOT_FOUND => [
			'Check the resource URI is correct',
			'Use resources/list to see all available resources',
		],
		self::INTERNAL_ERROR     => [
			'Check server logs for more details',
			'Verify PHP memory limits and error reporting settings',
		],
		self::TOOL_EXCEPTION     => [
			'This is an unexpected error in the tool implementation',
			'Check server logs and report this issue',
		],
	];

	public static function getHttpStatus( string $code ): int {
		return match ( $code ) {
			self::TOOL_NOT_FOUND     => 404,
			self::INVALID_PARAMS     => 422,
			self::RATE_LIMITED       => 429,
			self::AUTH_ERROR         => 401,
			self::API_ERROR          => 502,
			self::RESOURCE_NOT_FOUND => 404,
			self::INTERNAL_ERROR     => 500,
			self::TOOL_EXCEPTION     => 500,
			default                  => 500,
		};
	}

	public static function getJsonRpcCode( string $code ): int {
		return match ( $code ) {
			self::TOOL_NOT_FOUND     => -32602,
			self::INVALID_PARAMS     => -32602,
			self::RATE_LIMITED       => -32000,
			self::AUTH_ERROR         => -32000,
			self::API_ERROR          => -32000,
			self::RESOURCE_NOT_FOUND => -32602,
			self::INTERNAL_ERROR     => -32000,
			self::TOOL_EXCEPTION     => -32000,
			default                  => -32000,
		};
	}

	public static function getDefaultMessage( string $code ): string {
		return match ( $code ) {
			self::TOOL_NOT_FOUND     => 'The requested tool was not found',
			self::INVALID_PARAMS     => 'Invalid parameters provided',
			self::RATE_LIMITED       => 'Rate limit exceeded',
			self::AUTH_ERROR         => 'Authentication failed',
			self::API_ERROR          => 'WordPress REST API returned an error',
			self::RESOURCE_NOT_FOUND => 'The requested resource was not found',
			self::INTERNAL_ERROR     => 'Internal server error',
			self::TOOL_EXCEPTION     => 'Unexpected error in tool execution',
			default                  => 'Unknown error',
		};
	}

	/**
	 * @return list<string>
	 */
	public static function getHints( string $code ): array {
		return self::HINTS[ $code ] ?? [ 'No additional hints available' ];
	}
}
