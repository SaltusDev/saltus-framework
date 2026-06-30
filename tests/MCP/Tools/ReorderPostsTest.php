<?php

namespace Saltus\WP\Framework\Tests\MCP\Tools;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Tools\ReorderPosts;
use Saltus\WP\Framework\MCP\Client\WordPressClient;

class ReorderPostsTest extends TestCase
{
    private ReorderPosts $tool;

    protected function setUp(): void
    {
        $this->tool = new ReorderPosts();
    }

    public function testGetName(): void
    {
        $this->assertSame('reorder_posts', $this->tool->get_name());
    }

    public function testGetDescription(): void
    {
        $this->assertNotEmpty($this->tool->get_description());
    }

    public function testGetParametersHasRequiredItems(): void
    {
        $params = $this->tool->get_parameters();
        $this->assertArrayHasKey('items', $params);
        $this->assertTrue($params['items']['required']);
    }

    public function testHandleReordersPostsSuccessfully(): void
    {
        $client = $this->createMock(WordPressClient::class);
        $items = [
            ['id' => 1, 'menu_order' => 0],
            ['id' => 2, 'menu_order' => 1],
        ];
        $client->expects($this->once())
            ->method('post')
            ->with('saltus-framework/v1/reorder', ['items' => $items])
            ->willReturn([
                'results' => [
                    ['id' => 1, 'menu_order' => 0, 'status' => 'updated'],
                    ['id' => 2, 'menu_order' => 1, 'status' => 'updated'],
                ],
                'total' => 2,
                'updated' => 2,
            ]);

        $result = $this->tool->handle(['items' => $items], $client);

        $this->assertSame(2, $result['total']);
        $this->assertSame(2, $result['updated']);
    }

    public function testHandleMissingItemsReturnsError(): void
    {
        $client = $this->createStub(WordPressClient::class);
        $result = $this->tool->handle([], $client);

        $this->assertArrayHasKey('code', $result);
        $this->assertSame('invalid_params', $result['code']);
    }

    public function testHandleEmptyItemsReturnsError(): void
    {
        $client = $this->createStub(WordPressClient::class);
        $result = $this->tool->handle(['items' => []], $client);

        $this->assertArrayHasKey('code', $result);
        $this->assertSame('invalid_params', $result['code']);
    }

    public function testHandlePassesThroughApiError(): void
    {
        $client = $this->createStub(WordPressClient::class);
        $client->method('post')->willReturn([
            'code' => 'rest_empty_data',
            'message' => 'No items provided.',
        ]);

        $result = $this->tool->handle(['items' => [['id' => 1, 'menu_order' => 0]]], $client);

        $this->assertArrayHasKey('code', $result);
        $this->assertSame('rest_empty_data', $result['code']);
    }
}
