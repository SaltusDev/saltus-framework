<?php

namespace Saltus\WP\Framework\Tests\Models;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Models\Config\SuggestsNearestKey;

/**
 * The shared "did you mean" suggestion used by the validator and every
 * contributor, tested in isolation.
 *
 * A trait cannot be instantiated directly, so an anonymous class brings the
 * method into scope. The threshold matters more than the algorithm: every
 * consumer must agree on how close "close" is, or the same typo gets a
 * suggestion in one section and none in another.
 *
 * @covers \Saltus\WP\Framework\Models\Config\SuggestsNearestKey
 */
class SuggestsNearestKeyTest extends TestCase {

	public function testAnExactMatchIsNotOfferedAsASuggestion(): void {
		$this->assertNull(
			$this->suggest( 'has_many', [ 'has_one', 'has_many', 'belongs_to', 'many_to_many' ] )
		);
	}

	public function testACaseOnlyDifferenceIsNotASuggestion(): void {
		$this->assertNull(
			$this->suggest( 'HAS_MANY', [ 'has_one', 'has_many', 'belongs_to', 'many_to_many' ] )
		);
	}

	public function testANearMissGetsTheClosestCandidate(): void {
		$this->assertSame(
			'has_many',
			$this->suggest( 'has_meny', [ 'has_one', 'has_many', 'belongs_to', 'many_to_many' ] )
		);
	}

	public function testMatchingIsCaseInsensitive(): void {
		$this->assertSame(
			'has_many',
			$this->suggest( 'HAS_MENY', [ 'has_one', 'has_many', 'belongs_to', 'many_to_many' ] )
		);
	}

	public function testADistanceOfTwoIsStillASuggestion(): void {
		$this->assertSame(
			'taxonomies',
			$this->suggest( 'taxonomys', [ 'taxonomies', 'meta' ] )
		);
	}

	public function testAboveTheThresholdNoSuggestionIsOffered(): void {
		$this->assertNull(
			$this->suggest( 'meta', [ 'has_one', 'has_many', 'belongs_to', 'many_to_many' ] )
		);
	}

	public function testTheClosestCandidateWins(): void {
		$this->assertSame(
			'has_many',
			$this->suggest( 'has_man', [ 'has_one', 'has_many', 'belongs_to', 'many_to_many' ] )
		);
	}

	public function testTheThresholdCanBeTightened(): void {
		$this->assertSame(
			'has_many',
			$this->suggest( 'has_meny', [ 'has_one', 'has_many', 'belongs_to', 'many_to_many' ], 1 )
		);
		$this->assertNull(
			$this->suggest( 'taxonomys', [ 'taxonomies', 'meta' ], 1 )
		);
	}

	public function testAnEmptyValueIsNeverASuggestion(): void {
		$this->assertNull( $this->suggest( '', [ 'has_one', 'has_many' ] ) );
	}

	public function testEmptyCandidatesOfferNothing(): void {
		$this->assertNull( $this->suggest( 'has_meny', [] ) );
	}

	private function suggest( string $value, array $candidates, int $max_distance = 2 ): ?string {
		$rule = new class() {
			use SuggestsNearestKey;

			public function nearest( string $value, array $candidates, int $max_distance = 2 ): ?string {
				return $this->nearest_key( $value, $candidates, $max_distance );
			}
		};

		return $rule->nearest( $value, $candidates, $max_distance );
	}
}
