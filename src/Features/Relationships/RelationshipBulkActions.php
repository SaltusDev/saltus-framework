<?php

namespace Saltus\WP\Framework\Features\Relationships;

/**
 * Bulk attach and detach from the post list table.
 *
 * Delegates to `RelationshipManager::attach()` and `detach()` — the same methods
 * `wp saltus relationship` and the REST controller call. There is no bulk
 * fast-path: attaching fifty posts is fifty governed `attach()` calls, each
 * enforcing cardinality and target validity, rather than one query that skips
 * the checks. A bulk operation that can produce state a single operation would
 * have refused is a bug waiting to happen.
 *
 * WordPress's bulk-action UI cannot host a second input, so the related post id
 * is not chosen here. The action redirects back to the list with the selection
 * preserved, and the id comes from a query argument — which is how core's own
 * multi-step bulk flows work.
 *
 * @api
 */
final class RelationshipBulkActions {

	/** Query arg naming the relationship for a pending bulk operation. */
	public const ARG_RELATIONSHIP = 'saltus_rel';

	/** Query arg naming the related post id. */
	public const ARG_RELATED = 'saltus_rel_target';

	/** Query arg carrying the result summary back to the list screen. */
	public const ARG_RESULT = 'saltus_rel_result';

	/** Cap on posts processed in one bulk request. */
	private const MAX_POSTS = 100;

	private RelationshipManager $manager;

	public function __construct( RelationshipManager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Offer attach and detach per relationship on this post type.
	 *
	 * @param array<string, string> $actions   Existing bulk actions.
	 * @param string                $post_type Post type being listed.
	 * @return array<string, string>
	 */
	public function add_actions( array $actions, string $post_type ): array {
		foreach ( $this->manager->describe( $post_type ) as $definition ) {
			$name = isset( $definition['name'] ) ? (string) $definition['name'] : '';
			if ( $name === '' || ! $this->user_can_write( $post_type, $name ) ) {
				continue;
			}

			$label = ucwords( str_replace( [ '_', '-' ], ' ', $name ) );

			/* translators: %s: relationship label. */
			$actions[ 'saltus_attach_' . $name ] = sprintf( __( 'Attach to %s…', 'saltus-framework' ), $label );
			/* translators: %s: relationship label. */
			$actions[ 'saltus_detach_' . $name ] = sprintf( __( 'Detach from %s…', 'saltus-framework' ), $label );
		}

		return $actions;
	}

	/**
	 * Apply a bulk relationship action.
	 *
	 * Returns the redirect URL WordPress should send the user to, or the URL
	 * unchanged when this is not one of our actions. Returning rather than
	 * redirecting keeps the method testable and matches the `handle_bulk_actions`
	 * filter contract.
	 *
	 * @param string     $redirect_to Redirect URL WordPress proposes.
	 * @param string     $action      Bulk action selected.
	 * @param list<int>  $post_ids    Posts checked in the list.
	 * @param string     $post_type   Post type being listed.
	 * @param array<string, mixed> $request Query arguments, normally `$_REQUEST`.
	 */
	public function handle( string $redirect_to, string $action, array $post_ids, string $post_type, array $request ): string {
		$parsed = $this->parse_action( $action );
		if ( $parsed === null ) {
			return $redirect_to;
		}

		[ $operation, $name ] = $parsed;

		if ( ! $this->user_can_write( $post_type, $name ) ) {
			return $this->with_result( $redirect_to, 'denied', 0, 0 );
		}

		$related_id = isset( $request[ self::ARG_RELATED ] ) ? (int) $request[ self::ARG_RELATED ] : 0;
		if ( $related_id <= 0 ) {
			// No target chosen yet. Send the user back with the selection intact so
			// the screen can prompt for one, rather than failing the whole action.
			return $this->with_result( $redirect_to, 'needs_target', 0, 0 );
		}

		$applied = 0;
		$failed  = 0;

		foreach ( array_slice( $post_ids, 0, self::MAX_POSTS ) as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id <= 0 ) {
				continue;
			}

			// Per-post capability, not just per-relationship: a bulk selection can
			// span posts the user may not all edit.
			if ( function_exists( 'current_user_can' ) && ! current_user_can( 'edit_post', $post_id ) ) {
				++$failed;
				continue;
			}

			$result = $operation === 'attach'
				? $this->manager->attach( $post_id, $post_type, $name, $related_id )
				: $this->manager->detach( $post_id, $post_type, $name, $related_id );

			if ( $result instanceof \WP_Error ) {
				++$failed;
				continue;
			}

			++$applied;
		}

		return $this->with_result( $redirect_to, $operation, $applied, $failed );
	}

