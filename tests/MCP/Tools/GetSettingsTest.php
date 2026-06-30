<?php

namespace Saltus\WP\Framework\Tests\MCP\Tools;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Tools\GetSettings;
use Saltus\WP\Framework\MCP\Client\WordPressClient;

class GetSettingsTest extends TestCase
{
    private GetSettings $tool;

    protected function setUp(): void
    {
        $this->tool = new GetSettings();
    }

    public function testGetName(): void
    {
        $this->assertSame('get_settings', $this->tool->getName());
    }

    public function testGetDescription(): void
    {
        $this->assertNotEmpty($this->tool->getDescription());
    }

    public function testGetParametersHasRequiredPostType(): void
    {
        $params = $this->tool->getParameters();
        $this->assertArrayHasKey('post_type', $params);
        $this->assertTrue($params['post_type']['required']);
    }

    public function testHandleGetsSettingsSuccessfully(): void
    {
        $client = $this->createMock(WordPressClient::class);
        $client->expects($this->once())
            ->method('get')
            ->with('saltus-framework/v1/settings/book')
            ->willReturn([
                'post_type' => 'book',
                'settings' => ['show_author' => 'yes', 'enable_reviews' => 'no'],
            ]);

        $result = $this->tool->handle(['post_type' => 'book'], $client);

        $this->assertSame('book', $result['post_type']);
        $this->assertSame(['show_author' => 'yes', 'enable_reviews' => 'no'], $result['settings']);
    }

    public function testHandleMissingPostTypeReturnsError(): void
    {
        $client = $this->createStub(WordPressClient::class);
        $result = $this->tool->handle([], $client);

        $this->assertArrayHasKey('code', $result);
        $this->assertSame('invalid_params', $result['code']);
    }

    public function testHandlePassesThroughApiError(): void
    {
        $client = $this->createStub(WordPressClient::class);
        $client->method('get')->willReturn([
            'code' => 'rest_forbidden',
            'message' => 'You do not have permission.',
        ]);

        $result = $this->tool->handle(['post_type' => 'book'], $client);

        $this->assertArrayHasKey('code', $result);
        $this->assertSame('rest_forbidden', $result['code']);
    }
}
