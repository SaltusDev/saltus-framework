<?php

namespace Saltus\WP\Framework\Tests\MCP\Resources;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Resources\ResourceProvider;
use Saltus\WP\Framework\MCP\Client\WordPressClient;

class ResourceProviderTest extends TestCase
{
    private ResourceProvider $provider;
    private WordPressClient $client;

    protected function setUp(): void
    {
        $this->client = $this->createMock(WordPressClient::class);
        $this->provider = new ResourceProvider($this->client);
    }

    public function testGetDefinitionsReturnsThree(): void
    {
        $definitions = $this->provider->getDefinitions();
        $this->assertCount(3, $definitions);
    }

    public function testGetDefinitionsContainExpectedUris(): void
    {
        $definitions = $this->provider->getDefinitions();
        $uris = array_map(fn ($d) => $d['uri'], $definitions);
        $this->assertContains('saltus://models', $uris);
        $this->assertContains('saltus://features', $uris);
        $this->assertContains('saltus://status', $uris);
    }

    public function testGetDefinitionsHaveRequiredFields(): void
    {
        foreach ($this->provider->getDefinitions() as $def) {
            $this->assertArrayHasKey('uri', $def);
            $this->assertArrayHasKey('name', $def);
            $this->assertArrayHasKey('description', $def);
            $this->assertArrayHasKey('mimeType', $def);
        }
    }

    public function testResolveModelsReturnsLiveData(): void
    {
        $this->client->expects($this->once())
            ->method('get')
            ->with('saltus-framework/v1/models')
            ->willReturn([
                ['name' => 'book', 'type' => 'post_type', 'label_singular' => 'Book'],
                ['name' => 'author', 'type' => 'taxonomy', 'label_singular' => 'Author'],
            ]);

        $result = $this->provider->resolve('saltus://models');
        $this->assertNotNull($result);
        $this->assertArrayHasKey('contents', $result);
        $this->assertCount(1, $result['contents']);
        $this->assertSame('saltus://models', $result['contents'][0]['uri']);

        $text = $result['contents'][0]['text'];
        $decoded = json_decode($text, true);
        $this->assertCount(2, $decoded);
        $this->assertSame('book', $decoded[0]['name']);
    }

    public function testResolveModelsHandlesApiError(): void
    {
        $this->client->expects($this->once())
            ->method('get')
            ->with('saltus-framework/v1/models')
            ->willReturn(['code' => 'rest_forbidden', 'message' => 'Forbidden']);

        $result = $this->provider->resolve('saltus://models');
        $this->assertNotNull($result);

        $text = $result['contents'][0]['text'];
        $decoded = json_decode($text, true);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertSame('Forbidden', $decoded['error']);
    }

    public function testResolveFeaturesReturnsContent(): void
    {
        $result = $this->provider->resolve('saltus://features');
        $this->assertNotNull($result);
        $this->assertArrayHasKey('contents', $result);
        $text = $result['contents'][0]['text'];
        $decoded = json_decode($text, true);
        $this->assertArrayHasKey('available_features', $decoded);
    }

    public function testResolveStatusReturnsContent(): void
    {
        $result = $this->provider->resolve('saltus://status');
        $this->assertNotNull($result);
        $this->assertArrayHasKey('contents', $result);
        $text = $result['contents'][0]['text'];
        $decoded = json_decode($text, true);
        $this->assertSame('Saltus Framework', $decoded['framework']);
    }

    public function testResolveUnknownUriReturnsNull(): void
    {
        $this->assertNull($this->provider->resolve('saltus://unknown'));
    }
}
