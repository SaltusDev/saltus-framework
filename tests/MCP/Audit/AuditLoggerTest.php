<?php

namespace Saltus\WP\Framework\Tests\MCP\Audit;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Audit\AuditEntry;
use Saltus\WP\Framework\MCP\Audit\AuditLogger;

class AuditLoggerTest extends TestCase
{
    public function testRecordStoresEntryInMemory(): void
    {
        $logger = new AuditLogger(true, false);
        $entry = new AuditEntry('list_models', []);
        $entry->complete('success');
        $logger->record($entry);

        $stats = $logger->get_stats();
        $this->assertSame(1, $stats['total']);
        $this->assertSame(0, $stats['errors']);
    }

    public function testRecordCountsErrors(): void
    {
        $logger = new AuditLogger(true, false);

        $success = new AuditEntry('good', []);
        $success->complete('success');
        $logger->record($success);

        $fail = new AuditEntry('bad', []);
        $fail->complete('error', 'api_error', 'fail');
        $logger->record($fail);

        $stats = $logger->get_stats();
        $this->assertSame(2, $stats['total']);
        $this->assertSame(1, $stats['errors']);
    }

    public function testDisabledLoggerDoesNotStore(): void
    {
        $logger = new AuditLogger(false, false);
        $entry = new AuditEntry('test', []);
        $entry->complete('success');
        $logger->record($entry);

        $this->assertSame(0, $logger->get_stats()['total']);
    }

    public function testGetRecentEntriesReturnsLatest(): void
    {
        $logger = new AuditLogger(true, false);

        for ($i = 0; $i < 5; $i++) {
            $e = new AuditEntry("tool_{$i}", []);
            $e->complete('success');
            $logger->record($e);
        }

        $recent = $logger->get_recent_entries(2);
        $this->assertCount(2, $recent);
        $this->assertSame('tool_3', $recent[0]['tool']);
        $this->assertSame('tool_4', $recent[1]['tool']);
    }

    public function testMaxMemoryEntriesRespected(): void
    {
        $logger = new AuditLogger(true, false, null, 3);

        for ($i = 0; $i < 10; $i++) {
            $e = new AuditEntry("tool_{$i}", []);
            $e->complete('success');
            $logger->record($e);
        }

        $stats = $logger->get_stats();
        $this->assertSame(3, $stats['total']);
    }

    public function testLogToFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'audit_');
        $logger = new AuditLogger(true, false, $tmpFile);

        $entry = new AuditEntry('list_posts', ['per_page' => 5]);
        $entry->complete('success');
        $logger->record($entry);

        $contents = file_get_contents($tmpFile);
        $this->assertNotFalse($contents);

        $decoded = json_decode(trim($contents), true);
        $this->assertIsArray($decoded);
        $this->assertSame('list_posts', $decoded['tool']);
        $this->assertSame('success', $decoded['status']);

        unlink($tmpFile);
    }

    public function testGetStatsShape(): void
    {
        $logger = new AuditLogger(true, false);

        $e = new AuditEntry('test', []);
        $e->complete('success');
        $logger->record($e);

        $stats = $logger->get_stats();
        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('errors', $stats);
        $this->assertArrayHasKey('recent', $stats);
        $this->assertCount(1, $stats['recent']);
    }
}
