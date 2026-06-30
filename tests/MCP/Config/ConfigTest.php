<?php

namespace Saltus\WP\Framework\Tests\MCP\Config;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Config\Config;

class ConfigTest extends TestCase
{
    public function testConstructorTrimsTrailingSlash(): void
    {
        $config = new Config(['site_url' => 'https://example.com/', 'username' => 'user', 'password' => 'pass']);
        $this->assertSame('https://example.com', $config->get_site_url());
    }

    public function testConstructorKeepsUrlWithoutTrailingSlash(): void
    {
        $config = new Config(['site_url' => 'https://example.com', 'username' => 'user', 'password' => 'pass']);
        $this->assertSame('https://example.com', $config->get_site_url());
    }

    public function testGetApiUrlAppendsWpJson(): void
    {
        $config = new Config(['site_url' => 'https://example.com', 'username' => 'user', 'password' => 'pass']);
        $this->assertSame('https://example.com/wp-json/', $config->get_api_url());
    }

    public function testGetUsername(): void
    {
        $config = new Config(['site_url' => 'https://example.com', 'username' => 'testuser', 'password' => 'secret']);
        $this->assertSame('testuser', $config->get_username());
    }

    public function testGetPassword(): void
    {
        $config = new Config(['site_url' => 'https://example.com', 'username' => 'user', 'password' => 'secret123']);
        $this->assertSame('secret123', $config->get_password());
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
        $this->assertSame($expected, $config->to_array());
    }

    public function testFromArray(): void
    {
        $data = [
            'site_url' => 'https://example.com',
            'username' => 'admin',
            'password' => 'hunter2',
        ];
        $config = Config::from_array($data);
        $this->assertSame('https://example.com', $config->get_site_url());
        $this->assertSame('admin', $config->get_username());
        $this->assertSame('hunter2', $config->get_password());
        $this->assertTrue($config->is_cache_enabled());
        $this->assertSame(300, $config->get_cache_ttl());
        $this->assertSame(600, $config->get_cache_ttl_models());
        $this->assertTrue($config->is_rate_limit_enabled());
        $this->assertSame(60, $config->get_rate_limit_max());
        $this->assertSame(60, $config->get_rate_limit_window());
    }

    public function testFromArrayWithTrailingSlash(): void
    {
        $data = [
            'site_url' => 'https://example.com/',
            'username' => 'u',
            'password' => 'p',
        ];
        $config = Config::from_array($data);
        $this->assertSame('https://example.com', $config->get_site_url());
    }

    public function testFromArrayWithMissingFields(): void
    {
        $data = [];
        $config = Config::from_array($data);
        $this->assertSame('', $config->get_site_url());
        $this->assertSame('', $config->get_username());
        $this->assertSame('', $config->get_password());
        $this->assertTrue($config->is_cache_enabled());
        $this->assertSame(300, $config->get_cache_ttl());
        $this->assertTrue($config->is_rate_limit_enabled());
        $this->assertSame(60, $config->get_rate_limit_max());
    }

    public function testConstructorDefaults(): void
    {
        $config = new Config(['site_url' => 'https://example.com', 'username' => 'u', 'password' => 'p']);
        $this->assertTrue($config->is_cache_enabled());
        $this->assertSame(300, $config->get_cache_ttl());
        $this->assertSame(600, $config->get_cache_ttl_models());
        $this->assertTrue($config->is_rate_limit_enabled());
        $this->assertSame(60, $config->get_rate_limit_max());
        $this->assertSame(60, $config->get_rate_limit_window());
        $this->assertTrue($config->is_audit_enabled());
        $this->assertNull($config->get_audit_log_file());
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
        $this->assertFalse($config->is_cache_enabled());
        $this->assertSame(120, $config->get_cache_ttl());
        $this->assertSame(300, $config->get_cache_ttl_models());
        $this->assertFalse($config->is_rate_limit_enabled());
        $this->assertSame(10, $config->get_rate_limit_max());
        $this->assertSame(30, $config->get_rate_limit_window());
        $this->assertFalse($config->is_audit_enabled());
        $this->assertSame('/tmp/audit.log', $config->get_audit_log_file());
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
        $config = Config::from_array($data);
        $this->assertFalse($config->is_cache_enabled());
        $this->assertSame(60, $config->get_cache_ttl());
        $this->assertSame(120, $config->get_cache_ttl_models());
        $this->assertFalse($config->is_rate_limit_enabled());
        $this->assertSame(100, $config->get_rate_limit_max());
        $this->assertSame(30, $config->get_rate_limit_window());
        $this->assertFalse($config->is_audit_enabled());
        $this->assertSame('/tmp/custom_audit.log', $config->get_audit_log_file());
    }
}
