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
        $tool->method('get_name')->willReturn('test_tool');

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
        $tool1->method('get_name')->willReturn('tool_a');

        $tool2 = $this->createStub(ToolInterface::class);
        $tool2->method('get_name')->willReturn('tool_b');

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
        $tool->method('get_name')->willReturn('my_tool');
        $tool->method('get_description')->willReturn('Does something');
        $tool->method('get_parameters')->willReturn(['param' => ['type' => 'string']]);

        $this->provider->register($tool);
        $defs = $this->provider->get_definitions();

        $this->assertCount(1, $defs);
        $this->assertSame('my_tool', $defs[0]['name']);
        $this->assertSame('Does something', $defs[0]['description']);
        $this->assertSame(['param' => ['type' => 'string']], $defs[0]['inputSchema']);
    }

    public function testRegisterOverwritesExisting(): void
    {
        $tool1 = $this->createStub(ToolInterface::class);
        $tool1->method('get_name')->willReturn('dup');

        $tool2 = $this->createStub(ToolInterface::class);
        $tool2->method('get_name')->willReturn('dup');

        $this->provider->register($tool1);
        $this->provider->register($tool2);

        $this->assertSame($tool2, $this->provider->get('dup'));
    }
}
