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
        global $wpdb, $wp_transients, $wp_filter_values;

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

        // Sampling and TTL are filter-driven, so a value left behind by one test
        // silently changes what the next one measures.
        $wp_filter_values = [];

        // The schema-version option is written by ensure_db() and asserted on by
        // the DDL-failure tests, so it cannot carry over between orderings.
        delete_option('saltus_mcp_audit_db_version');
    }

    protected function tearDown(): void
    {
        global $wpdb, $wp_transients, $wp_filter_values;

        $wpdb             = $this->original_wpdb;
        $wp_transients    = [];
        $wp_filter_values = [];
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

        // Phase 14: cleanup now deletes from two tables (normal audit + slow-call).
        $this->assertCount(2, $delete_queries);
        $this->assertStringStartsWith("DELETE FROM wp_saltus_mcp_audit WHERE created_at < '", $delete_queries[0]);
        $this->assertStringStartsWith("DELETE FROM wp_saltus_mcp_audit_slow_calls WHERE created_at < '", $delete_queries[1]);
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
     * The draw has to land in 0..1. It was `wp_rand() / getrandmax()`, whose two
     * bounds are unrelated — the result sat above 1.0 on all but a vanishing
     * fraction of draws, so every rate below 1.0 discarded very nearly
     * everything while the rate persisted with each rollup claimed otherwise.
     *
     * The band is deliberately loose: the point is a draw that never falls under
     * the rate, not the shape of the distribution. At 2000 entries and p = 0.5
     * the standard deviation is ~22, so these bounds are some 30 sigma out —
     * unreachable by chance, and comfortably failed by an unbounded draw.
     */
    public function testHalfRateKeepsRoughlyHalfTheEntries(): void
    {
        global $wpdb, $wp_filter_values;

        $wp_filter_values['saltus/framework/mcp/audit/sample_rate'] = 0.5;

        $this->recordEntries(2000);

        $kept = count($wpdb->inserts);

        $this->assertGreaterThan(300, $kept, 'the sampling draw must fall below the rate sometimes');
        $this->assertLessThan(1700, $kept);
    }

    public function testFullRateRecordsEverything(): void
    {
        global $wpdb, $wp_filter_values;

        $wp_filter_values['saltus/framework/mcp/audit/sample_rate'] = 1.0;

        $this->recordEntries(25);

        $this->assertCount(25, $wpdb->inserts);
    }

    public function testZeroRateRecordsNothing(): void
    {
        global $wpdb, $wp_filter_values;

        $wp_filter_values['saltus/framework/mcp/audit/sample_rate'] = 0.0;

        $this->recordEntries(25);

        $this->assertSame([], $wpdb->inserts);
    }

    public function testADrawUnderTheRateIsKept(): void
    {
        global $wpdb, $wp_filter_values;

        $wp_filter_values['saltus/framework/mcp/audit/sample_rate']  = 0.5;
        $wp_filter_values['saltus/framework/mcp/audit/sample_value'] = 0.2;

        $this->recordEntries(3);

        $this->assertCount(3, $wpdb->inserts);
    }

    public function testADrawOverTheRateIsDropped(): void
    {
        global $wpdb, $wp_filter_values;

        $wp_filter_values['saltus/framework/mcp/audit/sample_rate']  = 0.5;
        $wp_filter_values['saltus/framework/mcp/audit/sample_value'] = 0.8;

        $this->recordEntries(3);

        $this->assertSame([], $wpdb->inserts);
    }

    /**
     * Error visibility must not depend on the sampling rate: a site sampling at
     * a low rate to control table growth still needs every failure.
     */
    public function testFailuresBypassSamplingEntirely(): void
    {
        global $wpdb, $wp_filter_values;

        $wp_filter_values['saltus/framework/mcp/audit/sample_rate']  = 0.0;
        $wp_filter_values['saltus/framework/mcp/audit/sample_value'] = 1.0;

        $logger = new AuditLogger();

        foreach (['error', 'exception'] as $status) {
            $entry = new AuditEntry('failing', []);
            $entry->complete($status);
            $logger->record($entry);
        }

        $this->assertCount(2, $wpdb->inserts);
    }

    /** Record $count completed successes through one logger. */
    private function recordEntries(int $count): void
    {
        $logger = new AuditLogger();

        for ($i = 0; $i < $count; $i++) {
            $entry = new AuditEntry('list_models', []);
            $entry->complete('success');
            $logger->record($entry);
        }
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
