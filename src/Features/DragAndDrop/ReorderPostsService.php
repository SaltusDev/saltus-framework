<?php
namespace Saltus\WP\Framework\Features\DragAndDrop;

use Saltus\WP\Framework\Rest\ModelRestPolicy;

/**
 * Shared service for applying menu_order updates to posts.
 */
class ReorderPostsService {

	/**
	 * Check whether the current user can edit at least one post in the request.
	 *
	 * @param array<int, mixed> $items Requested reorder items.
	 * @return bool
	 */
	public function can_edit_any_requested_post( array $items ): bool {
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['id'] ) ) {
				continue;
			}

			$post_id = (int) $item['id'];
			if ( $post_id > 0 && get_post( $post_id ) && current_user_can( 'edit_post', $post_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reorder posts by updating their menu_order values.
	 *
	 * @param array<int, mixed> $items Requested reorder items.
	 * @param ModelRestPolicy|null $policy Optional REST policy for capability gating.
	 * @return array{results: list<array<string, mixed>>, total: int, updated: int}
	 */
	public function reorder( array $items, ?ModelRestPolicy $policy = null ): array {
		$results = [];

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['id'], $item['menu_order'] ) ) {
				$results[] = [
					'id'     => 0,
					'status' => 'skipped',
					'reason' => 'Invalid item payload',
				];
				continue;
			}

			$post_id    = (int) $item['id'];
			$menu_order = (int) $item['menu_order'];

			if ( ! get_post( $post_id ) ) {
				$results[] = [
					'id'     => $post_id,
					'status' => 'skipped',
					'reason' => 'Post not found',
				];
				continue;
			}

			if ( $policy && ! $policy->is_post_enabled( $post_id, ModelRestPolicy::CAPABILITY_REORDER ) ) {
				$results[] = [
					'id'     => $post_id,
					'status' => 'skipped',
					'reason' => 'Reorder is not enabled for this post type',
				];
				continue;
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				$results[] = [
					'id'     => $post_id,
					'status' => 'skipped',
					'reason' => 'Permission denied',
				];
				continue;
			}

			$updated = wp_update_post(
				[
					'ID'         => $post_id,
					'menu_order' => $menu_order,
				],
				true
			);

			if ( is_wp_error( $updated ) ) {
				$results[] = [
					'id'     => $post_id,
					'status' => 'error',
					'reason' => $updated->get_error_message(),
				];
				continue;
			}

			$results[] = [
				'id'         => $post_id,
				'menu_order' => $menu_order,
				'status'     => 'updated',
			];
		}

		return [
			'results' => $results,
			'total'   => count( $results ),
			'updated' => count( array_filter( $results, fn( $result ) => $result['status'] === 'updated' ) ),
		];
	}
}
