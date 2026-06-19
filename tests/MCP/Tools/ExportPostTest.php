<?php

namespace Saltus\WP\Framework\Tests\MCP\Tools;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Tools\ExportPost;
use Saltus\WP\Framework\MCP\Client\WordPressClient;

class ExportPostTest extends TestCase
{
    private ExportPost $tool;

    protected function setUp(): void
    {
        $this->tool = new ExportPost();
    }

    public function testGetName(): void
    {
        $this->assertSame('export_post', $this->tool->getName());
    }

    public function testGetDescription(): void
    {
        $this->assertNotEmpty($this->tool->getDescription());
    }

    public function testGetParametersHasRequiredPostId(): void
    {
        $params = $this->tool->getParameters();
        $this->assertArrayHasKey('post_id', $params);
        $this->assertTrue($params['post_id']['required']);
    }

    public function testHandleExportsPostSuccessfully(): void
    {
        $client = $this->createMock(WordPressClient::class);
        $client->expects($this->once())
            ->method('get')
            ->with('saltus-framework/v1/export/42')
            ->willReturn([
                'post_id' => 42,
                'post_type' => 'post',
                'post_title' => 'Test Post',
                'wxr' => '<?xml version="1.0" encoding="UTF-8" ?>',
            ]);

        $result = $this->tool->handle(['post_id' => 42], $client);

        $this->assertSame(42, $result['post_id']);
        $this->assertSame('Test Post', $result['title']);
        $this->assertStringContainsString('<?xml', $result['wxr']);
    }

    public function testHandleMissingPostIdReturnsError(): void
    {
        $client = $this->createMock(WordPressClient::class);
        $result = $this->tool->handle([], $client);

        $this->assertArrayHasKey('code', $result);
        $this->assertSame('invalid_params', $result['code']);
    }

    public function testHandlePassesThroughApiError(): void
    {
        $client = $this->createMock(WordPressClient::class);
        $client->method('get')->willReturn([
            'code' => 'post_not_found',
            'message' => 'Post not found.',
        ]);

        $result = $this->tool->handle(['post_id' => 999], $client);

        $this->assertArrayHasKey('code', $result);
        $this->assertSame('post_not_found', $result['code']);
    }
}
