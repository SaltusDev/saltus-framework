<?php

namespace Saltus\WP\Framework\Tests\MCP\Abilities;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Abilities\AbilityRuntime;
use Saltus\WP\Framework\MCP\RateLimiter\RateLimiter;
use Saltus\WP\Framework\MCP\Tools\CreatePost;
use Saltus\WP\Framework\MCP\Tools\ListModels;
use Saltus\WP\Framework\MCP\Tools\UpdateSettings;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

class AbilityRuntimeTest extends TestCase {

	protected function setUp(): void {
		global $wpdb, $wp_transients, $wp_options, $wp_rest_request_log;
		$wp_transients       = [];
		$wp_options           = [];
		$wp_rest_request_log  = [];
		if ( ! is_object( $wpdb ) ) {
			$wpdb = $this->fakeWpdb();
		}
		$wpdb->inserts = [];
		$wpdb->queries = [];
	}

	public function testExecuteCallsRestDoRequestOnValidTool(): void {
		global $wp_rest_request_log;

		$runtime = new AbilityRuntime();
		$tool    = new ListModels();

		$result = $runtime->execute( $tool, [ 'type' => 'post_types' ] );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $wp_rest_request_log );
		$this->assertSame( 'GET', $wp_rest_request_log[0]['method'] );
		$this->assertSame( '/saltus-framework/v1/models', $wp_rest_request_log[0]['route'] );
	}

	public function testExecuteReturnsValidationError(): void {
		$runtime = new AbilityRuntime();
		$tool    = new CreatePost();

		$result = $runtime->execute( $tool, [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_params', $result->get_error_code() );
	}

	public function testExecuteReturnsRateLimitError(): void {
		$runtime = new AbilityRuntime( null, new RateLimiter( 1, 60 ) );
		$tool    = new ListModels();

		$runtime->execute( $tool, [] );
		$result = $runtime->execute( $tool, [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );
	}

	public function testExecuteWritesAuditRecordOnSuccess(): void {
		global $wpdb;

		$runtime = new AbilityRuntime();
		$tool    = new ListModels();

		$runtime->execute( $tool, [] );

		$this->assertNotEmpty( $wpdb->inserts );
		$this->assertSame( 'success', $wpdb->inserts[0]['data']['status'] );
	}

	public function testExecuteWritesAuditRecordOnError(): void {
		global $wpdb;

		$runtime = new AbilityRuntime();
		$tool    = new CreatePost();

		$runtime->execute( $tool, [] );

		$this->assertNotEmpty( $wpdb->inserts );
		$this->assertSame( 'validation_error', $wpdb->inserts[0]['data']['status'] );
	}

	public function testCacheHitSkipsRestRequest(): void {
		global $wp_rest_request_log, $wp_transients;

		$runtime    = new AbilityRuntime();
		$tool       = new ListModels();
		$cache_key  = 'saltus_mcp_' . hash( 'sha256', '{"tool":"list_models","args":[],"user":1,"locale":"en_US"}' );

		$wp_transients = [
			$cache_key => [
				'value'   => [ 'cached' => true ],
				'expires' => 0,
			],
		];

		$result = $runtime->execute( $tool, [] );

		$this->assertSame( [ 'cached' => true ], $result );
		$this->assertEmpty( $wp_rest_request_log );
	}

	public function testMutatingToolClearsCache(): void {
		$runtime    = new AbilityRuntime();
		$read_tool  = new ListModels();
		$write_tool = new UpdateSettings();

		$runtime->execute( $read_tool, [] );
		$this->assertNotEmpty(
			$runtime->execute(
				$write_tool,
				[
					'post_type' => 'book',
					'settings'  => [],
				]
			)
		);
	}

	private function fakeWpdb(): object {
		return new class implements \Saltus\WP\Framework\MCP\Audit\AuditDatabase {
			public string $prefix = 'wp_';
			/** @var list<array<string, mixed>> */
			public array $inserts = [];
			/** @var list<string> */
			public array $queries = [];

			public function prefix(): string {
				return $this->prefix;
			}

			/**
			 * @param array<string, mixed> $data
			 * @param list<string> $format
			 */
			public function insert( string $table, array $data, array $format = [] ): bool {
				$this->inserts[] = compact( 'table', 'data', 'format' );
				return true;
			}

			public function query( string $query ): bool {
				$this->queries[] = $query;
				return true;
			}

			public function prepare( string $query, mixed ...$args ): string {
				foreach ( $args as $arg ) {
					$query = preg_replace( '/%[dsf]/', (string) $arg, $query, 1 );
				}
				return $query;
			}

			public function get_results( string $query, mixed $output = null ): array {
				return array_reverse( array_map( fn( array $insert ) => $insert['data'], $this->inserts ) );
			}

			public function get_charset_collate(): string {
				return '';
			}
		};
	}
}
