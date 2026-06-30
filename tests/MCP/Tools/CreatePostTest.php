<?php

namespace Saltus\WP\Framework\Tests\MCP\Tools;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Tools\CreatePost;
use Saltus\WP\Framework\MCP\Client\WordPressClient;

class CreatePostTest extends TestCase
{
    private CreatePost $tool;

    protected function setUp(): void
    {
        $this->tool = new CreatePost();
    }

    public function testGetName(): void
    {
        $this->assertSame('create_post', $this->tool->getName());
    }

    public function testGetDescription(): void
    {
        $this->assertNotEmpty($this->tool->getDescription());
    }

    public function testGetParametersHasRequiredTitle(): void
    {
        $params = $this->tool->getParameters();
        $this->assertArrayHasKey('title', $params);
        $this->assertTrue($params['title']['required']);
    }

    public function testHandleCreatesPostSuccessfully(): void
    {
        $client = $this->createMock(WordPressClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with('wp/v2/posts', $this->callback(function (array $data) {
                return isset($data['title']) && $data['title'] === 'Test Post' && $data['status'] === 'draft';
            }))
            ->willReturn([
                'id' => 123,
                'title' => ['rendered' => 'Test Post'],
                'link' => 'https://example.com/?p=123',
                'status' => 'draft',
            ]);

        $result = $this->tool->handle(['title' => 'Test Post'], $client);

        $this->assertSame(123, $result['id']);
        $this->assertSame('Test Post', $result['title']);
        $this->assertSame('draft', $result['status']);
    }

    public function testHandleSendsAllOptionalFields(): void
    {
        $client = $this->createMock(WordPressClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with('wp/v2/posts', $this->callback(function (array $data) {
                return $data['title'] === 'My Title'
                    && $data['content'] === 'Hello'
                    && $data['excerpt'] === 'Excerpt'
                    && $data['slug'] === 'my-title'
                    && $data['status'] === 'publish';
            }))
            ->willReturn(['id' => 1, 'title' => ['rendered' => 'My Title'], 'link' => '', 'status' => 'publish']);

        $result = $this->tool->handle([
            'title' => 'My Title',
            'content' => 'Hello',
            'excerpt' => 'Excerpt',
            'slug' => 'my-title',
            'status' => 'publish',
        ], $client);

        $this->assertSame(1, $result['id']);
    }

    public function testHandleSendsMetaFields(): void
    {
        $client = $this->createMock(WordPressClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with('wp/v2/posts', $this->callback(function (array $data) {
                return isset($data['meta']) && $data['meta'] === ['key' => 'value'];
            }))
            ->willReturn(['id' => 1, 'title' => ['rendered' => ''], 'link' => '', 'status' => 'draft']);

        $this->tool->handle(['title' => 'Test', 'meta' => ['key' => 'value']], $client);
    }

    public function testHandleSendsTermsAsTaxonomyKeys(): void
    {
        $client = $this->createMock(WordPressClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with('wp/v2/posts', $this->callback(function (array $data) {
                return isset($data['category']) && $data['category'] === [1, 2]
                    && isset($data['post_tag']) && $data['post_tag'] === [3];
            }))
            ->willReturn(['id' => 1, 'title' => ['rendered' => ''], 'link' => '', 'status' => 'draft']);

        $this->tool->handle([
            'title' => 'Test',
            'terms' => ['category' => [1, 2], 'post_tag' => [3]],
        ], $client);
    }

    public function testHandlePassesThroughApiError(): void
    {
        $client = $this->createMock(WordPressClient::class);
        $client->method('post')->willReturn([
            'code' => 'rest_invalid_param',
            'message' => 'Invalid parameter(s): title',
        ]);

        $result = $this->tool->handle(['title' => ''], $client);
        $this->assertArrayHasKey('code', $result);
        $this->assertSame('rest_invalid_param', $result['code']);
    }

    public function testHandleDefaultsPostTypeToPosts(): void
    {
        $client = $this->createMock(WordPressClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with('wp/v2/posts', $this->anything())
            ->willReturn(['id' => 1, 'title' => ['rendered' => ''], 'link' => '', 'status' => 'draft']);

        $this->tool->handle(['title' => 'Test'], $client);
    }
}
