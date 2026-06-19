<?php

namespace Saltus\WP\Framework\Tests\MCP\Tools;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Tools\GetMetaFields;
use Saltus\WP\Framework\MCP\Client\WordPressClient;

class GetMetaFieldsTest extends TestCase
{
    private GetMetaFields $tool;

    protected function setUp(): void
    {
        $this->tool = new GetMetaFields();
    }

    public function testGetName(): void
    {
        $this->assertSame('get_meta_fields', $this->tool->getName());
    }

    public function testGetDescription(): void
    {
        $this->assertNotEmpty($this->tool->getDescription());
    }

    public function testGetParametersHasRequiredPostType(): void
    {
        $params = $this->tool->getParameters();
        $this->assertArrayHasKey('post_type', $params);
        $this->assertTrue($params['post_type']['required']);
    }

    public function testHandleGetsMetaFieldsSuccessfully(): void
    {
        $client = $this->createMock(WordPressClient::class);
        $client->expects($this->once())
            ->method('get')
            ->with('saltus-framework/v1/meta/book')
            ->willReturn([
                'post_type' => 'book',
                'meta' => [
                    ['id' => 'author', 'type' => 'text', 'title' => 'Author'],
                    ['id' => 'isbn', 'type' => 'text', 'title' => 'ISBN'],
                ],
            ]);

        $result = $this->tool->handle(['post_type' => 'book'], $client);

        $this->assertSame('book', $result['post_type']);
        $this->assertCount(2, $result['meta']);
        $this->assertSame('author', $result['meta'][0]['id']);
    }

    public function testHandleMissingPostTypeReturnsError(): void
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
            'code' => 'model_not_found',
            'message' => 'Model not found.',
        ]);

        $result = $this->tool->handle(['post_type' => 'nonexistent'], $client);

        $this->assertArrayHasKey('code', $result);
        $this->assertSame('model_not_found', $result['code']);
    }
}
