<?php

namespace Saltus\WP\Framework\Tests\MCP\Resources;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Resources\ResourceProvider;

class ResourceProviderTest extends TestCase
{
    private ResourceProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new ResourceProvider();
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

    public function testResolveModelsReturnsContent(): void
    {
        $result = $this->provider->resolve('saltus://models');
        $this->assertNotNull($result);
        $this->assertArrayHasKey('contents', $result);
        $this->assertCount(1, $result['contents']);
        $this->assertSame('saltus://models', $result['contents'][0]['uri']);
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
