<?php

namespace Saltus\WP\Framework\Tests\MCP\Audit;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Audit\AuditEntry;
use Saltus\WP\Framework\MCP\Audit\AuditLogger;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\MCP\Audit\AuditLogger
 */
class AuditLoggerTest extends TestCase
{
    /** @var mixed The shared global this class borrows, put back on teardown. */
    private $original_wpdb;

    protected function setUp(): void
    {
        global $wpdb, $wp_transients;

        // Tests that model a rejected DDL swap in their own failing double. The
        // global is shared with every other test class, so remember what was
        // there and restore it rather than leaving a failing wpdb behind.
        $this->original_wpdb = $wpdb;

        if ( ! is_object( $wpdb ) ) {
            $wpdb = $this->fakeWpdb();
        }
        $wpdb->inserts = [];
        $wpdb->queries = [];

        // ensure_db() skips the DDL while its verification transient is live, so a
        // marker left by an earlier test would make later ones see no CREATE.
        $wp_transients = [];

        // The schema-version option is written by ensure_db() and asserted on by
        // the DDL-failure tests, so it cannot carry over between orderings.
        delete_option('saltus_mcp_audit_db_version');
    }

    protected function tearDown(): void
    {
        global $wpdb, $wp_transients;

        $wpdb          = $this->original_wpdb;
        $wp_transients = [];
        delete_option('saltus_mcp_audit_db_version');
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

    public function testRecordDoesNotRunRetentionCleanup(): void
    {
        global $wpdb;

        $logger = new AuditLogger();
        $entry = new AuditEntry('list_models', []);
        $entry->complete('success');
        $logger->record($entry);

        $delete_queries = array_values(array_filter($wpdb->queries, static fn(string $query): bool => strpos($query, 'DELETE FROM') === 0));

        $this->assertSame([], $delete_queries);
    }

    public function testCleanupExpiredEntriesDeletesOnlyOldAuditRows(): void
    {
        global $wpdb;

        $logger = new AuditLogger();
        $logger->cleanup_expired_entries();

        $delete_queries = array_values(array_filter($wpdb->queries, static fn(string $query): bool => strpos($query, 'DELETE FROM') === 0));

        $this->assertCount(1, $delete_queries);
		$this->assertStringStartsWith("DELETE FROM wp_saltus_mcp_audit WHERE created_at < '", $delete_queries[0]);
    }

    /**
     * An install that has only ever been read from — health endpoint, retention
     * cron — never calls record(), so a read must create the table itself or it
     * queries a table that does not exist and reports zero errors.
     */
    public function testGetRecentEntriesCreatesTableBeforeReading(): void
    {
        global $wpdb;

        (new AuditLogger())->get_recent_entries();

        $this->assertNotSame([], $this->createQueries($wpdb->queries));
    }

    public function testCleanupExpiredEntriesCreatesTableBeforeDeleting(): void
    {
        global $wpdb;

        (new AuditLogger())->cleanup_expired_entries();

        $create_index = $this->firstIndexMatching($wpdb->queries, 'CREATE TABLE IF NOT EXISTS');
        $delete_index = $this->firstIndexMatching($wpdb->queries, 'DELETE FROM');

        $this->assertNotNull($create_index, 'cleanup must ensure the table exists');
        $this->assertNotNull($delete_index);
        $this->assertLessThan($delete_index, $create_index, 'the table must be created before the delete runs');
    }

    /**
     * Creation must not be gated on the stored schema version alone: a table
     * dropped after the option was set would otherwise never come back.
     */
    public function testTableIsRecreatedWhenVersionOptionIsAlreadySet(): void
    {
        global $wpdb;

        update_option('saltus_mcp_audit_db_version', '1.0.0');
        $wpdb->queries = [];

        (new AuditLogger())->get_recent_entries();

        $this->assertNotSame([], $this->createQueries($wpdb->queries));
    }

    /**
     * The DDL is idempotent but not free: once the table is known to exist, a
     * high-traffic site should not send CREATE TABLE from every worker on every
     * request that touches the log.
     */
    public function testDdlIsSkippedWhileVerificationTransientIsLive(): void
    {
        global $wpdb;

        (new AuditLogger())->get_recent_entries();
        $this->assertNotSame([], $this->createQueries($wpdb->queries), 'first read must create the table');

        $wpdb->queries = [];
        (new AuditLogger())->get_recent_entries();

        $this->assertSame([], $this->createQueries($wpdb->queries), 'a later request must reuse the verification');
    }

    /**
     * Bounded, not permanent: a table dropped out from under the marker comes
     * back once the transient lapses, so the log self-heals without the option
     * gate that would suppress recreation forever.
     */
    public function testDdlRunsAgainAfterVerificationExpires(): void
    {
        global $wpdb, $wp_transients;

        (new AuditLogger())->get_recent_entries();
        $wpdb->queries = [];

        $wp_transients = [];
        (new AuditLogger())->get_recent_entries();

        $this->assertNotSame([], $this->createQueries($wpdb->queries));
    }

    /**
     * A failed create must not be recorded as a success. Marking the table
     * verified after rejected DDL suppresses the retry for the whole TTL, and
     * every read in that window queries a missing table and reports zero errors
     * — a broken log indistinguishable from a healthy one.
     */
    public function testFailedDdlIsNotMarkedVerified(): void
    {
        global $wpdb;

        $wpdb = $this->fakeWpdb(false);

        (new AuditLogger())->get_recent_entries();
        $this->assertNotSame([], $this->createQueries($wpdb->queries), 'the first read must attempt the DDL');

        $this->assertFalse(
            get_transient('saltus_mcp_audit_table_verified'),
            'a rejected CREATE must leave no verification marker'
        );

        $wpdb->queries = [];
        (new AuditLogger())->get_recent_entries();

        $this->assertNotSame(
            [],
            $this->createQueries($wpdb->queries),
            'the next request must retry the create rather than trust a failed one'
        );
    }

    /**
     * The schema marker records that this version's table exists. Writing it
     * after a failed create would make a future migration skip a table that was
     * never built.
     */
    public function testFailedDdlDoesNotRecordSchemaVersion(): void
    {
        global $wpdb;

        $wpdb = $this->fakeWpdb(false);

        (new AuditLogger())->get_recent_entries();

        $this->assertFalse(get_option('saltus_mcp_audit_db_version'));
    }

    /**
     * A site that would rather pay the DDL every request can switch the guard
     * off, restoring the immediate self-healing behavior.
     */
    public function testZeroTtlFilterDisablesTheGuard(): void
    {
        global $wpdb, $wp_filter_values;

        $wp_filter_values['saltus/framework/mcp/audit/table_check_ttl'] = 0;

        (new AuditLogger())->get_recent_entries();
        $wpdb->queries = [];
        (new AuditLogger())->get_recent_entries();

        $this->assertNotSame([], $this->createQueries($wpdb->queries));

        $wp_filter_values = [];
    }

    /**
     * @param list<string> $queries
     * @return list<string>
     */
    private function createQueries(array $queries): array
    {
        return array_values(array_filter(
            $queries,
            static fn(string $query): bool => strpos($query, 'CREATE TABLE IF NOT EXISTS') === 0
        ));
    }

    /** @param list<string> $queries */
    private function firstIndexMatching(array $queries, string $prefix): ?int
    {
        foreach ($queries as $index => $query) {
            if (strpos($query, $prefix) === 0) {
                return $index;
            }
        }

        return null;
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

    public function testInvalidStatusFallsBackToError(): void
    {
        global $wpdb;

        $logger = new AuditLogger();
        $entry = new AuditEntry('test_tool', []);
        $entry->complete('bogus_status');
        $logger->record($entry);

        $this->assertSame('error', $wpdb->inserts[0]['data']['status']);
    }

    public function testSanitizesLongAbility(): void
    {
        global $wpdb;

        $logger = new AuditLogger();
        $entry = new AuditEntry(str_repeat('a', 250), []);
        $entry->complete('success');
        $logger->record($entry);

        $this->assertSame(191, strlen($wpdb->inserts[0]['data']['ability']));
    }

    public function testSanitizesLongIdentifier(): void
    {
        global $wpdb;

        $logger = new AuditLogger();

        $entry = new AuditEntry('test', [], str_repeat('x', 250));
        $entry->complete('success');
        $logger->record($entry);

        $this->assertSame(191, strlen($wpdb->inserts[0]['data']['identifier']));
    }

    public function testSanitizesLongErrorCode(): void
    {
        global $wpdb;

        $logger = new AuditLogger();
        $entry = new AuditEntry('test', []);
        $entry->complete('error', str_repeat('e', 250));
        $logger->record($entry);

        $this->assertSame(191, strlen($wpdb->inserts[0]['data']['error_code']));
    }

    public function testStripsNullBytes(): void
    {
        global $wpdb;

        $logger = new AuditLogger();
        $entry = new AuditEntry("bad\x00tool", []);
        $entry->complete('success');
        $logger->record($entry);

        $this->assertSame('badtool', $wpdb->inserts[0]['data']['ability']);
    }

    /**
     * @param bool $query_result What query() reports. False models a server that
     *                           rejected the statement, as wpdb::query() does.
     */
    private function fakeWpdb(bool $query_result = true): object
    {
        return new class($query_result) implements \Saltus\WP\Framework\MCP\Audit\AuditDatabase {
            public string $prefix = 'wp_';
            /** @var list<array<string, mixed>> */
            public array $inserts = [];
            /** @var list<string> */
            public array $queries = [];
            private bool $query_result;

            public function __construct(bool $query_result = true)
            {
                $this->query_result = $query_result;
            }

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
                return $this->query_result;
            }

			public function prepare(string $query, ...$args): string
			{
				foreach ($args as $arg) {
					$replacement = is_string( $arg ) ? "'" . (string) $arg . "'" : (string) $arg;
					$query       = preg_replace( '/%[dsf]/', $replacement, $query, 1 );
				}
				return $query;
			}

            public function get_results(string $query, $output = null)
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
