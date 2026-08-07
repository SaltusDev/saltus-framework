<?php

namespace Saltus\WP\Framework\WebMcp;

use Saltus\WP\Framework\Infrastructure\Services\FilterAwareTrait;

/**
 * Clamps a tool result to the agent output character budget.
 *
 * Agents apply their own output limits and degrade silently past them: an
 * oversized result is cut mid-token by the client, so the agent receives
 * malformed JSON with no signal that anything was dropped. Trimming here keeps
 * the payload well-formed and sets `truncated`, so the agent knows to narrow
 * its query instead of treating a partial answer as complete.
 *
 * Lists are shortened before strings are clipped: dropping whole entries costs
 * the agent less than mangling the entries it keeps.
 *
 * Budget figure from docs/discovery/webmcp.md — 1.5K characters per output.
 * @api
 */
final class ResultBudget {

	use FilterAwareTrait;

	/** Maximum characters in a serialized tool result. */
	public const MAX_OUTPUT = 1500;

	/** Shortest a string is ever clipped to, so clipped values stay meaningful. */
	private const MIN_STRING = 40;

	/** Characters held back for the `truncated` flag added after trimming. */
	private const FLAG_RESERVE = 24;

	/** Bound on string-clipping passes, so no payload can loop indefinitely. */
	private const MAX_PASSES = 20;

	/** Marker appended to a clipped string. */
	private const ELLIPSIS = '…';

	private int $budget;

	/**
	 * @param int $budget Character budget for a serialized result.
	 */
	public function __construct( int $budget = self::MAX_OUTPUT ) {
		$this->budget = max( 1, $budget );
	}

	/**
	 * Trim a result until it fits the budget.
	 *
	 * Returns the result unchanged when it already fits, so the common case
	 * costs one measurement and nothing else.
	 *
	 * @param array<string, mixed> $result Tool result payload.
	 * @return array<string, mixed> Result within budget, flagged when trimmed.
	 */
	public function apply( array $result ): array {
		$budget = $this->budget();
		if ( $this->measure( $result ) <= $budget ) {
			return $result;
		}

		$allowance = max( 1, $budget - self::FLAG_RESERVE );
		$trimmed   = $this->shrink_lists( $result, $allowance );
		$trimmed   = $this->clip_strings( $trimmed, $allowance );

		// Flagged only when something was actually dropped or clipped. A
		// payload made of many short scalars has nothing to trim, and claiming
		// otherwise would tell the agent to re-query for data it already has.
		if ( $trimmed !== $result ) {
			$trimmed['truncated'] = true;
		}

		return $trimmed;
	}

	/**
	 * Serialized length of a payload in characters.
	 *
	 * Measured against the JSON the browser actually receives rather than the
	 * PHP array, since the budget applies to what reaches the agent.
	 *
	 * @param array<string, mixed> $result Payload to measure.
	 */
	public function measure( array $result ): int {
		return strlen( $this->encode( $result ) );
	}

	/**
	 * Whether a payload fits the budget.
	 *
	 * @param array<string, mixed> $result Payload to test.
	 */
	public function fits( array $result ): bool {
		return $this->measure( $result ) <= $this->budget();
	}

	/**
	 * Resolve the effective budget.
	 *
	 * Filterable so a site pairing Saltus with a large-context agent can raise
	 * the ceiling without patching the framework.
	 */
	private function budget(): int {
		return max( 1, (int) $this->filter( 'saltus/framework/webmcp/output_budget', $this->budget ) );
	}

	/**
	 * Drop trailing entries from the longest list until the payload fits.
	 *
	 * The longest list is targeted each pass so a payload holding both a long
	 * result set and a short one loses entries from the set that is actually
	 * costing the budget.
	 *
	 * @param array<string, mixed> $result    Payload to shrink.
	 * @param int                  $allowance Character allowance.
	 * @return array<string, mixed>
	 */
	private function shrink_lists( array $result, int $allowance ): array {
		while ( $this->measure( $result ) > $allowance ) {
			$key = $this->longest_list_key( $result );
			if ( $key === null ) {
				return $result;
			}

			/** @var list<mixed> $list */
			$list = $result[ $key ];
			array_pop( $list );
			$result[ $key ] = $list;
			$result         = $this->recount( $result, $key, count( $list ) );
		}

		return $result;
	}

	/**
	 * Find the top-level key holding the longest non-empty list.
	 *
	 * @param array<string, mixed> $result Payload to inspect.
	 */
	private function longest_list_key( array $result ): ?string {
		$key   = null;
		$count = 0;

		foreach ( $result as $name => $value ) {
			if ( ! is_array( $value ) || $value === [] || ! $this->is_list( $value ) ) {
				continue;
			}

			if ( count( $value ) > $count ) {
				$key   = (string) $name;
				$count = count( $value );
			}
		}

		return $key;
	}

