<?php

namespace Saltus\WP\Framework\Tests\MCP\Tools;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Tools\GetPost;
use Saltus\WP\Framework\MCP\Client\WordPressClient;

class GetPostTest extends TestCase
{
    private GetPost $tool;

    protected function setUp(): void
    {
        $this->tool = new GetPost();
    }

    public function testGetName(): void
    {
        $this->assertSame('get_post', $this->tool->get_name());
    }

    public function testGetParametersRequiresPostId(): void
    {
        $params = $this->tool->get_parameters();
        $this->assertArrayHasKey('post_id', $params);
        $this->assertTrue($params['post_id']['required']);
    }

    public function testHandleReturnsErrorWhenPostIdMissing(): void
    {
        $client = $this->createStub(WordPressClient::class);

        $result = $this->tool->handle(['post_type' => 'posts'], $client);

        $this->assertArrayHasKey('code', $result);
        $this->assertSame('invalid_params', $result['code']);
    }

    public function testHandleReturnsPostSuccessfully(): void
    {
        $client = $this->createMock(WordPressClient::class);
        $client->expects($this->once())
            ->method('get')
            ->with('wp/v2/posts/42')
            ->willReturn([
                'id' => 42,
                'title' => ['rendered' => 'Hello World', 'raw' => 'Hello World'],
                'content' => ['rendered' => '<p>Content</p>', 'raw' => 'Content'],
                'excerpt' => ['rendered' => '<p>Excerpt</p>'],
                'slug' => 'hello-world',
                'status' => 'publish',
                'date' => '2024-01-01T00:00:00',
                'modified' => '2024-01-02T00:00:00',
                'type' => 'post',
                'author' => 1,
                'parent' => 0,
                'menu_order' => 0,
                'link' => 'https://example.com/hello-world',
            ]);

        $result = $this->tool->handle(['post_id' => 42], $client);

        $this->assertSame(42, $result['id']);
        $this->assertSame('Hello World', $result['title']);
        $this->assertSame('publish', $result['status']);
        $this->assertSame('https://example.com/hello-world', $result['permalink']);
    }

    public function testHandlePassesThroughApiError(): void
    {
        $client = $this->createStub(WordPressClient::class);
        $client->method('get')->willReturn([
            'code' => 'rest_post_invalid_id',
            'message' => 'Invalid post ID.',
        ]);

        $result = $this->tool->handle(['post_id' => 999], $client);

        $this->assertArrayHasKey('code', $result);
        $this->assertSame('rest_post_invalid_id', $result['code']);
    }

    public function testHandleIncludesMetaWhenPresent(): void
    {
        $client = $this->createStub(WordPressClient::class);
        $client->method('get')->willReturn([
            'id' => 1,
            'title' => ['rendered' => 'Test'],
            'meta' => ['custom_field' => 'value'],
        ]);

        $result = $this->tool->handle(['post_id' => 1], $client);

        $this->assertArrayHasKey('meta', $result);
        $this->assertSame('value', $result['meta']['custom_field']);
    }

    public function testHandleIncludesTermsWhenEmbedded(): void
    {
        $client = $this->createStub(WordPressClient::class);
        $client->method('get')->willReturn([
            'id' => 1,
            'title' => ['rendered' => 'Test'],
            '_embedded' => [
                'wp:term' => [
                    [
                        ['id' => 5, 'name' => 'Cat A', 'slug' => 'cat-a', 'taxonomy' => 'category'],
                    ],
                ],
            ],
        ]);

        $result = $this->tool->handle(['post_id' => 1], $client);

        $this->assertArrayHasKey('terms', $result);
        $this->assertCount(1, $result['terms']);
        $this->assertSame('Cat A', $result['terms'][0]['name']);
    }
}
