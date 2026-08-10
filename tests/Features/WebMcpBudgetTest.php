<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\WebMcp\ResultBudget;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\WebMcp\ResultBudget
 */
class WebMcpBudgetTest extends TestCase {

	protected function setUp(): void {
		global $wp_filter_values;
		$wp_filter_values = [];
	}

	protected function tearDown(): void {
		global $wp_filter_values;
		$wp_filter_values = [];
	}

	/**
	 * Trimming reclaims list and string cost, and nothing else. A payload made of
	 * many scalar keys whose strings already sit at the clip floor has neither, so
	 * it comes back over budget rather than mangled — apply() leaves the
	 * `truncated` flag off, which is what tells the agent the result is whole.
	 *
	 * Pinned because shrink_lists() once claimed to guarantee fit outright.
	 */
	public function testScalarOnlyPayloadCannotBeTrimmedToFit(): void {
		global $wp_filter_values;

		$wp_filter_values['saltus/framework/webmcp/output_budget'] = 200;

		$budget  = new ResultBudget();
		$payload = [];
		for ( $i = 0; $i < 20; $i++ ) {
			$payload[ 'field_' . $i ] = 'value';
		}

		$this->assertGreaterThan( 200, $budget->measure( $payload ), 'Fixture must start over budget.' );

		$clamped = $budget->apply( $payload );

		$this->assertSame( $payload, $clamped, 'Nothing here is a list or a clippable string.' );
		$this->assertArrayNotHasKey( 'truncated', $clamped, 'Nothing was dropped, so nothing may be flagged.' );
		$this->assertFalse( $budget->fits( $clamped ) );
	}

	public function testSmallResultPassesThroughUnchanged(): void {
		$budget = new ResultBudget();
		$result = [
			'query'   => 'ursula',
			'count'   => 1,
			'results' => [ [ 'id' => 1, 'title' => 'A Wizard of Earthsea' ] ],
		];

		$this->assertSame( $result, $budget->apply( $result ) );
		$this->assertArrayNotHasKey( 'truncated', $budget->apply( $result ) );
		$this->assertTrue( $budget->fits( $result ) );
	}

	public function testOversizedResultIsClampedToBudget(): void {
		$budget  = new ResultBudget();
		$results = [];

		for ( $i = 1; $i <= 40; $i++ ) {
			$results[] = [
				'id'        => $i,
				'title'     => 'Entry number ' . $i,
				'excerpt'   => str_repeat( 'padding text ', 12 ),
				'permalink' => 'http://example.com/entry-' . $i . '/',
			];
		}

		$oversized = [
			'count'   => count( $results ),
			'results' => $results,
		];

		$this->assertFalse( $budget->fits( $oversized ), 'Fixture must exceed the budget to be meaningful.' );

		$clamped = $budget->apply( $oversized );

		$this->assertLessThanOrEqual( ResultBudget::MAX_OUTPUT, $budget->measure( $clamped ) );
		$this->assertTrue( $clamped['truncated'] );
		$this->assertLessThan( 40, count( $clamped['results'] ) );
		$this->assertNotSame( [], $clamped['results'], 'Trimming must leave at least one entry.' );
	}

	public function testCountIsCorrectedWhenEntriesAreDropped(): void {
		$budget  = new ResultBudget();
		$results = [];

		for ( $i = 1; $i <= 30; $i++ ) {
			$results[] = [
				'id'      => $i,
				'title'   => 'Title ' . $i,
				'excerpt' => str_repeat( 'lorem ipsum ', 10 ),
			];
		}

		$clamped = $budget->apply(
			[
				'count'   => count( $results ),
				'results' => $results,
			]
		);

		$this->assertSame(
			count( $clamped['results'] ),
			$clamped['count'],
			'A stale count would tell the agent it received more entries than it did.'
		);
	}

	public function testSingleLongStringIsClippedRatherThanDropped(): void {
		$budget = new ResultBudget();

		$clamped = $budget->apply(
			[
				'found' => true,
				'entry' => [
					'id'      => 5,
					'title'   => 'A single long entry',
					'content' => str_repeat( 'sentence of body copy. ', 200 ),
				],
			]
		);

		$this->assertLessThanOrEqual( ResultBudget::MAX_OUTPUT, $budget->measure( $clamped ) );
		$this->assertTrue( $clamped['truncated'] );
		$this->assertTrue( $clamped['found'], 'Scalars outside the long string must survive.' );
		$this->assertSame( 5, $clamped['entry']['id'] );
		$this->assertSame( 'A single long entry', $clamped['entry']['title'] );
		$this->assertStringEndsWith( '…', $clamped['entry']['content'] );
		$this->assertLessThan( 4600, strlen( $clamped['entry']['content'] ) );
	}

	public function testClampedPayloadRemainsValidJson(): void {
		$budget = new ResultBudget();

		$clamped = $budget->apply(
			[
				'count'   => 1,
				'results' => [ [ 'content' => str_repeat( 'multibyte é text ', 300 ) ] ],
			]
		);

		$encoded = wp_json_encode( $clamped );

		$this->assertIsString( $encoded );
		$this->assertNotNull( json_decode( $encoded, true ), 'A mid-token cut would leave undecodable JSON.' );
		$this->assertSame( JSON_ERROR_NONE, json_last_error() );
	}

	public function testBudgetIsFilterable(): void {
		global $wp_filter_values;

		$result = [
			'count'   => 2,
			'results' => [
				[ 'title' => str_repeat( 'a', 120 ) ],
				[ 'title' => str_repeat( 'b', 120 ) ],
			],
		];

		$this->assertTrue( ( new ResultBudget() )->fits( $result ) );

		$wp_filter_values['saltus/framework/webmcp/output_budget'] = 120;

		$clamped = ( new ResultBudget() )->apply( $result );

		$this->assertLessThanOrEqual( 120, ( new ResultBudget() )->measure( $clamped ) );
		$this->assertTrue( $clamped['truncated'] );
	}

	public function testLongestListIsTrimmedFirst(): void {
		global $wp_filter_values;

		$wp_filter_values['saltus/framework/webmcp/output_budget'] = 400;

		$clamped = ( new ResultBudget() )->apply(
			[
				'taxonomies' => [ [ 'taxonomy' => 'genre' ] ],
				'results'    => array_fill( 0, 20, [ 'title' => 'padded entry title here' ] ),
			]
		);

		$this->assertCount( 1, $clamped['taxonomies'], 'The short list should survive intact.' );
		$this->assertLessThan( 20, count( $clamped['results'] ) );
	}
}
