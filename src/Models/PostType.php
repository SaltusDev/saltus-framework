<?php
namespace Saltus\WP\Framework\Models;

class PostType extends BaseModel implements Model {

	/**
	 * Setup the data needed to register
	 */
	public function setup() {
		if ( $this->is_disabled() ) {
			return;
		}

		$this->set_options( $this->get_default_options() );

		$this->set_labels( $this->get_default_labels() );

		$this->set_ui_labels( $this->ui_labels );

		$this->set_updated_messages();

		$this->set_meta();

		$this->register();
	}

	/**
	 * Get default Options
	 *
	 * Turn it public, change menu position and add `supports` list.
	 *
	 * @return array The list of options settings
	 */
	protected function get_default_options() {
		$options = [
			'public'        => true,
			'menu_position' => 5,
			'show_in_rest'  => true,
		];

		if ( $this->config->has( 'supports' ) ) {
			$options['supports'] = $this->config->get( 'supports' );
		}
		return $options;
	}


	/**
	 *
	 *
	 */
	protected function set_meta() {

		$meta = [];
		if ( $this->config->has( 'meta' ) ) {
			$meta = $this->config->get( 'meta' );
		}
		$this->args['meta'] = $meta;
	}

	/**
	 * Checks if has any meta fields
	 */
	public function has_meta() {

		return count( $this->args['meta'] ) > 0;
	}


	/**
	 * Set default labels
	 *
	 * Create an labels array and implement default singular and plural labels
	 *
	 * @return array The list of Labels
	 */
	protected function get_default_labels() {

		$many_lower = strtolower( $this->many );
		$one_lower  = strtolower( $this->one );

		$labels = [
			'name'                  => $this->many,
			'singular_name'         => $this->one,
			'menu_name'             => $this->many,
			'name_admin_bar'        => $this->one,
			'add_new_item'          => 'Add New ' . $this->one,
			'edit_item'             => 'Edit ' . $this->one,
			'new_item'              => 'New ' . $this->one,
			'view_item'             => 'View ' . $this->one,
			'view_items'            => 'View ' . $this->many,
			'search_items'          => 'Search ' . $this->many,
			'not_found'             => 'No ' . $many_lower . ' found.',
			'not_found_in_trash'    => 'No ' . $many_lower . ' found in Trash',
			'parent_item_colon'     => 'Parent ' . $this->many . ':',
			'all_items'             => 'All ' . $this->many,
			'archives'              => $this->one . ' Archives',
			'attributes'            => $this->one . ' Attributes',
			'insert_into_item'      => 'Insert into ' . $one_lower,
			'uploaded_to_this_item' => 'Uploaded to this ' . $one_lower,
			'filter_items_list'     => 'Filter ' . $many_lower . ' list',
			'items_list_navigation' => $this->many . ' list navigation',
			'items_list'            => $this->many . ' list',
		];

		if ( ! empty( $this->featured_image ) ) {
			$featured_image_low              = strtolower( $this->featured_image );
			$labels['featured_image']        = $this->featured_image;
			$labels['set_featured_image']    = sprintf( 'Set %s', $featured_image_low );
			$labels['remove_featured_image'] = sprintf( 'Remove %s', $featured_image_low );
			$labels['use_featured_image']    = sprintf( 'Use as %s', $featured_image_low );
		}

		return $labels;
	}

	/**
	 * Register Post Type
	 *
	 * @return void
	 */
	protected function register() {
		$args = array_merge( $this->args, $this->options );
		register_post_type( $this->name, $args );
	}

	/**
	 * Get the type of the model
	 *
	 * @return string The type of Model
	 */
	public function get_type() {
		return 'post_type';
	}

	/**
	 * Adds filters to change post update messages
	 * TODO: accept overrides
	 *
	 * @return void
	 */
	protected function set_updated_messages() {

		add_filter( 'post_updated_messages', [ $this, 'post_updated_messages' ], 1 );
		add_filter( 'bulk_post_updated_messages', [ $this, 'bulk_post_updated_messages' ], 1, 2 );

	}

	/**
	 * Adds filters to change the ui labels
	 *
	 * @param array $ui_labels
	 * @return void
	 */
	protected function set_ui_labels( array $ui_labels ) {

		if ( empty( $ui_labels ) ) {
			return;
		}

		// change 'enter title here' placeholder
		if ( isset( $ui_labels['enter_title_here'] ) ) {
			add_filter( 'enter_title_here', [ $this, 'enter_title_here' ], 5, 2 );
		}
	}

	/**
	 * Disable block editor for this custom post type
	 *
	 * @return boolean status
	 */
	public function disable_block_editor( $current_status, $post_type ) {
		if ( $post_type === $this->name ) {
			return false;
		}
		return $current_status;
	}

	/**
	 * Sets the placeholder text for the title field for this post type.
	 *
	 * @param string  $title The placeholder text.
	 * @param WP_Post $post  The current post.
	 * @return string The updated placeholder text.
	 */
	public function enter_title_here( string $title, \WP_Post $post ) : string {
		if ( $this->name !== $post->post_type ) {
			return $title;
		}

		return $this->ui_labels['enter_title_here'];
	}

}
