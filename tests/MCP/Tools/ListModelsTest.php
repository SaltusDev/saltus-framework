<?php

namespace Saltus\WP\Framework\Tests\MCP\Tools;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Tools\ListModels;
use Saltus\WP\Framework\MCP\Client\WordPressClient;

class ListModelsTest extends TestCase
{
    private ListModels $tool;

    protected function setUp(): void
    {
        $this->tool = new ListModels();
    }

    public function testGetName(): void
    {
        $this->assertSame('list_models', $this->tool->get_name());
    }

    public function testGetDescription(): void
    {
        $this->assertNotEmpty($this->tool->get_description());
    }

    public function testGetParametersHasTypeFilter(): void
    {
        $params = $this->tool->get_parameters();
        $this->assertArrayHasKey('type', $params);
        $this->assertSame('string', $params['type']['type']);
    }

    public function testHandleReturnsPostTypesAndTaxonomiesByDefault(): void
    {
        $client = $this->createMock(WordPressClient::class);
        $client->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $endpoint, array $query = []) {
                return match ($endpoint) {
                    'wp/v2/types' => [
                        'post' => ['name' => 'Posts', 'rest_base' => 'posts', 'public' => true, 'hierarchical' => false, 'description' => ''],
                        'page' => ['name' => 'Pages', 'rest_base' => 'pages', 'public' => true, 'hierarchical' => true, 'description' => ''],
                    ],
                    'wp/v2/taxonomies' => [
                        'category' => ['name' => 'Categories', 'rest_base' => 'categories', 'hierarchical' => true, 'types' => ['post']],
                        'post_tag' => ['name' => 'Tags', 'rest_base' => 'tags', 'hierarchical' => false, 'types' => ['post']],
                    ],
                    default => [],
                };
            });

        $result = $this->tool->handle([], $client);

        $this->assertArrayHasKey('post_types', $result);
        $this->assertArrayHasKey('taxonomies', $result);
        $this->assertCount(2, $result['post_types']);
        $this->assertCount(2, $result['taxonomies']);
    }

    public function testHandleFiltersByPostTypes(): void
    {
        $client = $this->createMock(WordPressClient::class);
        $client->expects($this->once())
            ->method('get')
            ->with('wp/v2/types')
            ->willReturn(['post' => ['name' => 'Posts', 'public' => true]]);

        $result = $this->tool->handle(['type' => 'post_types'], $client);

        $this->assertArrayHasKey('post_types', $result);
        $this->assertArrayNotHasKey('taxonomies', $result);
    }

    public function testHandleFiltersByTaxonomies(): void
    {
        $client = $this->createMock(WordPressClient::class);
        $client->expects($this->once())
            ->method('get')
            ->with('wp/v2/taxonomies')
            ->willReturn(['category' => ['name' => 'Categories', 'rest_base' => 'categories']]);

        $result = $this->tool->handle(['type' => 'taxonomies'], $client);

        $this->assertArrayNotHasKey('post_types', $result);
        $this->assertArrayHasKey('taxonomies', $result);
    }

    public function testHandleSkipsNonArrayEntries(): void
    {
        $client = $this->createStub(WordPressClient::class);
        $client->method('get')->willReturnCallback(function (string $endpoint) {
            return match ($endpoint) {
                'wp/v2/types' => [
                    'valid' => ['name' => 'Valid', 'public' => true],
                    'invalid' => 'string entry',
                ],
                'wp/v2/taxonomies' => [],
                default => [],
            };
        });

        $result = $this->tool->handle([], $client);
        $this->assertCount(1, $result['post_types']);
        $this->assertSame('Valid', $result['post_types'][0]['name']);
    }
}
