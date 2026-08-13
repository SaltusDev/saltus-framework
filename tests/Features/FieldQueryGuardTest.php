<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Meta\FieldQueryGuard;
use Saltus\WP\Framework\MCP\Abilities\AbilityRuntime;
use Saltus\WP\Framework\MCP\Tools\ListPosts;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\Features\Meta\FieldQueryGuard
 */
class FieldQueryGuardTest extends TestCase {

	protected function setUp(): void {
		$this->reset();
	}

	protected function tearDown(): void {
		$this->reset();
	}

	/**
	 * Reset in both hooks, not just setUp.
	 *
	 * `$wp_transients` matters here even though nothing in this class writes one:
	 * `AbilityRuntime::execute()` rate-limits through transients, so requests left
	 * behind by an earlier class count against this class's sliding window and the
	 * two "must be allowed" tests come back `rate_limited` instead. Reproducible on
	 * seed 1786550479. Clearing only in setUp protects this class but still leaks
	 * into the next one.
	 */
	private function reset(): void {
		global $wp_current_user_can, $wp_rest_request_log, $wp_posts, $wp_transients, $wp_rest_response_override;
		$wp_current_user_can = true;
		$wp_rest_request_log = [];
		$wp_posts            = [];
		$wp_transients       = [];
		// The stubbed `rest_do_request()` returns this whenever it is set, so an
		// override left behind by another class makes every dispatch here reply with
		// that class's canned response. `AbilityRuntimeTest` sets one and had no
		// tearDown, which is what failed the two "must be allowed" tests on seed
		// 1786550479.
		$wp_rest_response_override = null;
	}

	private function modeler(): Modeler {
		$meta = [
			'employment' => [
				'register_rest_api' => true,
				'fields'            => [
					'title' => [ 'type' => 'text', 'title' => 'Job Title' ],
					'ssn'   => [ 'type' => 'text', 'title' => 'SSN', 'encrypted' => true ],
				],
			],
		];

		$model = $this->createStub( Model::class );
		$model->method( 'get_config' )->willReturn( [ 'meta' => $meta ] );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_args' )->willReturn( [ 'meta' => $meta ] );

		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( [ 'book' => $model ] );

		return $modeler;
	}

	private function guard(): FieldQueryGuard {
		return new FieldQueryGuard( $this->modeler() );
	}

	public function testSortingOnAnEncryptedFieldIsRejected(): void {
		$result = $this->guard()->check( [ 'post_type' => 'book', 'orderby' => 'ssn' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'field_not_queryable', $result->get_error_code() );
	}

	public function testFilteringOnAnEncryptedMetaKeyIsRejected(): void {
		$result = $this->guard()->check( [ 'post_type' => 'book', 'meta_key' => 'ssn' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'field_not_queryable', $result->get_error_code() );
	}

	public function testMetaQueryClauseOnAnEncryptedFieldIsRejected(): void {
		$result = $this->guard()->check(
			[
				'post_type'  => 'book',
				'meta_query' => [
					[ 'key' => 'title', 'value' => 'Engineer' ],
					[ 'key' => 'ssn', 'value' => '123' ],
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ssn', $result->get_error_data()['field'] );
	}

	/** A clause can hold clauses, so the walk has to recurse. */
	public function testNestedMetaQueryClauseIsRejected(): void {
		$result = $this->guard()->check(
			[
				'post_type'  => 'book',
				'meta_query' => [
					'relation' => 'AND',
					[
						'relation' => 'OR',
						[ 'key' => 'title', 'value' => 'x' ],
						[ 'key' => 'ssn', 'value' => 'y' ],
					],
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ssn', $result->get_error_data()['field'] );
	}

	public function testSortingOnAnUnencryptedFieldIsAllowed(): void {
		$this->assertNull( $this->guard()->check( [ 'post_type' => 'book', 'orderby' => 'title' ] ) );
		$this->assertNull( $this->guard()->check( [ 'post_type' => 'book', 'orderby' => 'date' ] ) );
	}

	/**
	 * `search` hits post title and content, not meta, so it cannot touch an
	 * encrypted value. Rejecting it would be a false positive.
	 */
	public function testSearchIsNotTreatedAsAFieldReference(): void {
		$this->assertNull( $this->guard()->check( [ 'post_type' => 'book', 'search' => 'ssn' ] ) );
	}

	public function testCallWithNoFieldReferencesIsAllowed(): void {
		$this->assertNull( $this->guard()->check( [ 'post_type' => 'book', 'per_page' => 20 ] ) );
	}

	public function testCallWithoutAPostTypeIsAllowed(): void {
		$this->assertNull( $this->guard()->check( [ 'orderby' => 'ssn' ] ) );
	}

	public function testPostTypeWithoutEncryptedFieldsIsUnaffected(): void {
		$meta  = [ 'employment' => [ 'fields' => [ 'title' => [ 'type' => 'text' ] ] ] ];
		$model = $this->createStub( Model::class );
		$model->method( 'get_config' )->willReturn( [ 'meta' => $meta ] );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_args' )->willReturn( [ 'meta' => $meta ] );

		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( [ 'book' => $model ] );

		$this->assertNull( ( new FieldQueryGuard( $modeler ) )->check( [ 'post_type' => 'book', 'orderby' => 'anything' ] ) );
	}

	/** A lazy resolver, so the guard can be built before the registry exists. */
	public function testModelerCanBeResolvedLazily(): void {
		$calls   = 0;
		$modeler = $this->modeler();
		$guard   = new FieldQueryGuard(
			function () use ( &$calls, $modeler ): Modeler {
				++$calls;

				return $modeler;
			}
		);

		$this->assertInstanceOf( \WP_Error::class, $guard->check( [ 'post_type' => 'book', 'orderby' => 'ssn' ] ) );
		$guard->check( [ 'post_type' => 'book', 'orderby' => 'ssn' ] );

		$this->assertSame( 1, $calls, 'The registry must be resolved once and reused.' );
	}

	public function testGuardWithoutAModelerIsInert(): void {
		$this->assertNull( ( new FieldQueryGuard() )->check( [ 'post_type' => 'book', 'orderby' => 'ssn' ] ) );
	}

	// --- Through the real dispatch path ---

	/**
	 * The wiring, not just the guard. This is the gap the unit tests above would
	 * not have caught: a correct guard that nothing calls rejects nothing.
	 */
	public function testRuntimeRejectsAnEncryptedSortBeforeDispatch(): void {
		global $wp_rest_request_log;

		$runtime = new AbilityRuntime( null, null, null, null, null, null, $this->guard() );

		$result = $runtime->execute( new ListPosts(), [ 'post_type' => 'book', 'orderby' => 'ssn' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'field_not_queryable', $result->get_error_code() );
		$this->assertEmpty( $wp_rest_request_log, 'The query must not reach REST at all.' );
	}

	public function testRuntimeAllowsAnUnencryptedSort(): void {
		$runtime = new AbilityRuntime( null, null, null, null, null, null, $this->guard() );

		$result = $runtime->execute( new ListPosts(), [ 'post_type' => 'book', 'orderby' => 'title' ] );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
	}

	/** No guard configured must not change behavior for existing sites. */
	public function testRuntimeWithoutAGuardIsUnaffected(): void {
		$runtime = new AbilityRuntime();

		$result = $runtime->execute( new ListPosts(), [ 'post_type' => 'book', 'orderby' => 'ssn' ] );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
	}
}
