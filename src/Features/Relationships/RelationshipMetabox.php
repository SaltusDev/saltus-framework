<?php

namespace Saltus\WP\Framework\Features\Relationships;

/**
 * Renders and saves the relationship picker on the post editor.
 *
 * One component serves all four cardinalities. `has_one` and `belongs_to` are
 * the same picker with a cap of one, not a separate single-select control: two
 * implementations of the same thing drift, and the cap is already enforced
 * server-side by `RelationshipManager`, so the UI cap is a convenience rather
 * than the guard.
 *
 * Saving goes straight to `RelationshipManager::sync()`, which is what
 * `RelationshipsController::sync_items()` and `RelationshipCommand::sync()` also
 * do. The editorial review queue deliberately does not sit in front of this
 * path: queueing lives one layer above the manager, in `AbilityRuntime` for MCP
 * and `WebMcp` for browser agents, and it exists because an agent caller may be
 * autonomous. An editor saving a post has already cleared a WordPress
 * capability check, and that is the gate here — the same posture REST and
 * WP-CLI have.
 *
 * @api
 */
final class RelationshipMetabox {

	/** Nonce action for the picker's save. */
	private const NONCE_ACTION = 'saltus_relationships_save';

	/** Nonce field name. */
	private const NONCE_NAME = 'saltus_relationships_nonce';

	/** Cap on ids accepted from one submitted field, matching the manager's own sync cap. */
	private const MAX_IDS = 200;

	private RelationshipManager $manager;

