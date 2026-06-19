<?php

namespace Saltus\WP\Framework\Tests\MCP\Tools;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Tools\DuplicatePost;
use Saltus\WP\Framework\MCP\Client\WordPressClient;

class DuplicatePostTest extends TestCase
{
    private DuplicatePost $tool;

    protected function setUp(): void
    {
        $this->tool = new DuplicatePost();
    }

    public function testGetName(): void
    {
        $this->assertSame('duplicate_post', $this->tool->getName());
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

    public function testHandleDuplicatesPostSuccessfully(): void
    {
        $client = $this->createMock(WordPressClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with('saltus-framework/v1/duplicate/42')
            ->willReturn([
                'id' => 43,
                'post_type' => 'post',
                'post_title' => 'Test Post (Copy)',
                'post_status' => 'draft',
                'edit_link' => 'http://example.com/wp-admin/post.php?action=edit&post=43',
            ]);

        $result = $this->tool->handle(['post_id' => 42], $client);

        $this->assertSame(43, $result['id']);
        $this->assertSame('Test Post (Copy)', $result['title']);
        $this->assertSame('draft', $result['status']);
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
        $client->method('post')->willReturn([
            'code' => 'post_not_found',
            'message' => 'Post not found.',
        ]);

        $result = $this->tool->handle(['post_id' => 999], $client);

        $this->assertArrayHasKey('code', $result);
        $this->assertSame('post_not_found', $result['code']);
    }
}
