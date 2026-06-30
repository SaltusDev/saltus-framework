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
        $this->assertSame(404, ErrorCode::get_http_status(ErrorCode::TOOL_NOT_FOUND));
        $this->assertSame(422, ErrorCode::get_http_status(ErrorCode::INVALID_PARAMS));
        $this->assertSame(429, ErrorCode::get_http_status(ErrorCode::RATE_LIMITED));
        $this->assertSame(401, ErrorCode::get_http_status(ErrorCode::AUTH_ERROR));
        $this->assertSame(502, ErrorCode::get_http_status(ErrorCode::API_ERROR));
        $this->assertSame(404, ErrorCode::get_http_status(ErrorCode::RESOURCE_NOT_FOUND));
        $this->assertSame(500, ErrorCode::get_http_status(ErrorCode::INTERNAL_ERROR));
        $this->assertSame(500, ErrorCode::get_http_status(ErrorCode::TOOL_EXCEPTION));
        $this->assertSame(500, ErrorCode::get_http_status('unknown_code'));
    }

    public function testGetJsonRpcCode(): void
    {
        $this->assertSame(-32602, ErrorCode::get_json_rpc_code(ErrorCode::TOOL_NOT_FOUND));
        $this->assertSame(-32602, ErrorCode::get_json_rpc_code(ErrorCode::INVALID_PARAMS));
        $this->assertSame(-32000, ErrorCode::get_json_rpc_code(ErrorCode::RATE_LIMITED));
        $this->assertSame(-32000, ErrorCode::get_json_rpc_code(ErrorCode::AUTH_ERROR));
        $this->assertSame(-32000, ErrorCode::get_json_rpc_code(ErrorCode::API_ERROR));
        $this->assertSame(-32602, ErrorCode::get_json_rpc_code(ErrorCode::RESOURCE_NOT_FOUND));
        $this->assertSame(-32000, ErrorCode::get_json_rpc_code(ErrorCode::INTERNAL_ERROR));
        $this->assertSame(-32000, ErrorCode::get_json_rpc_code(ErrorCode::TOOL_EXCEPTION));
        $this->assertSame(-32000, ErrorCode::get_json_rpc_code('unknown_code'));
    }
    public function testGetHints(): void
    {
        $hints = ErrorCode::get_hints(ErrorCode::AUTH_ERROR);
        $this->assertContains('Check SALTUS_WP_USERNAME has the required capabilities', $hints);
        $this->assertContains('Verify the application password is correct and not expired', $hints);

        $hints = ErrorCode::get_hints('unknown_code');
        $this->assertSame(['No additional hints available'], $hints);
    }
}