	/**
	 * Keep a sibling count key honest after entries are dropped.
	 *
	 * Every public tool returns `count` alongside its results. Leaving it at
	 * the pre-trim value would tell the agent it received more entries than it
	 * did, which is worse than returning fewer.
	 *
	 * @param array<string, mixed> $result    Payload being trimmed.
	 * @param string               $list_key  Key whose list shrank.
	 * @param int                  $remaining Entries left in that list.
	 * @return array<string, mixed>
	 */
	private function recount( array $result, string $list_key, int $remaining ): array {
		foreach ( [ 'count', $list_key . '_count' ] as $key ) {
			if ( isset( $result[ $key ] ) && is_int( $result[ $key ] ) ) {
				$result[ $key ] = $remaining;
			}
		}

		return $result;
	}

	/**
	 * Clip the longest string in the payload until it fits.
	 *
	 * Runs after list shrinking, so this only handles payloads whose weight is
	 * one long string — a single entry with a large body, typically.
	 *
	 * @param array<string, mixed> $result    Payload to clip.
	 * @param int                  $allowance Character allowance.
	 * @return array<string, mixed>
	 */
	private function clip_strings( array $result, int $allowance ): array {
		for ( $pass = 0; $pass < self::MAX_PASSES; $pass++ ) {
			$excess = $this->measure( $result ) - $allowance;
			if ( $excess <= 0 ) {
				return $result;
			}

			$path = $this->longest_string_path( $result );
			if ( $path === [] ) {
				return $result;
			}

			$current = (string) $this->read( $result, $path );
			$target  = strlen( $current ) - $excess;
			if ( $target < self::MIN_STRING ) {
				$target = self::MIN_STRING;
			}

			if ( $target >= strlen( $current ) ) {
				return $result;
			}

			$this->write( $result, $path, $this->clip( $current, $target ) );
		}

		return $result;
	}

	/**
	 * Locate the longest string in the payload, at any depth.
	 *
	 * @param mixed             $value  Value to search.
	 * @param list<string|int>  $prefix Path accumulated so far.
	 * @return list<string|int> Path to the longest string, empty when none.
	 */
	private function longest_string_path( $value, array $prefix = [] ): array {
		if ( is_string( $value ) ) {
			return strlen( $value ) > self::MIN_STRING ? $prefix : [];
		}

		if ( ! is_array( $value ) ) {
			return [];
		}

		$best   = [];
		$length = self::MIN_STRING;

		foreach ( $value as $key => $item ) {
			$path = $this->longest_string_path( $item, array_merge( $prefix, [ $key ] ) );
			if ( $path === [] ) {
				continue;
			}

			$candidate = strlen( (string) $this->read( $value, array_slice( $path, count( $prefix ) ) ) );
			if ( $candidate > $length ) {
				$best   = $path;
				$length = $candidate;
			}
		}

		return $best;
	}

	/**
	 * Read a value at a path.
	 *
	 * @param mixed            $value Payload to read from.
	 * @param list<string|int> $path  Path to read.
	 * @return mixed
	 */
	private function read( $value, array $path ) {
		foreach ( $path as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return null;
			}

			$value = $value[ $key ];
		}

		return $value;
	}

	/**
	 * Write a value at a path, in place.
	 *
	 * Walks by reference rather than rebuilding each level, so the caller's
	 * top-level key type is preserved through arbitrarily deep payloads.
	 *
	 * @param array<string, mixed> $target      Payload to modify.
	 * @param list<string|int>     $path        Path to write.
	 * @param mixed                $replacement Value to set.
	 */
	private function write( array &$target, array $path, $replacement ): void {
		if ( $path === [] ) {
			return;
		}

		$cursor = &$target;

		foreach ( $path as $depth => $key ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $key, $cursor ) ) {
				return;
			}

			if ( $depth === count( $path ) - 1 ) {
				$cursor[ $key ] = $replacement;

				return;
			}

			$cursor = &$cursor[ $key ];
		}
	}

	/**
	 * Clip a string to a length without cutting mid-word.
	 *
	 * @param string $value  Value to clip.
	 * @param int    $length Maximum length before the marker.
	 */
	private function clip( string $value, int $length ): string {
		$clipped = substr( $value, 0, $length );
		$break   = strrpos( $clipped, ' ' );

		if ( $break !== false && $break >= self::MIN_STRING ) {
			$clipped = substr( $clipped, 0, $break );
		}

		return rtrim( $clipped ) . self::ELLIPSIS;
	}

	/**
	 * Serialize a payload the way the REST layer will.
	 *
	 * @param array<string, mixed> $result Payload to encode.
	 */
	private function encode( array $result ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			$encoded = wp_json_encode( $result );

			return is_string( $encoded ) ? $encoded : '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fallback for use outside WordPress, where wp_json_encode is undefined.
		$encoded = json_encode( $result );

		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * Whether an array is a sequential list.
	 *
	 * Mirrors Validator::is_list — array_is_list needs PHP 8.1 and the
	 * framework targets 7.4.
	 *
	 * @param array<string|int, mixed> $value Array to test.
	 */
	private function is_list( array $value ): bool {
		$expected = 0;
		foreach ( $value as $key => $_ ) {
			if ( $key !== $expected ) {
				return false;
			}

			++$expected;
		}

		return true;
	}
}
