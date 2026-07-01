<?php

namespace Saltus\WP\Framework\Tests\MCP\Audit;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Audit\AuditEntry;
use Saltus\WP\Framework\MCP\Audit\AuditLogger;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

class AuditLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        global $wpdb;
        if ( ! is_object( $wpdb ) ) {
            $wpdb = $this->fakeWpdb();
        }
        $wpdb->inserts = [];
        $wpdb->queries = [];
    }

    public function testRecordStoresEntryInAuditTable(): void
    {
        global $wpdb;

        $logger = new AuditLogger();
        $entry = new AuditEntry('list_models', []);
        $entry->complete('success');
        $logger->record($entry);

        $this->assertCount(1, $wpdb->inserts);
        $this->assertSame('wp_saltus_mcp_audit', $wpdb->inserts[0]['table']);
        $this->assertSame('list_models', $wpdb->inserts[0]['data']['ability']);
        $this->assertSame('success', $wpdb->inserts[0]['data']['status']);
    }

    public function testRecordStoresErrors(): void
    {
        global $wpdb;

        $logger = new AuditLogger();
        $fail = new AuditEntry('bad', []);
        $fail->complete('error', 'api_error', 'fail');
        $logger->record($fail);

        $this->assertSame('api_error', $wpdb->inserts[0]['data']['error_code']);
        $this->assertSame('fail', $wpdb->inserts[0]['data']['error_message']);
    }

    public function testGetRecentEntriesReadsFromTable(): void
    {
        $logger = new AuditLogger();

        for ($i = 0; $i < 5; $i++) {
            $e = new AuditEntry("tool_{$i}", []);
            $e->complete('success');
            $logger->record($e);
        }

        $recent = $logger->get_recent_entries(2);
        $this->assertNotEmpty($recent);
        $this->assertSame('tool_4', $recent[0]['ability']);
    }

    private function fakeWpdb(): object
    {
        return new class implements \Saltus\WP\Framework\MCP\Audit\AuditDatabase {
            public string $prefix = 'wp_';
            /** @var list<array<string, mixed>> */
            public array $inserts = [];
            /** @var list<string> */
            public array $queries = [];

            public function prefix(): string
            {
                return $this->prefix;
            }

            /**
             * @param array<string, mixed> $data
             * @param list<string> $format
             */
            public function insert(string $table, array $data, array $format = []): bool
            {
                $this->inserts[] = compact('table', 'data', 'format');
                return true;
            }

            public function query(string $query): bool
            {
                $this->queries[] = $query;
                return true;
            }

            public function prepare(string $query, mixed ...$args): string
            {
                foreach ($args as $arg) {
                    $query = preg_replace('/%[dsf]/', (string) $arg, $query, 1);
                }
                return $query;
            }

            public function get_results(string $query, mixed $output = null): array
            {
                return array_reverse(array_map(fn(array $insert) => $insert['data'], $this->inserts));
            }

            public function get_charset_collate(): string
            {
                return '';
            }
        };
    }
}
