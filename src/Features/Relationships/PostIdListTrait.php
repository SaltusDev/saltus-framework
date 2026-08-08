<?php

namespace Saltus\WP\Framework\Features\Relationships;

/**
 * Normalizes lists of post ids for relationship reads and writes.
 */
trait PostIdListTrait {

	/**
	 * Reduce a mixed list to unique, positive post ids, preserving order.
	 *
	 * Filtering explicitly on `> 0` rather than truthiness matters: a negative
	 * id is truthy and would otherwise be treated as a real post reference.
	 *
	 * @param array<int|string, mixed> $post_ids Raw post ids.
	 * @return list<int>
	 */
	private static function post_id_list( array $post_ids ): array {
		$ids = array_map( 'intval', array_values( $post_ids ) );

		return array_values(
			array_unique(
				array_filter(
					$ids,
					static function ( int $post_id ): bool {
						return $post_id > 0;
					}
				)
			)
		);
	}
}
