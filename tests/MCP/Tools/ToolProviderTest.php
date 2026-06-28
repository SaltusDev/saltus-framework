<?php

namespace Saltus\WP\Framework\Tests\MCP\Tools;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Tools\ToolProvider;
use Saltus\WP\Framework\MCP\Tools\ToolInterface;

class ToolProviderTest extends TestCase
{
    private ToolProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new ToolProvider();
    }

    public function testRegisterAndGet(): void
    {
        $tool = $this->createStub(ToolInterface::class);
        $tool->method('getName')->willReturn('test_tool');

        $this->provider->register($tool);
        $this->assertSame($tool, $this->provider->get('test_tool'));
    }

    public function testGetReturnsNullForUnknown(): void
    {
        $this->assertNull($this->provider->get('nonexistent'));
    }

    public function testAllReturnsAllRegistered(): void
    {
        $tool1 = $this->createStub(ToolInterface::class);
        $tool1->method('getName')->willReturn('tool_a');

        $tool2 = $this->createStub(ToolInterface::class);
        $tool2->method('getName')->willReturn('tool_b');

        $this->provider->register($tool1);
        $this->provider->register($tool2);

        $all = $this->provider->all();
        $this->assertCount(2, $all);
        $this->assertArrayHasKey('tool_a', $all);
        $this->assertArrayHasKey('tool_b', $all);
    }

    public function testGetDefinitions(): void
    {
        $tool = $this->createStub(ToolInterface::class);
        $tool->method('getName')->willReturn('my_tool');
        $tool->method('getDescription')->willReturn('Does something');
        $tool->method('getParameters')->willReturn(['param' => ['type' => 'string']]);

        $this->provider->register($tool);
        $defs = $this->provider->getDefinitions();

        $this->assertCount(1, $defs);
        $this->assertSame('my_tool', $defs[0]['name']);
        $this->assertSame('Does something', $defs[0]['description']);
        $this->assertSame(['param' => ['type' => 'string']], $defs[0]['inputSchema']);
    }

    public function testRegisterOverwritesExisting(): void
    {
        $tool1 = $this->createStub(ToolInterface::class);
        $tool1->method('getName')->willReturn('dup');

        $tool2 = $this->createStub(ToolInterface::class);
        $tool2->method('getName')->willReturn('dup');

        $this->provider->register($tool1);
        $this->provider->register($tool2);

        $this->assertSame($tool2, $this->provider->get('dup'));
    }
}
