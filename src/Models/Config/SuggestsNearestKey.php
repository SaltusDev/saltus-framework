<?php

namespace Saltus\WP\Framework\Models\Config;

/**
 * Shared "did you mean" suggestion for config problems.
 *
 * A trait rather than a base class: contributors are feature services that already
 * extend or implement whatever their feature needs, and this is one function's worth
 * of behaviour. It is shared because `ConfigValidator` and every contributor must
 * agree on the distance threshold — two thresholds would mean the same typo gets a
 * suggestion in one section and none in another.
 *
 * @internal
 */
trait SuggestsNearestKey {

	/**
	 * The closest candidate to a misspelled value, or null when nothing is close.
	 *
	 * Two lets `has_meny` reach `has_many` and `taxonomys` reach `taxonomies`
	 * without `meta` suggesting `beta`. Above the threshold no suggestion is
	 * offered: a wrong guess is worse than none, because it sends the author to
	 * the wrong key.
	 *
	 * @param list<string> $candidates
	 */
	protected function nearest_key( string $value, array $candidates, int $max_distance = 2 ): ?string {
		if ( $value === '' || $candidates === [] ) {
			return null;
		}

		$best     = null;
		$shortest = PHP_INT_MAX;

		foreach ( $candidates as $candidate ) {
			$distance = levenshtein( strtolower( $value ), strtolower( $candidate ) );

			// A distance of zero means the value is already the candidate, which is
			// not a typo to suggest against.
			if ( $distance > 0 && $distance < $shortest ) {
				$shortest = $distance;
				$best     = $candidate;
			}
		}

		return $shortest <= $max_distance ? $best : null;
	}
}
