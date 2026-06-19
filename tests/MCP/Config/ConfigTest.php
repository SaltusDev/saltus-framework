<?php

namespace Saltus\WP\Framework\Tests\MCP\Config;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Config\Config;

class ConfigTest extends TestCase
{
    public function testConstructorTrimsTrailingSlash(): void
    {
        $config = new Config(['site_url' => 'https://example.com/', 'username' => 'user', 'password' => 'pass']);
        $this->assertSame('https://example.com', $config->getSiteUrl());
    }

    public function testConstructorKeepsUrlWithoutTrailingSlash(): void
    {
        $config = new Config(['site_url' => 'https://example.com', 'username' => 'user', 'password' => 'pass']);
        $this->assertSame('https://example.com', $config->getSiteUrl());
    }

    public function testGetApiUrlAppendsWpJson(): void
    {
        $config = new Config(['site_url' => 'https://example.com', 'username' => 'user', 'password' => 'pass']);
        $this->assertSame('https://example.com/wp-json/', $config->getApiUrl());
    }

    public function testGetUsername(): void
    {
        $config = new Config(['site_url' => 'https://example.com', 'username' => 'testuser', 'password' => 'secret']);
        $this->assertSame('testuser', $config->getUsername());
    }

    public function testGetPassword(): void
    {
        $config = new Config(['site_url' => 'https://example.com', 'username' => 'user', 'password' => 'secret123']);
        $this->assertSame('secret123', $config->getPassword());
    }

    public function testToArray(): void
    {
        $config = new Config(['site_url' => 'https://example.com', 'username' => 'user', 'password' => 'pass']);
        $expected = [
            'cache_enabled' => true,
            'cache_ttl' => 300,
            'cache_ttl_models' => 600,
            'rate_limit_enabled' => true,
            'rate_limit_max' => 60,
            'rate_limit_window' => 60,
            'audit_enabled' => true,
            'audit_log_file' => null,
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
        $this->assertTrue($config->isCacheEnabled());
        $this->assertSame(300, $config->getCacheTtl());
        $this->assertSame(600, $config->getCacheTtlModels());
        $this->assertTrue($config->isRateLimitEnabled());
        $this->assertSame(60, $config->getRateLimitMax());
        $this->assertSame(60, $config->getRateLimitWindow());
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
        $this->assertTrue($config->isCacheEnabled());
        $this->assertSame(300, $config->getCacheTtl());
        $this->assertTrue($config->isRateLimitEnabled());
        $this->assertSame(60, $config->getRateLimitMax());
    }

    public function testConstructorDefaults(): void
    {
        $config = new Config(['site_url' => 'https://example.com', 'username' => 'u', 'password' => 'p']);
        $this->assertTrue($config->isCacheEnabled());
        $this->assertSame(300, $config->getCacheTtl());
        $this->assertSame(600, $config->getCacheTtlModels());
        $this->assertTrue($config->isRateLimitEnabled());
        $this->assertSame(60, $config->getRateLimitMax());
        $this->assertSame(60, $config->getRateLimitWindow());
        $this->assertTrue($config->isAuditEnabled());
        $this->assertNull($config->getAuditLogFile());
    }

    public function testConstructorCustomValues(): void
    {
        $config = new Config([
            'site_url' => 'https://example.com',
            'username' => 'u',
            'password' => 'p',
            'cache_enabled' => false,
            'cache_ttl' => 120,
            'cache_ttl_models' => 300,
            'rate_limit_enabled' => false,
            'rate_limit_max' => 10,
            'rate_limit_window' => 30,
            'audit_enabled' => false,
            'audit_log_file' => '/tmp/audit.log',
        ]);
        $this->assertFalse($config->isCacheEnabled());
        $this->assertSame(120, $config->getCacheTtl());
        $this->assertSame(300, $config->getCacheTtlModels());
        $this->assertFalse($config->isRateLimitEnabled());
        $this->assertSame(10, $config->getRateLimitMax());
        $this->assertSame(30, $config->getRateLimitWindow());
        $this->assertFalse($config->isAuditEnabled());
        $this->assertSame('/tmp/audit.log', $config->getAuditLogFile());
    }

    public function testFromArrayCustomValues(): void
    {
        $data = [
            'site_url' => 'https://example.com',
            'username' => 'u',
            'password' => 'p',
            'cache_enabled' => false,
            'cache_ttl' => 60,
            'cache_ttl_models' => 120,
            'rate_limit_enabled' => false,
            'rate_limit_max' => 100,
            'rate_limit_window' => 30,
            'audit_enabled' => false,
            'audit_log_file' => '/tmp/custom_audit.log',
        ];
        $config = Config::fromArray($data);
        $this->assertFalse($config->isCacheEnabled());
        $this->assertSame(60, $config->getCacheTtl());
        $this->assertSame(120, $config->getCacheTtlModels());
        $this->assertFalse($config->isRateLimitEnabled());
        $this->assertSame(100, $config->getRateLimitMax());
        $this->assertSame(30, $config->getRateLimitWindow());
        $this->assertFalse($config->isAuditEnabled());
        $this->assertSame('/tmp/custom_audit.log', $config->getAuditLogFile());
    }
}
