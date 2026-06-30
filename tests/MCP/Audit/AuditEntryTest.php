<?php

namespace Saltus\WP\Framework\Tests\MCP\Audit;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Audit\AuditEntry;

class AuditEntryTest extends TestCase
{
    public function testConstructorSetsToolNameAndArguments(): void
    {
        $entry = new AuditEntry('list_models', ['type' => 'all']);
        $arr = $entry->to_array();

        $this->assertSame('list_models', $arr['tool']);
        $this->assertSame(['type' => 'all'], $arr['arguments']);
    }

    public function testInitialStatusIsStarted(): void
    {
        $entry = new AuditEntry('get_post', ['id' => 1]);
        $arr = $entry->to_array();

        $this->assertSame('started', $arr['status']);
        $this->assertNull($arr['duration_ms']);
    }

    public function testCompleteSetsStatusAndDuration(): void
    {
        $entry = new AuditEntry('create_post', ['title' => 'Test']);
        usleep(1000);
        $entry->complete('success');

        $arr = $entry->to_array();

        $this->assertSame('success', $arr['status']);
        $this->assertNotNull($arr['duration_ms']);
        $this->assertGreaterThan(0, $arr['duration_ms']);
        $this->assertNull($arr['error_code']);
        $this->assertNull($arr['error_message']);
    }

    public function testCompleteWithError(): void
    {
        $entry = new AuditEntry('delete_post', ['id' => 99]);
        $entry->complete('error', 'rest_forbidden', 'You cannot delete this post');

        $arr = $entry->to_array();

        $this->assertSame('error', $arr['status']);
        $this->assertSame('rest_forbidden', $arr['error_code']);
        $this->assertSame('You cannot delete this post', $arr['error_message']);
    }

    public function testTimestampFormat(): void
    {
        $entry = new AuditEntry('list_posts', []);
        $arr = $entry->to_array();

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $arr['timestamp']
        );
    }

    public function testMultipleCompletesOverwrite(): void
    {
        $entry = new AuditEntry('test', []);
        $entry->complete('error', 'e1', 'first');
        $entry->complete('success');

        $arr = $entry->to_array();
        $this->assertSame('success', $arr['status']);
        $this->assertNull($arr['error_code']);
        $this->assertNull($arr['error_message']);
    }

    public function testGetDurationBeforeComplete(): void
    {
        $entry = new AuditEntry('test', []);
        $this->assertNull($entry->get_duration());
    }
}
