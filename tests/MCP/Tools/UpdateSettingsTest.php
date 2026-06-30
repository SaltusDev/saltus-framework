<?php

namespace Saltus\WP\Framework\Tests\MCP\Tools;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Tools\UpdateSettings;
use Saltus\WP\Framework\MCP\Client\WordPressClient;

class UpdateSettingsTest extends TestCase
{
    private UpdateSettings $tool;

    protected function setUp(): void
    {
        $this->tool = new UpdateSettings();
    }

    public function testGetName(): void
    {
        $this->assertSame('update_settings', $this->tool->getName());
    }

    public function testGetDescription(): void
    {
        $this->assertNotEmpty($this->tool->getDescription());
    }

    public function testGetParametersHasRequiredFields(): void
    {
        $params = $this->tool->getParameters();
        $this->assertArrayHasKey('post_type', $params);
        $this->assertTrue($params['post_type']['required']);
        $this->assertArrayHasKey('settings', $params);
        $this->assertTrue($params['settings']['required']);
    }

    public function testHandleUpdatesSettingsSuccessfully(): void
    {
        $client = $this->createMock(WordPressClient::class);
        $client->expects($this->once())
            ->method('put')
            ->with('saltus-framework/v1/settings/book', ['show_author' => 'yes'])
            ->willReturn([
                'post_type' => 'book',
                'settings' => ['show_author' => 'yes'],
                'status' => 'updated',
            ]);

        $result = $this->tool->handle([
            'post_type' => 'book',
            'settings' => ['show_author' => 'yes'],
        ], $client);

        $this->assertSame('book', $result['post_type']);
        $this->assertSame('updated', $result['status']);
    }

    public function testHandleMissingPostTypeReturnsError(): void
    {
        $client = $this->createStub(WordPressClient::class);
        $result = $this->tool->handle(['settings' => ['key' => 'val']], $client);

        $this->assertArrayHasKey('code', $result);
        $this->assertSame('invalid_params', $result['code']);
    }

    public function testHandleEmptySettingsReturnsError(): void
    {
        $client = $this->createStub(WordPressClient::class);
        $result = $this->tool->handle(['post_type' => 'book', 'settings' => []], $client);

        $this->assertArrayHasKey('code', $result);
        $this->assertSame('invalid_params', $result['code']);
    }

    public function testHandlePassesThroughApiError(): void
    {
        $client = $this->createStub(WordPressClient::class);
        $client->method('put')->willReturn([
            'code' => 'rest_forbidden',
            'message' => 'You do not have permission.',
        ]);

        $result = $this->tool->handle([
            'post_type' => 'book',
            'settings' => ['key' => 'val'],
        ], $client);

        $this->assertArrayHasKey('code', $result);
        $this->assertSame('rest_forbidden', $result['code']);
    }
}
