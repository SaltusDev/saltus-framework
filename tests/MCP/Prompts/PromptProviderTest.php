<?php

namespace Saltus\WP\Framework\Tests\MCP\Prompts;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Prompts\PromptProvider;

class PromptProviderTest extends TestCase
{
    private PromptProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new PromptProvider();
    }

    public function testListReturnsThreePrompts(): void
    {
        $prompts = $this->provider->list();
        $this->assertCount(3, $prompts);
    }

    public function testListHasCreateContent(): void
    {
        $prompts = $this->provider->list();
        $names = array_column($prompts, 'name');
        $this->assertContains('create_content', $names);
    }

    public function testListHasAnalyzeContent(): void
    {
        $prompts = $this->provider->list();
        $names = array_column($prompts, 'name');
        $this->assertContains('analyze_content', $names);
    }

    public function testListHasSiteOverview(): void
    {
        $prompts = $this->provider->list();
        $names = array_column($prompts, 'name');
        $this->assertContains('site_overview', $names);
    }

    public function testGetCreateContentReturnsPrompt(): void
    {
        $result = $this->provider->get('create_content', [
            'post_type' => 'posts',
            'topic'     => 'AI',
            'tone'      => 'professional',
        ]);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('description', $result);
        $this->assertArrayHasKey('messages', $result);
        $this->assertCount(1, $result['messages']);
        $this->assertSame('user', $result['messages'][0]['role']);
    }

    public function testGetCreateContentDefaultTone(): void
    {
        $result = $this->provider->get('create_content', [
            'post_type' => 'posts',
            'topic'     => 'Tech',
        ]);

        $this->assertNotNull($result);
        $this->assertStringContainsString('professional', $result['messages'][0]['content']['text']);
    }

    public function testGetAnalyzeContentReturnsPrompt(): void
    {
        $result = $this->provider->get('analyze_content', ['post_id' => 42]);

        $this->assertNotNull($result);
        $this->assertStringContainsString('42', $result['messages'][0]['content']['text']);
        $this->assertSame('user', $result['messages'][0]['role']);
    }

    public function testGetSiteOverviewReturnsPrompt(): void
    {
        $result = $this->provider->get('site_overview');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('description', $result);
        $this->assertArrayHasKey('messages', $result);
        $this->assertStringContainsString('list_models', $result['messages'][0]['content']['text']);
    }

    public function testGetUnknownPromptReturnsNull(): void
    {
        $result = $this->provider->get('nonexistent_prompt');
        $this->assertNull($result);
    }

    public function testEachPromptHasDescriptionAndMessages(): void
    {
        $prompts = $this->provider->list();
        foreach ($prompts as $prompt) {
            $result = $this->provider->get($prompt['name']);
            $this->assertNotNull($result, "Prompt '{$prompt['name']}' returned null");
            $this->assertNotEmpty($result['description']);
            $this->assertNotEmpty($result['messages']);
        }
    }
}