	/**
	 * Split a bulk action name into its operation and relationship.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function parse_action( string $action ): ?array {
		foreach ( [ 'attach', 'detach' ] as $operation ) {
			$prefix = 'saltus_' . $operation . '_';
			if ( strpos( $action, $prefix ) === 0 ) {
				$name = substr( $action, strlen( $prefix ) );

				return $name === '' ? null : [ $operation, $name ];
			}
		}

		return null;
	}

	/** Append the outcome to the redirect so the list screen can report it. */
	private function with_result( string $redirect_to, string $outcome, int $applied, int $failed ): string {
		if ( ! function_exists( 'add_query_arg' ) ) {
			return $redirect_to;
		}

		return add_query_arg(
			[
				self::ARG_RESULT => $outcome,
				'saltus_rel_ok'  => $applied,
				'saltus_rel_bad' => $failed,
			],
			$redirect_to
		);
	}

	/**
	 * Render the notice describing what a bulk run did.
	 *
	 * @param array<string, mixed> $request Query arguments, normally `$_GET`.
	 */
	public function render_notice( array $request ): void {
		$outcome = isset( $request[ self::ARG_RESULT ] ) ? (string) $request[ self::ARG_RESULT ] : '';
		if ( $outcome === '' ) {
			return;
		}

		if ( $outcome === 'denied' ) {
			$this->notice( 'error', __( 'You do not have permission to change that relationship.', 'saltus-framework' ) );
			return;
		}

		if ( $outcome === 'needs_target' ) {
			$this->notice( 'warning', __( 'Choose a post to attach or detach before running that bulk action.', 'saltus-framework' ) );
			return;
		}

		if ( $outcome !== 'attach' && $outcome !== 'detach' ) {
			return;
		}

		$applied = isset( $request['saltus_rel_ok'] ) ? (int) $request['saltus_rel_ok'] : 0;
		$failed  = isset( $request['saltus_rel_bad'] ) ? (int) $request['saltus_rel_bad'] : 0;

		$this->notice(
			$failed > 0 ? 'warning' : 'success',
			$this->summary( $outcome, $applied, $failed )
		);
	}

	/** Human-readable outcome for a completed bulk run. */
	private function summary( string $outcome, int $applied, int $failed ): string {
		$message = $outcome === 'attach'
			/* translators: %s: number of posts. */
			? sprintf( _n( 'Attached %s post.', 'Attached %s posts.', $applied, 'saltus-framework' ), number_format_i18n( $applied ) )
			/* translators: %s: number of posts. */
			: sprintf( _n( 'Detached %s post.', 'Detached %s posts.', $applied, 'saltus-framework' ), number_format_i18n( $applied ) );

		if ( $failed === 0 ) {
			return $message;
		}

		return $message . ' ' . sprintf(
			/* translators: %s: number of posts that could not be changed. */
			_n( '%s post could not be changed.', '%s posts could not be changed.', $failed, 'saltus-framework' ),
			number_format_i18n( $failed )
		);
	}

	/** Emit one admin notice. */
	private function notice( string $type, string $message ): void {
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/** Whether the current user may write one relationship. */
	private function user_can_write( string $post_type, string $name ): bool {
		$definition = $this->manager->get_definition( $post_type, $name );
		if ( ! $definition instanceof RelationshipDefinition ) {
			return false;
		}

		$capability = (string) $definition->get_capability();
		if ( $capability === '' ) {
			return true;
		}

		return ! function_exists( 'current_user_can' ) || current_user_can( $capability );
	}
}
