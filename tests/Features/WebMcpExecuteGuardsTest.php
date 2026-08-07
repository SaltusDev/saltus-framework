<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\WebMcp\WebMcp;
use Saltus\WP\Framework\Features\WebMcp\WebMcpPolicy;
use Saltus\WP\Framework\MCP\RateLimiter\RateLimiter;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Rest\WebMcpController;
use Saltus\WP\Framework\WebMcp\ResultBudget;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * Guards on the execute route: per-client throttling and output clamping.
 *
 * These exercise the wiring rather than the units — a correct ResultBudget that
 * the controller never calls would still ship an unbounded payload.
 *
 * @covers \Saltus\WP\Framework\Rest\WebMcpController
 */
class WebMcpExecuteGuardsTest extends TestCase {

	/** @var array<string, mixed> */
	private array $server = [];

	protected function setUp(): void {
		global $wp_filter_values, $wp_current_user_id, $wp_transients, $wp_query_posts, $wp_post_type_objects, $wp_object_taxonomies;
		$wp_filter_values     = [];
		$wp_current_user_id   = 0;
		$wp_transients        = [];
		$wp_query_posts       = [];
		$wp_post_type_objects = [];
		$wp_object_taxonomies = [];
		$this->server         = $_SERVER;
	}

	protected function tearDown(): void {
		global $wp_filter_values, $wp_current_user_id, $wp_transients;
		$wp_filter_values   = [];
		$wp_current_user_id = null;
		$wp_transients      = [];
		$_SERVER            = $this->server;
	}

	public function testRateLimitIsScopedPerClientNotGlobally(): void {
		$controller = $this->controller( new RateLimiter( 2, 60 ) );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertExecutes( $controller, 'First call for this client is allowed.' );
		$this->assertExecutes( $controller, 'Second call is still within the window.' );

		$throttled = $controller->execute_tool( $this->request() );
		$this->assertInstanceOf( \WP_Error::class, $throttled );
		$this->assertSame( 'saltus_webmcp_rate_limited', $throttled->get_error_code() );

		// A different visitor must not inherit the first one's exhausted window.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->assertExecutes( $controller, 'A second client must get its own window.' );
	}

	public function testThrottledClientDoesNotBlockLoggedInCaller(): void {
		global $wp_current_user_id;

		$controller = $this->controller( new RateLimiter( 1, 60 ) );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertExecutes( $controller );

		$throttled = $controller->execute_tool( $this->request() );
		$this->assertInstanceOf( \WP_Error::class, $throttled );

		$wp_current_user_id = 42;
		$this->assertExecutes( $controller, 'A logged-in caller is keyed by user id, not the shared IP window.' );
	}

	public function testResultIsClampedToTheOutputBudget(): void {
		global $wp_query_posts, $wp_filter_values;

		// Enough oversized posts that the raw result cannot fit the budget.
		for ( $i = 1; $i <= 40; $i++ ) {
			$wp_query_posts[] = new \WP_Post(
				[
					'ID'           => $i,
					'post_type'    => 'book',
					'post_status'  => 'publish',
					'post_title'   => 'Result number ' . $i,
					'post_excerpt' => str_repeat( 'padding excerpt text ', 10 ),
					'post_date'    => '2026-08-01 10:00:00',
				]
			);
		}

		$wp_filter_values['saltus/framework/mcp/rate_limit/enabled'] = false;

		$response = $this->controller()->execute_tool( $this->request() );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );

		$result = $response->get_data()['result'];
		$budget = new ResultBudget();

		$this->assertLessThanOrEqual( ResultBudget::MAX_OUTPUT, $budget->measure( $result ) );
		$this->assertTrue( $result['truncated'], 'The agent must be told the result was shortened.' );
		$this->assertLessThan( 40, count( $result['results'] ) );
		$this->assertSame( count( $result['results'] ), $result['count'] );
	}

	public function testSmallResultIsNotFlaggedAsTruncated(): void {
		global $wp_query_posts, $wp_filter_values;

		$wp_query_posts[] = new \WP_Post(
			[
				'ID'          => 1,
				'post_type'   => 'book',
				'post_status' => 'publish',
				'post_title'  => 'One result',
				'post_date'   => '2026-08-01 10:00:00',
			]
		);

		$wp_filter_values['saltus/framework/mcp/rate_limit/enabled'] = false;

		$response = $this->controller()->execute_tool( $this->request() );
		$result   = $response->get_data()['result'];

		$this->assertArrayNotHasKey( 'truncated', $result );
		$this->assertSame( 1, $result['count'] );
	}

	/**
	 * Assert a call reaches the tool rather than returning an error.
	 */
	private function assertExecutes( WebMcpController $controller, string $message = '' ): void {
		$response = $controller->execute_tool( $this->request() );

		$this->assertInstanceOf( \WP_REST_Response::class, $response, $message );
	}

	/**
	 * A search_content request the agent would send.
	 */
	private function request(): object {
		return new class {
			/** @return array<string, mixed> */
			public function get_json_params(): array {
				return [
					'tool'      => 'search_content',
					'arguments' => [ 'query' => 'test' ],
				];
			}
		};
	}

	private function controller( ?RateLimiter $limiter = null ): WebMcpController {
		$modeler = $this->modeler();
		$policy  = new WebMcpPolicy( $modeler );
		$feature = new WebMcp( [ 'modeler' => $modeler ] );

		return new WebMcpController(
			$policy,
			$feature->build_tools( $modeler ),
			null,
			null,
			$limiter
		);
	}

	private function modeler(): Modeler {
		global $wp_post_type_objects;

		$model = $this->createStub( Model::class );
		$model->method( 'get_name' )->willReturn( 'book' );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_config' )->willReturn( [ 'webmcp' => true ] );
		$model->method( 'get_options' )->willReturn( [ 'show_in_rest' => true ] );
		$model->method( 'get_args' )->willReturn( [ 'public' => true, 'publicly_queryable' => true ] );

		$wp_post_type_objects['book'] = (object) [
			'name'               => 'book',
			'publicly_queryable' => true,
			'public'             => true,
			'label'              => 'Book',
			'labels'             => (object) [ 'name' => 'Books' ],
		];

		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( [ 'book' => $model ] );

		return $modeler;
	}
}
