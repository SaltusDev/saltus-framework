<?php

namespace Saltus\WP\Framework\Tests\MCP\Error;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Error\ErrorCode;

class ErrorCodeTest extends TestCase
{
    public function testConstantsAreDefined(): void
    {
        $this->assertSame('tool_not_found', ErrorCode::TOOL_NOT_FOUND);
        $this->assertSame('invalid_params', ErrorCode::INVALID_PARAMS);
        $this->assertSame('rate_limited', ErrorCode::RATE_LIMITED);
        $this->assertSame('auth_error', ErrorCode::AUTH_ERROR);
        $this->assertSame('api_error', ErrorCode::API_ERROR);
        $this->assertSame('resource_not_found', ErrorCode::RESOURCE_NOT_FOUND);
        $this->assertSame('internal_error', ErrorCode::INTERNAL_ERROR);
        $this->assertSame('tool_exception', ErrorCode::TOOL_EXCEPTION);
    }

    public function testGetHttpStatus(): void
    {
        $this->assertSame(404, ErrorCode::getHttpStatus(ErrorCode::TOOL_NOT_FOUND));
        $this->assertSame(422, ErrorCode::getHttpStatus(ErrorCode::INVALID_PARAMS));
        $this->assertSame(429, ErrorCode::getHttpStatus(ErrorCode::RATE_LIMITED));
        $this->assertSame(401, ErrorCode::getHttpStatus(ErrorCode::AUTH_ERROR));
        $this->assertSame(502, ErrorCode::getHttpStatus(ErrorCode::API_ERROR));
        $this->assertSame(404, ErrorCode::getHttpStatus(ErrorCode::RESOURCE_NOT_FOUND));
        $this->assertSame(500, ErrorCode::getHttpStatus(ErrorCode::INTERNAL_ERROR));
        $this->assertSame(500, ErrorCode::getHttpStatus(ErrorCode::TOOL_EXCEPTION));
        $this->assertSame(500, ErrorCode::getHttpStatus('unknown_code'));
    }

    public function testGetJsonRpcCode(): void
    {
        $this->assertSame(-32602, ErrorCode::getJsonRpcCode(ErrorCode::TOOL_NOT_FOUND));
        $this->assertSame(-32602, ErrorCode::getJsonRpcCode(ErrorCode::INVALID_PARAMS));
        $this->assertSame(-32000, ErrorCode::getJsonRpcCode(ErrorCode::RATE_LIMITED));
        $this->assertSame(-32000, ErrorCode::getJsonRpcCode(ErrorCode::AUTH_ERROR));
        $this->assertSame(-32000, ErrorCode::getJsonRpcCode(ErrorCode::API_ERROR));
        $this->assertSame(-32602, ErrorCode::getJsonRpcCode(ErrorCode::RESOURCE_NOT_FOUND));
        $this->assertSame(-32000, ErrorCode::getJsonRpcCode(ErrorCode::INTERNAL_ERROR));
        $this->assertSame(-32000, ErrorCode::getJsonRpcCode(ErrorCode::TOOL_EXCEPTION));
        $this->assertSame(-32000, ErrorCode::getJsonRpcCode('unknown_code'));
    }

    public function testGetDefaultMessage(): void
    {
        $this->assertSame('The requested tool was not found', ErrorCode::getDefaultMessage(ErrorCode::TOOL_NOT_FOUND));
        $this->assertSame('Invalid parameters provided', ErrorCode::getDefaultMessage(ErrorCode::INVALID_PARAMS));
        $this->assertSame('Rate limit exceeded', ErrorCode::getDefaultMessage(ErrorCode::RATE_LIMITED));
        $this->assertSame('Unknown error', ErrorCode::getDefaultMessage('unknown_code'));
    }

    public function testGetHints(): void
    {
        $hints = ErrorCode::getHints(ErrorCode::AUTH_ERROR);
        $this->assertContains('Check SALTUS_WP_USERNAME has the required capabilities', $hints);
        $this->assertContains('Verify the application password is correct and not expired', $hints);

        $hints = ErrorCode::getHints('unknown_code');
        $this->assertSame(['No additional hints available'], $hints);
    }
}