	public function __construct( RelationshipManager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Register the picker for every post type declaring a relationship the caller may read.
	 *
	 * @param string $post_type Post type being edited.
	 */
	public function add_meta_boxes( string $post_type ): void {
		if ( ! $this->manager->has_readable_relationships( $post_type ) ) {
			return;
		}

		if ( ! function_exists( 'add_meta_box' ) ) {
			return;
		}

		add_meta_box(
			'saltus-relationships',
			__( 'Relationships', 'saltus-framework' ),
			[ $this, 'render' ],
			$post_type,
			'normal',
			'default'
		);
	}

	/**
	 * Render one field per relationship declared by this post's type.
	 *
	 * Typed `mixed` because WordPress invokes a metabox callback with whatever it
	 * has, and the guard below is the only thing that makes it a post.
	 *
	 * @param mixed $post Post being edited.
	 */
	public function render( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$post_type   = (string) $post->post_type;
		$definitions = $this->manager->describe( $post_type );
		if ( $definitions === [] ) {
			return;
		}

		if ( function_exists( 'wp_nonce_field' ) ) {
			wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		}

		echo '<div class="saltus-relationships">';

		foreach ( $definitions as $definition ) {
			$this->render_field( $post, $post_type, $definition );
		}

		echo '</div>';
	}

	/**
	 * Render the control for a single relationship.
	 *
	 * @param \WP_Post             $post       Post being edited.
	 * @param string               $post_type  Post type being edited.
	 * @param array<string, mixed> $definition One entry from `describe()`.
	 */
	private function render_field( \WP_Post $post, string $post_type, array $definition ): void {
		$name = isset( $definition['name'] ) ? (string) $definition['name'] : '';
		if ( $name === '' ) {
			return;
		}

		// `to_array()` carries no capability, so resolve the definition object for
		// the gate rather than trusting a key that is not in the projection.
		$definition_object = $this->manager->get_definition( $post_type, $name );
		$capability        = $definition_object instanceof RelationshipDefinition
			? (string) $definition_object->get_capability()
			: '';
		if ( $capability !== '' && function_exists( 'current_user_can' ) && ! current_user_can( $capability ) ) {
			return;
		}

		// Read and write are separate verdicts, so there are three outcomes rather
		// than two: absent, read-only, or editable. Rendering a write-denied
		// relationship as read-only is the whole reason the split exists — collapsing
		// it back to "hidden" would make `capabilities` no more expressive than the
		// single `capability` key it sits beside.
		$editable = ! $definition_object instanceof RelationshipDefinition
			|| $this->manager->permissions()->can_write( $definition_object );

		$multiple = ! empty( $definition['multiple'] );
		$target   = isset( $definition['to'] ) ? (string) $definition['to'] : '';
		$field_id = 'saltus-rel-' . $name;
		$desc_id  = $field_id . '-desc';

		$selected = $this->manager->get_related( (int) $post->ID, $post_type, $name );

		if ( ! $editable ) {
			$this->render_readonly_field( $name, $selected );
			return;
		}

		// The definition has no label of its own; derive a readable one from the
		// relationship name so the <label for> has something meaningful to say.
		$label = ucwords( str_replace( [ '_', '-' ], ' ', $name ) );

		$description = $multiple
			/* translators: %s: target post type name. */
			? sprintf( __( 'Search and select %s. Drag to reorder.', 'saltus-framework' ), $target )
			/* translators: %s: target post type name. */
			: sprintf( __( 'Search and select one %s.', 'saltus-framework' ), $target );

		echo '<div class="saltus-relationship-field">';

		printf(
			'<label class="saltus-relationship-label" for="%s">%s</label>',
			esc_attr( $field_id ),
			esc_html( $label )
		);

		printf(
			'<p class="description saltus-relationship-desc" id="%s">%s</p>',
			esc_attr( $desc_id ),
			esc_html( $description )
		);

		printf(
			'<div class="saltus-relationship-control" data-saltus-relationship="%s" data-post-type="%s" data-target="%s" data-multiple="%s" data-post="%d">',
			esc_attr( $name ),
			esc_attr( $post_type ),
			esc_attr( $target ),
			$multiple ? '1' : '0',
			(int) $post->ID
		);

		printf(
			'<input type="search" class="saltus-relationship-search" id="%s" aria-describedby="%s" autocomplete="off" placeholder="%s" />',
			esc_attr( $field_id ),
			esc_attr( $desc_id ),
			esc_attr__( 'Search…', 'saltus-framework' )
		);

		// Results are announced as they arrive, so a screen-reader user hears the
		// count change instead of silently receiving new DOM.
		printf(
			'<ul class="saltus-relationship-results" role="listbox" aria-label="%s" hidden></ul>',
			esc_attr__( 'Search results', 'saltus-framework' )
		);

		printf(
			'<ul class="saltus-relationship-selected" aria-label="%s">',
			esc_attr__( 'Selected items', 'saltus-framework' )
		);
		foreach ( $selected as $row ) {
			$this->render_selected_item( $name, $row );
		}
		echo '</ul>';

		printf(
			'<p class="saltus-relationship-status" role="status" aria-live="polite"></p>'
		);

		echo '</div></div>';
	}

	/**
	 * Render a relationship the user may see but not change.
	 *
	 * Three omissions matter. There is no `data-saltus-relationship` attribute, which
	 * is what the picker script binds to, so no interactive control is attached rather
	 * than one being attached and then disabled — a disabled control is still a
	 * control, and re-enabling it in devtools must not produce a submittable field.
	 * There are no hidden inputs, so nothing is submitted for this relationship. And
	 * there is no remove button. `save()` skips it regardless, but the markup should
	 * not depend on that for its correctness.
	 *
	 * Rendered as a plain list rather than a disabled picker so a screen reader
	 * announces values instead of a form control the user cannot operate.
	 *
	 * @param string                     $name     Relationship name.
	 * @param list<array<string, mixed>> $selected Currently related rows.
	 */
	private function render_readonly_field( string $name, array $selected ): void {
		$label = ucwords( str_replace( [ '_', '-' ], ' ', $name ) );

		echo '<div class="saltus-relationship-field saltus-relationship-field--readonly">';

		printf(
			'<span class="saltus-relationship-label">%s</span>',
			esc_html( $label )
		);

		printf(
			'<p class="description saltus-relationship-desc">%s</p>',
			esc_html__( 'You can view these but not change them.', 'saltus-framework' )
		);

		if ( $selected === [] ) {
			// Matches the list-table empty cell: the dash is decorative and hidden
			// from assistive technology, which gets the word instead.
			printf(
				'<p class="saltus-relationship-empty"><span aria-hidden="true">&mdash;</span><span class="screen-reader-text">%s</span></p>',
				esc_html__( 'None', 'saltus-framework' )
			);
			echo '</div>';
			return;
		}

		printf(
			'<ul class="saltus-relationship-selected saltus-relationship-selected--readonly" aria-label="%s">',
			esc_attr__( 'Related items', 'saltus-framework' )
		);

		foreach ( $selected as $row ) {
			$related_id = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
			if ( $related_id <= 0 ) {
				continue;
			}

			$title = isset( $row['title'] ) && $row['title'] !== ''
				? (string) $row['title']
				: sprintf( '#%d', $related_id );

			printf(
				'<li class="saltus-relationship-item"><span class="saltus-relationship-item-title">%s</span></li>',
				esc_html( $title )
			);
		}

		echo '</ul></div>';
	}

	/**
	 * Render one already-selected row, carrying its id in a submitted input.
	 *
	 * The hidden input is the source of truth on save: the JS reorders and
	 * removes these, and `save()` reads them in document order, so ordering
	 * survives without a separate order field to keep in step.
	 *
	 * @param string               $name Relationship name.
	 * @param array<string, mixed> $row  Projected relationship row.
	 */
	private function render_selected_item( string $name, array $row ): void {
		$related_id = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
		if ( $related_id <= 0 ) {
			return;
		}

		$title = isset( $row['title'] ) && $row['title'] !== ''
			? (string) $row['title']
			: sprintf( '#%d', $related_id );

		echo '<li class="saltus-relationship-item" data-id="' . esc_attr( (string) $related_id ) . '">';

		echo '<span class="saltus-relationship-item-title">' . esc_html( $title ) . '</span>';

		printf(
			'<button type="button" class="button-link saltus-relationship-remove" aria-label="%s">%s</button>',
			/* translators: %s: post title. */
			esc_attr( sprintf( __( 'Remove %s', 'saltus-framework' ), $title ) ),
			esc_html__( 'Remove', 'saltus-framework' )
		);

		printf(
			'<input type="hidden" name="saltus_relationships[%s][]" value="%s" />',
			esc_attr( $name ),
			esc_attr( (string) $related_id )
		);

		echo '</li>';
	}

	/**
	 * Persist the submitted sets for a post.
	 *
	 * Every guard here is a reason not to write, and each returns rather than
	 * falling through: an autosave, a revision, a missing nonce, or a caller
	 * without the capability must leave stored relationships untouched rather
	 * than sync an empty set over them.
	 *
	 * @param int   $post_id Post being saved.
	 * @param array<string, mixed> $request Submitted payload, normally `$_POST`.
	 */
	public function save( int $post_id, array $request ): void {
		if ( ! $this->submission_is_actionable( $post_id, $request ) ) {
			return;
		}

		$post      = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
		$post_type = $post instanceof \WP_Post ? (string) $post->post_type : '';
		if ( $post_type === '' ) {
			return;
		}

		$submitted = isset( $request['saltus_relationships'] ) && is_array( $request['saltus_relationships'] )
			? $request['saltus_relationships']
			: [];

		foreach ( $this->manager->describe( $post_type ) as $definition ) {
			$name = isset( $definition['name'] ) ? (string) $definition['name'] : '';
			if ( $name === '' || ! $this->user_can_edit_relationship( $post_type, $name ) ) {
				continue;
			}

			// Ids are passed through as submitted. `RelationshipManager::sync()`
			// runs them through `PostIdListTrait::post_id_list()`, which casts to
			// int, drops anything not `> 0`, and deduplicates while preserving
			// order — so a second filter here would be dead code that looks load
			// bearing. The cap is applied because that is the one thing the trait
			// does not do: `sync()` rejects an oversized list outright, and
			// silently refusing an editor's save is worse than trimming it.
			$ids = isset( $submitted[ $name ] ) && is_array( $submitted[ $name ] )
				? array_slice( array_values( $submitted[ $name ] ), 0, self::MAX_IDS )
				: [];

			$this->manager->sync( $post_id, $post_type, $name, $ids );
		}
	}

	/**
	 * Whether this save carries a picker submission that should be applied.
	 *
	 * @param int                  $post_id Post being saved.
	 * @param array<string, mixed> $request Submitted payload.
	 */
	private function submission_is_actionable( int $post_id, array $request ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) {
			return false;
		}

		// A post can be saved by paths that never render the metabox — a REST
		// update, a WP-CLI call, another plugin. Absent the nonce field there is
		// no picker submission to apply, and a missing key is not an empty set.
		if ( ! isset( $request[ self::NONCE_NAME ] ) ) {
			return false;
		}

		if ( function_exists( 'wp_verify_nonce' )
			&& ! wp_verify_nonce( (string) $request[ self::NONCE_NAME ], self::NONCE_ACTION ) ) {
			return false;
		}

		return ! ( function_exists( 'current_user_can' ) && ! current_user_can( 'edit_post', $post_id ) );
	}

	/**
	 * Whether the current user may write one relationship.
	 *
	 * A relationship the user could not see must not be cleared by their save:
	 * `render_field()` skips it, so it is absent from the payload, which is
	 * indistinguishable from "emptied" without this check.
	 */
	private function user_can_edit_relationship( string $post_type, string $name ): bool {
		$definition = $this->manager->get_definition( $post_type, $name );
		if ( ! $definition instanceof RelationshipDefinition ) {
			return true;
		}

		// A read-only render submits no inputs, so without this the relationship
		// would look emptied and be cleared — the same failure the absent-nonce
		// guard prevents for the whole picker, one relationship at a time.
		if ( ! $this->manager->permissions()->can_write( $definition ) ) {
			return false;
		}

		$capability = (string) $definition->get_capability();
		if ( $capability === '' ) {
			return true;
		}

		return ! function_exists( 'current_user_can' ) || current_user_can( $capability );
	}
}
