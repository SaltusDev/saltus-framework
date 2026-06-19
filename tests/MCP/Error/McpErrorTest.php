<?php

namespace Saltus\WP\Framework\Tests\MCP\Error;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Error\McpError;

class McpErrorTest extends TestCase
{
    public function testFromValidation(): void
    {
        $error = McpError::fromValidation(["'title' is required", "'type' must be string"]);

        $arr = $error->toArray();

        $this->assertSame(-32602, $arr['code']);
        $this->assertStringContainsString('Invalid parameters:', $arr['message']);
        $this->assertStringContainsString('title', $arr['message']);
        $this->assertSame('invalid_params', $arr['data']['appCode']);
        $this->assertIsArray($arr['data']['hints']);
        $this->assertNotEmpty($arr['data']['hints']);
    }

    public function testFromApiError(): void
    {
        $wpError = [
            'code' => 'rest_invalid_param',
            'message' => 'Invalid parameter(s): title',
            'status' => 400,
        ];

        $error = McpError::fromApiError($wpError);
        $arr = $error->toArray();

        $this->assertSame(-32000, $arr['code']);
        $this->assertSame('Invalid parameter(s): title', $arr['message']);
        $this->assertSame('api_error', $arr['data']['appCode']);
        $this->assertSame($wpError, $arr['data']['wpError']);
    }

    public function testFromApiErrorWithAuthFailure(): void
    {
        $wpError = [
            'code' => 'rest_forbidden',
            'message' => 'Sorry, you are not allowed to do that',
            'status' => 403,
        ];

        $error = McpError::fromApiError($wpError);
        $arr = $error->toArray();

        $this->assertSame('auth_error', $arr['data']['appCode']);
        $this->assertArrayHasKey('hints', $arr['data']);
    }

    public function testFromRateLimit(): void
    {
        $error = McpError::fromRateLimit(30, 0);
        $arr = $error->toArray();

        $this->assertSame(-32000, $arr['code']);
        $this->assertStringContainsString('Rate limit exceeded', $arr['message']);
        $this->assertStringContainsString('30', $arr['message']);
        $this->assertSame('rate_limited', $arr['data']['appCode']);
        $this->assertSame(30, $arr['data']['retryAfter']);
        $this->assertSame(0, $arr['data']['remaining']);
    }

    public function testFromThrowable(): void
    {
        $exception = new \RuntimeException('Something went wrong');
        $error = McpError::fromThrowable($exception);
        $arr = $error->toArray();

        $this->assertSame(-32000, $arr['code']);
        $this->assertSame('Something went wrong', $arr['message']);
        $this->assertSame('tool_exception', $arr['data']['appCode']);
        $this->assertIsArray($arr['data']['hints']);
    }

    public function testNotFoundTool(): void
    {
        $error = McpError::notFound('tool', 'nonexistent_tool');
        $arr = $error->toArray();

        $this->assertSame(-32602, $arr['code']);
        $this->assertSame('Tool not found: nonexistent_tool', $arr['message']);
        $this->assertSame('tool_not_found', $arr['data']['appCode']);
    }

    public function testNotFoundResource(): void
    {
        $error = McpError::notFound('resource', 'saltus://unknown');
        $arr = $error->toArray();

        $this->assertSame(-32602, $arr['code']);
        $this->assertSame('Resource not found: saltus://unknown', $arr['message']);
        $this->assertSame('resource_not_found', $arr['data']['appCode']);
    }

    public function testNotFoundPrompt(): void
    {
        $error = McpError::notFound('prompt', 'nonexistent');
        $arr = $error->toArray();

        $this->assertSame('resource_not_found', $arr['data']['appCode']);
    }

    public function testInternalError(): void
    {
        $error = McpError::internalError('Something broke');
        $arr = $error->toArray();

        $this->assertSame(-32000, $arr['code']);
        $this->assertSame('Something broke', $arr['message']);
        $this->assertSame('internal_error', $arr['data']['appCode']);
    }

    public function testGetAppCode(): void
    {
        $error = McpError::fromValidation(["test"]);
        $this->assertSame('invalid_params', $error->getAppCode());
    }
}
