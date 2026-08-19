<?php

namespace Saltus\WP\Framework\Tests\MCP\Audit;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Audit\AuditEntry;
use Saltus\WP\Framework\MCP\Audit\AuditLogger;
use Saltus\WP\Framework\MCP\Audit\RollupStore;

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
        global $wpdb, $wp_transients, $wp_filter_values, $wp_actions_fired;

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

        // The error action is asserted by tag, so a hook fired by an earlier test
        // would be read as this one's.
        $wp_actions_fired = [];

        // The schema-version option is written by ensure_db() and asserted on by
        // the DDL-failure tests, so it cannot carry over between orderings.
        delete_option('saltus_mcp_audit_db_version');
    }

    protected function tearDown(): void
    {
        global $wpdb, $wp_transients, $wp_filter_values, $wp_actions_fired;

        $wpdb             = $this->original_wpdb;
        $wp_transients    = [];
        $wp_filter_values = [];
        $wp_actions_fired = [];
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

        $delete_queries = $this->deleteQueries($wpdb->queries);

        $this->assertSame([], $delete_queries);
    }

    public function testCleanupExpiredEntriesDeletesOnlyOldAuditRows(): void
    {
        global $wpdb;

        $logger = new AuditLogger();
        $logger->cleanup_expired_entries();

        $delete_queries = $this->deleteQueries($wpdb->queries);

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

    /**
     * The draw is `wp_rand( 1, SAMPLE_PRECISION ) / SAMPLE_PRECISION`, so its
     * smallest possible value is one millionth and a rate under that could never
     * be met — a silent total stop, while the rate persisted with each rollup
     * still claimed that proportion had been kept, so the extrapolation
     * downstream scaled up an empty sample. The rate is clamped up to the floor
     * instead, which the smallest real draw does satisfy.
     */
    public function testARateBelowThePrecisionFloorIsClampedToIt(): void
    {
        global $wpdb, $wp_filter_values;

        $wp_filter_values['saltus/framework/mcp/audit/sample_rate']  = 1e-9;
        $wp_filter_values['saltus/framework/mcp/audit/sample_value'] = 1.0 / AuditLogger::SAMPLE_PRECISION;

        $this->recordEntries(3);

        $this->assertCount(3, $wpdb->inserts, 'the smallest expressible draw must still pass the clamped rate');
    }

    /**
     * The clamp must not turn "record only failures" into sampling: an exact 0.0
     * is a deliberate setting, not a rate too small for the constant to express.
     */
    public function testNormalizeSampleRateRaisesSubFloorRatesButLeavesZeroAlone(): void
    {
        $floor = 1.0 / AuditLogger::SAMPLE_PRECISION;

        $this->assertSame($floor, RollupStore::normalize_sample_rate(1e-9));
        $this->assertSame($floor, RollupStore::normalize_sample_rate($floor));
        $this->assertSame(0.0, RollupStore::normalize_sample_rate(0.0));
        $this->assertSame(0.5, RollupStore::normalize_sample_rate(0.5));
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

    /**
     * Slow-call retention is configured on its own filter, so it cannot be gated
     * on the normal one. Unlimited normal retention used to return before the
     * slow-call cleanup was reached, leaving that table to grow forever while
     * `slow_retention_days` said it was bounded.
     */
    public function testUnlimitedNormalRetentionStillPrunesSlowCalls(): void
    {
        global $wpdb, $wp_filter_values;

        $wp_filter_values['saltus/framework/mcp/audit/retention_days']      = 0;
        $wp_filter_values['saltus/framework/mcp/audit/slow_retention_days'] = 7;

        (new AuditLogger())->cleanup_expired_entries();

        $deletes = $this->deleteQueries($wpdb->queries);

        $this->assertCount(1, $deletes, 'only the slow-call table has a finite retention here');
        $this->assertStringStartsWith(
            "DELETE FROM wp_saltus_mcp_audit_slow_calls WHERE created_at < '",
            $deletes[0]
        );
    }

    /**
     * The slow-call table used to be created only by `record_slow_call()`, so on
     * an install that had never had a slow call the retention cron deleted from a
     * table that was not there and logged a database error on every run.
     */
    public function testRetentionCreatesTheSlowCallTableBeforePruningIt(): void
    {
        global $wpdb;

        (new AuditLogger())->cleanup_expired_entries();

        $create = $this->firstIndexMatching(
            $wpdb->queries,
            'CREATE TABLE IF NOT EXISTS wp_saltus_mcp_audit_slow_calls'
        );
        $delete = $this->firstIndexMatching($wpdb->queries, 'DELETE FROM wp_saltus_mcp_audit_slow_calls');

        $this->assertNotNull($create, 'retention must create the slow-call table it prunes');
        $this->assertNotNull($delete);
        $this->assertLessThan($delete, $create);
    }

    /**
     * The schema check belongs behind the same verification transient as the audit
     * table. It ran on every slow call, which put extra database work on exactly
     * the requests already identified as slow.
     */
    public function testABurstOfSlowCallsIssuesTheSchemaStatementOnce(): void
    {
        global $wpdb, $wp_filter_values;

        $wp_filter_values['saltus/framework/mcp/audit/slow_threshold_ms'] = 0.0;

        // A fresh logger per call: the per-request flag would hide a missing
        // transient throttle, and separate requests are the case that matters.
        for ($i = 0; $i < 5; $i++) {
            $entry = new AuditEntry('slow_tool', []);
            $entry->complete('success');
            (new AuditLogger())->record($entry);
        }

        $creates = array_values(array_filter(
            $wpdb->queries,
            static fn(string $query): bool => strpos($query, 'CREATE TABLE IF NOT EXISTS wp_saltus_mcp_audit_slow_calls') === 0
        ));

        $this->assertCount(1, $creates);
        $this->assertCount(5, $this->slowRows($wpdb->inserts), 'every slow call must still be recorded');
    }

    /**
     * The threshold is inclusive. A duration equal to it is the configured limit
     * being reached, not missed, and an exclusive comparison would silently drop
     * the calls sitting exactly on a round threshold.
     */
    public function testTheSlowThresholdIsInclusive(): void
    {
        $this->assertTrue($this->isSlow(5000.0), 'a duration equal to the threshold is slow');
        $this->assertFalse($this->isSlow(4999.999));
    }

    /**
     * An incomplete entry has a null duration. Without the numeric guard PHP
     * casts that to 0.0, and a threshold of 0 would then log every unfinished
     * call as slow.
     */
    public function testANonNumericDurationIsNeverSlow(): void
    {
        global $wp_filter_values;

        $wp_filter_values['saltus/framework/mcp/audit/slow_threshold_ms'] = 0.0;

        $this->assertFalse($this->isSlow(null));
        $this->assertFalse($this->isSlow('quickly'));
    }

    /**
     * A non-finite threshold falls back to the default rather than being compared
     * against. INF would mark nothing slow and switch the feature off silently;
     * NAN fails every comparison, and `max( 0.0, NAN )` is 0.0, so it would mark
     * everything slow instead.
     */
    public function testANonFiniteThresholdFallsBackToTheDefault(): void
    {
        global $wp_filter_values;

        $wp_filter_values['saltus/framework/mcp/audit/slow_threshold_ms'] = INF;
        $this->assertTrue($this->isSlow(5000.0), 'INF must not switch slow-call logging off');

        $wp_filter_values['saltus/framework/mcp/audit/slow_threshold_ms'] = NAN;
        $this->assertFalse($this->isSlow(1.0), 'NAN must not make every call slow');
    }

    /**
     * Slow calls are persisted outside sampling: a site sampling low enough to
     * control table growth still needs the calls worth investigating.
     */
    public function testASlowCallIsRecordedEvenWhenSamplingDropsIt(): void
    {
        global $wpdb, $wp_filter_values;

        $wp_filter_values['saltus/framework/mcp/audit/slow_threshold_ms'] = 0.0;
        $wp_filter_values['saltus/framework/mcp/audit/sample_rate']       = 0.0;

        $entry = new AuditEntry('slow_tool', ['id' => 7], 'session-9');
        $entry->complete('error', 'timeout', 'took too long');
        (new AuditLogger())->record($entry);

        $slow_rows = $this->slowRows($wpdb->inserts);

        $this->assertCount(1, $slow_rows);
        $this->assertSame('slow_tool', $slow_rows[0]['ability']);
        $this->assertSame('session-9', $slow_rows[0]['identifier']);
        $this->assertSame('error', $slow_rows[0]['status']);
        $this->assertSame('timeout', $slow_rows[0]['error_code']);
        $this->assertSame('took too long', $slow_rows[0]['error_message']);
        $this->assertSame('{"id":7}', $slow_rows[0]['arguments']);
        $this->assertIsFloat($slow_rows[0]['duration_ms']);
        $this->assertNotSame('', $slow_rows[0]['created_at']);
    }

    public function testASuccessfulFailureInsertFiresTheErrorAction(): void
    {
        $entry = new AuditEntry('failing', []);
        $entry->complete('error', 'api_error', 'fail');
        (new AuditLogger())->record($entry);

        $this->assertSame([$entry], $this->erroredEntries());
    }

    /**
     * The hook hands a collector a persisted entry. Firing it for a row the
     * database rejected would have every external collector holding failures the
     * audit log itself cannot account for.
     */
    public function testARejectedInsertDoesNotFireTheErrorAction(): void
    {
        global $wpdb;

        $wpdb = $this->fakeWpdb(true, false);

        $entry = new AuditEntry('failing', []);
        $entry->complete('error', 'api_error', 'fail');
        (new AuditLogger())->record($entry);

        $this->assertNotSame([], $wpdb->inserts, 'the insert must have been attempted');
        $this->assertSame([], $this->erroredEntries());
    }

    /** A success is persisted but is not a failure, so nothing is handed on. */
    public function testASuccessDoesNotFireTheErrorAction(): void
    {
        $this->recordEntries(1);

        $this->assertSame([], $this->erroredEntries());
    }

    /**
     * Ask the threshold check directly. Duration comes from the wall clock, so
     * the boundary is not reachable through `record()`.
     *
     * @param mixed $duration
     */
    private function isSlow($duration): bool
    {
        $method = new \ReflectionMethod(AuditLogger::class, 'is_slow');
        $method->setAccessible(true);

        return (bool) $method->invoke(new AuditLogger(), $duration);
    }

    /**
     * Entries handed to the observability error action, in order.
     *
     * @return list<mixed>
     */
    private function erroredEntries(): array
    {
        global $wp_actions_fired;

        $entries = [];
        foreach (is_array($wp_actions_fired) ? $wp_actions_fired : [] as $fired) {
            if ($fired['tag'] === 'saltus/framework/observability/error') {
                $entries[] = $fired['args'][0] ?? null;
            }
        }

        return $entries;
    }

    /**
     * @param list<array<string, mixed>> $inserts
     * @return list<array<string, mixed>>
     */
    private function slowRows(array $inserts): array
    {
        $rows = [];
        foreach ($inserts as $insert) {
            if ($insert['table'] === 'wp_saltus_mcp_audit_slow_calls') {
                $rows[] = $insert['data'];
            }
        }

        return $rows;
    }

    /**
     * @param list<string> $queries
     * @return list<string>
     */
    private function deleteQueries(array $queries): array
    {
        return array_values(array_filter(
            $queries,
            static fn(string $query): bool => strpos($query, 'DELETE FROM') === 0
        ));
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
     * @param bool $query_result  What query() reports. False models a server that
     *                            rejected the statement, as wpdb::query() does.
     * @param bool $insert_result What insert() reports. False models a rejected
     *                            row, which wpdb::insert() answers the same way.
     */
    private function fakeWpdb(bool $query_result = true, bool $insert_result = true): object
    {
        return new class($query_result, $insert_result) implements \Saltus\WP\Framework\MCP\Audit\AuditDatabase {
            public string $prefix = 'wp_';
            /** @var list<array<string, mixed>> */
            public array $inserts = [];
            /** @var list<string> */
            public array $queries = [];
            private bool $query_result;
            private bool $insert_result;

            public function __construct(bool $query_result = true, bool $insert_result = true)
            {
                $this->query_result  = $query_result;
                $this->insert_result = $insert_result;
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
                return $this->insert_result;
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
