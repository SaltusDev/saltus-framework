<?php

namespace Saltus\WP\Framework\Features\Relationships;

/**
 * Shows related posts as a column on the post list table.
 *
 * WordPress renders a custom column one post at a time, so resolving a
 * relationship inside the render callback would cost one query per row. The
 * whole result set is primed once from `the_posts` instead, through
 * `RelationshipManager::get_related_for_posts()`, which is the eager-loading
 * entry point the data layer already provides. Rendering then reads the primed
 * map and issues no query at all.
 *
 * @api
 */
final class RelationshipColumn {

	/** Related titles shown per cell before the rest are summarized. */
	private const VISIBLE_PER_CELL = 3;

	private RelationshipManager $manager;

	/**
	 * Primed related rows, keyed by relationship name then owning post id.
	 *
	 * @var array<string, array<int, list<array<string, mixed>>>>
	 */
	private array $primed = [];

	public function __construct( RelationshipManager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Add one column per relationship declared by this post type.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @param string                $post_type Post type being listed.
	 * @return array<string, string>
	 */
	public function add_columns( array $columns, string $post_type ): array {
		foreach ( $this->manager->describe( $post_type ) as $definition ) {
			$name = isset( $definition['name'] ) ? (string) $definition['name'] : '';
			if ( $name === '' || ! $this->user_can_read( $post_type, $name ) ) {
				continue;
			}

			$columns[ $this->column_key( $name ) ] = ucwords( str_replace( [ '_', '-' ], ' ', $name ) );
		}

		return $columns;
	}

	/**
	 * Resolve every relationship for a whole page of results in one query each.
	 *
	 * Returns the posts untouched — this is a filter used as a hook, because
	 * `the_posts` is the last point where the full result set is available before
	 * the table starts rendering rows.
	 *
	 * @param array<int, mixed> $posts     Posts about to be listed.
	 * @param string            $post_type Post type being listed.
	 * @return array<int, mixed>
	 */
	public function prime( array $posts, string $post_type ): array {
		$post_ids = [];
		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$post_ids[] = (int) $post->ID;
			}
		}

		if ( $post_ids === [] ) {
			return $posts;
		}

		foreach ( $this->manager->describe( $post_type ) as $definition ) {
			$name = isset( $definition['name'] ) ? (string) $definition['name'] : '';
			if ( $name === '' || ! $this->user_can_read( $post_type, $name ) ) {
				continue;
			}

			$this->primed[ $name ] = $this->manager->get_related_for_posts( $post_ids, $post_type, $name );
		}

		return $posts;
	}

	/**
	 * Render one cell.
	 *
	 * Reads only the primed map. A post absent from it means `prime()` never ran
	 * for this request — another plugin replacing the query, or a screen reached
	 * by a path that skips `the_posts`. That renders as empty rather than falling
	 * back to a per-row query, because a silent N+1 on an admin list is worse
	 * than a blank cell.
	 *
	 * @param string $column  Column key being rendered.
	 * @param int    $post_id Post for this row.
	 */
	public function render( string $column, int $post_id ): void {
		$name = $this->relationship_from_column( $column );
		if ( $name === '' || ! isset( $this->primed[ $name ] ) ) {
			return;
		}

		$rows = $this->primed[ $name ][ $post_id ] ?? [];
		if ( $rows === [] ) {
			echo '<span aria-hidden="true">—</span><span class="screen-reader-text">'
				. esc_html__( 'None', 'saltus-framework' ) . '</span>';
			return;
		}

		$links = [];
		foreach ( array_slice( $rows, 0, self::VISIBLE_PER_CELL ) as $row ) {
			$links[] = $this->linked_title( $row );
		}

		$remaining = count( $rows ) - count( $links );
		$output    = implode( ', ', $links );

		if ( $remaining > 0 ) {
			$output .= ' <span class="saltus-relationship-more">' . sprintf(
				/* translators: %s: number of additional related posts. */
				esc_html( _n( '+%s more', '+%s more', $remaining, 'saltus-framework' ) ),
				esc_html( number_format_i18n( $remaining ) )
			) . '</span>';
		}

		// Titles and hrefs are escaped as they are built in linked_title().
		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * One related post as a link to its editor, or plain text when unavailable.
	 *
	 * @param array<string, mixed> $row Projected relationship row.
	 */
	private function linked_title( array $row ): string {
		$related_id = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
		$title      = isset( $row['title'] ) && $row['title'] !== ''
			? (string) $row['title']
			: sprintf( '#%d', $related_id );

		$link = $related_id > 0 && function_exists( 'get_edit_post_link' )
			? get_edit_post_link( $related_id )
			: null;

		if ( ! is_string( $link ) || $link === '' ) {
			return esc_html( $title );
		}

		return '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>';
	}

	/** Column key for a relationship, namespaced so it cannot collide. */
	public function column_key( string $name ): string {
		return 'saltus_rel_' . $name;
	}

	/** Relationship name from a column key, or '' when the key is not ours. */
	private function relationship_from_column( string $column ): string {
		return strpos( $column, 'saltus_rel_' ) === 0 ? substr( $column, 11 ) : '';
	}

	/**
	 * Whether the current user may see one relationship.
	 *
	 * Both keys are consulted and both must pass. `capability` is the older
	 * admin-UI-only affordance and keeps that meaning; `capabilities.read` is the
	 * enforced rule `RelationshipPermissionPolicy` also applies on REST, MCP, and
	 * WP-CLI. Checking the policy here as well is not redundant: the column would
	 * otherwise advertise a relationship whose rows the manager returns empty,
	 * rendering a column that is always blank rather than not offering it.
	 */
	private function user_can_read( string $post_type, string $name ): bool {
		$definition = $this->manager->get_definition( $post_type, $name );
		if ( ! $definition instanceof RelationshipDefinition ) {
			return true;
		}

		if ( ! $this->manager->permissions()->can_read( $definition ) ) {
			return false;
		}

		$capability = (string) $definition->get_capability();
		if ( $capability === '' ) {
			return true;
		}

		return ! function_exists( 'current_user_can' ) || current_user_can( $capability );
	}
}
