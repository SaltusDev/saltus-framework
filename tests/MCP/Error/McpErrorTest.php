<?php

namespace Saltus\WP\Framework\Tests\MCP\Error;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Error\McpError;

class McpErrorTest extends TestCase
{
    public function testFromValidation(): void
    {
        $error = McpError::from_validation(["'title' is required", "'type' must be string"]);

        $arr = $error->to_array();

        $this->assertSame(-32602, $arr['code']);
        $this->assertStringContainsString('Invalid parameters:', $arr['message']);
        $this->assertStringContainsString('title', $arr['message']);
        $this->assertSame('invalid_params', $arr['data']['app_code']);
        $this->assertIsArray($arr['data']['hints']);
        $this->assertNotEmpty($arr['data']['hints']);
    }

    public function testFromApiError(): void
    {
        $wp_error = [
            'code' => 'rest_invalid_param',
            'message' => 'Invalid parameter(s): title',
            'status' => 400,
        ];

        $error = McpError::from_api_error($wp_error);
        $arr = $error->to_array();

        $this->assertSame(-32000, $arr['code']);
        $this->assertSame('Invalid parameter(s): title', $arr['message']);
        $this->assertSame('api_error', $arr['data']['app_code']);
        $this->assertSame($wp_error, $arr['data']['wp_error']);
    }

    public function testFromApiErrorWithAuthFailure(): void
    {
        $wp_error = [
            'code' => 'rest_forbidden',
            'message' => 'Sorry, you are not allowed to do that',
            'status' => 403,
        ];

        $error = McpError::from_api_error($wp_error);
        $arr = $error->to_array();

        $this->assertSame('auth_error', $arr['data']['app_code']);
        $this->assertArrayHasKey('hints', $arr['data']);
    }

    public function testFromRateLimit(): void
    {
        $error = McpError::from_rate_limit(30, 0);
        $arr = $error->to_array();

        $this->assertSame(-32000, $arr['code']);
        $this->assertStringContainsString('Rate limit exceeded', $arr['message']);
        $this->assertStringContainsString('30', $arr['message']);
        $this->assertSame('rate_limited', $arr['data']['app_code']);
        $this->assertSame(30, $arr['data']['retry_after']);
        $this->assertSame(0, $arr['data']['remaining']);
    }

    public function testFromThrowable(): void
    {
        $exception = new \RuntimeException('Something went wrong');
        $error = McpError::from_throwable($exception);
        $arr = $error->to_array();

        $this->assertSame(-32000, $arr['code']);
        $this->assertSame('Something went wrong', $arr['message']);
        $this->assertSame('tool_exception', $arr['data']['app_code']);
        $this->assertIsArray($arr['data']['hints']);
    }

    public function testNotFoundTool(): void
    {
        $error = McpError::not_found('tool', 'nonexistent_tool');
        $arr = $error->to_array();

        $this->assertSame(-32602, $arr['code']);
        $this->assertSame('Tool not found: nonexistent_tool', $arr['message']);
        $this->assertSame('tool_not_found', $arr['data']['app_code']);
    }

    public function testNotFoundResource(): void
    {
        $error = McpError::not_found('resource', 'saltus://unknown');
        $arr = $error->to_array();

        $this->assertSame(-32602, $arr['code']);
        $this->assertSame('Resource not found: saltus://unknown', $arr['message']);
        $this->assertSame('resource_not_found', $arr['data']['app_code']);
    }

    public function testNotFoundPrompt(): void
    {
        $error = McpError::not_found('prompt', 'nonexistent');
        $arr = $error->to_array();

        $this->assertSame('resource_not_found', $arr['data']['app_code']);
    }

    public function testInternalError(): void
    {
        $error = McpError::internal_error('Something broke');
        $arr = $error->to_array();

        $this->assertSame(-32000, $arr['code']);
        $this->assertSame('Something broke', $arr['message']);
        $this->assertSame('internal_error', $arr['data']['app_code']);
    }

    public function testGetAppCode(): void
    {
        $error = McpError::from_validation(["test"]);
        $this->assertSame('invalid_params', $error->get_app_code());
    }
}
