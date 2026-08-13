<?php

namespace Saltus\WP\Framework\Tests\MCP\Abilities;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Abilities\AbilityRuntime;
use Saltus\WP\Framework\MCP\RateLimiter\RateLimiter;
use Saltus\WP\Framework\MCP\Tools\CreatePost;
use Saltus\WP\Framework\MCP\Tools\ListModels;
use Saltus\WP\Framework\MCP\Tools\UpdateSettings;
use Saltus\WP\Framework\MCP\Tools\UpdatePost;
use Saltus\WP\Framework\Features\AiContext\AiContextProvider;
use Saltus\WP\Framework\Features\EditorialReview\ProposalService;
use Saltus\WP\Framework\Features\EditorialReview\ProposalStore;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\MCP\Abilities\AbilityRuntime
 */
class AbilityRuntimeTest extends TestCase {

	protected function setUp(): void {
		global $wpdb, $wp_transients, $wp_options, $wp_rest_request_log, $wp_rest_response_override, $wp_current_user_can;
		$wp_transients             = [];
		$wp_options                 = [];
		$wp_rest_request_log        = [];
		$wp_rest_response_override  = null;
		$wp_current_user_can        = true;
		if ( ! is_object( $wpdb ) ) {
			$wpdb = $this->fakeWpdb();
		}
		$wpdb->inserts = [];
		$wpdb->queries = [];
	}

	/**
	 * Clear the response override on the way out, not just on the way in.
	 *
	 * One test here installs a canned `WP_REST_Response` that the stubbed
	 * `rest_do_request()` returns for every dispatch. Resetting only in `setUp`
	 * protects this class while leaving the override in place for whichever class
	 * runs next — `FieldQueryGuardTest` then saw this class's response instead of
	 * its own, and failed on seed 1786550479.
	 */
	protected function tearDown(): void {
		global $wp_rest_response_override, $wp_transients, $wp_rest_request_log;
		$wp_rest_response_override = null;
		$wp_transients             = [];
		$wp_rest_request_log       = [];
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

	public function testExecuteRejectsMutationBeforeRestDispatch(): void {
		global $wp_rest_request_log;
		$model = $this->createStub( Model::class );
		$model->method( 'get_config' )->willReturn( [ 'ai_context' => [ 'allowed_statuses' => [ 'draft' ] ] ] );
		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( [ 'book' => $model ] );
		$provider = new AiContextProvider( $modeler );
		$runtime  = new AbilityRuntime( null, null, null, null, $provider );

		$result = $runtime->execute( new UpdatePost(), [ 'post_type' => 'book', 'post_id' => 7, 'status' => 'publish' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_context_violation', $result->get_error_code() );
		$this->assertEmpty( $wp_rest_request_log );
	}

	public function testEditorialReviewQueuesValidMutationBeforeRestDispatch(): void {
		global $wp_rest_request_log;

		$runtime = new AbilityRuntime( null, null, null, null, null, new ProposalService( new ProposalStore( null ) ) );
		$result  = $runtime->execute(
			new CreatePost(),
			[
				'post_type' => 'book',
				'title'     => 'Queued book',
				'status'    => 'draft',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertSame( 1, $result['proposal_id'] );
		$this->assertEmpty( $wp_rest_request_log );
	}

	public function testEditorialReviewDoesNotQueueUnauthorizedMutation(): void {
		global $wp_current_user_can;
		$wp_current_user_can = false;
		$runtime             = new AbilityRuntime( null, null, null, null, null, new ProposalService( new ProposalStore( null ) ) );

		$result = $runtime->execute( new CreatePost(), [ 'post_type' => 'book', 'title' => 'Blocked book' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
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

	public function testExecuteReturnsWpErrorWhenRestDispatchReturnsErrorStatus(): void {
		global $wpdb, $wp_rest_response_override;

		$wp_rest_response_override = new \WP_REST_Response(
			[
				'code'    => 'model_not_found',
				'message' => 'Model not found.',
			],
			404
		);

		$runtime = new AbilityRuntime();
		$tool    = new ListModels();
		$result  = $runtime->execute( $tool, [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'model_not_found', $result->get_error_code() );
		$this->assertSame( 'error', $wpdb->inserts[0]['data']['status'] );
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
			public string $posts = 'wp_posts';
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

			public function prepare( string $query, ...$args ): string {
				foreach ( $args as $arg ) {
					$query = preg_replace( '/%[dsf]/', (string) $arg, $query, 1 );
				}
				return $query;
			}

			public function get_results( string $query, $output = null ) {
				return array_reverse( array_map( fn( array $insert ) => $insert['data'], $this->inserts ) );
			}

			public function get_charset_collate(): string {
				return '';
			}
		};
	}
}
