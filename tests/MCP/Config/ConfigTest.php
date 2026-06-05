<?php

namespace Saltus\WP\Framework\Tests\MCP\Config;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Config\Config;

class ConfigTest extends TestCase
{
    public function testConstructorTrimsTrailingSlash(): void
    {
        $config = new Config('https://example.com/', 'user', 'pass');
        $this->assertSame('https://example.com', $config->getSiteUrl());
    }

    public function testConstructorKeepsUrlWithoutTrailingSlash(): void
    {
        $config = new Config('https://example.com', 'user', 'pass');
        $this->assertSame('https://example.com', $config->getSiteUrl());
    }

    public function testGetApiUrlAppendsWpJson(): void
    {
        $config = new Config('https://example.com', 'user', 'pass');
        $this->assertSame('https://example.com/wp-json/', $config->getApiUrl());
    }

    public function testGetUsername(): void
    {
        $config = new Config('https://example.com', 'testuser', 'secret');
        $this->assertSame('testuser', $config->getUsername());
    }

    public function testGetPassword(): void
    {
        $config = new Config('https://example.com', 'user', 'secret123');
        $this->assertSame('secret123', $config->getPassword());
    }

    public function testToArray(): void
    {
        $config = new Config('https://example.com', 'user', 'pass');
        $expected = [
            'site_url' => 'https://example.com',
            'username' => 'user',
            'password' => 'pass',
        ];
        $this->assertSame($expected, $config->toArray());
    }

    public function testFromArray(): void
    {
        $data = [
            'site_url' => 'https://example.com',
            'username' => 'admin',
            'password' => 'hunter2',
        ];
        $config = Config::fromArray($data);
        $this->assertSame('https://example.com', $config->getSiteUrl());
        $this->assertSame('admin', $config->getUsername());
        $this->assertSame('hunter2', $config->getPassword());
    }

    public function testFromArrayWithTrailingSlash(): void
    {
        $data = [
            'site_url' => 'https://example.com/',
            'username' => 'u',
            'password' => 'p',
        ];
        $config = Config::fromArray($data);
        $this->assertSame('https://example.com', $config->getSiteUrl());
    }

    public function testFromArrayWithMissingFields(): void
    {
        $data = [];
        $config = Config::fromArray($data);
        $this->assertSame('', $config->getSiteUrl());
        $this->assertSame('', $config->getUsername());
        $this->assertSame('', $config->getPassword());
    }
}
