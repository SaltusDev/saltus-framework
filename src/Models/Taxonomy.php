<?php
namespace Saltus\WP\Framework\Models;

class Taxonomy extends BaseModel implements Model {

	// data req for register_taxonomy()
	private $links = 'post';
	private $associations;

	/**
	 * Setup the data needed to register
	 */
	public function setup() {
		if ( $this->is_disabled() ) {
			return;
		}

		$this->set_options( $this->get_default_options() );

		$this->set_labels( $this->get_default_labels() );

		$this->set_associations( $this->get_default_associations() );

		$this->set_meta();

		$this->register();
	}

	/**
	 * Set config defaults
	 *
	 * Make public and change menu position
	 *
	 * @return array The list of config settings
	 */
	private function get_default_options() {
		$options = [];
		if ( ! $this->config->has( 'type' ) ) {
			return $options;
		}

		$config['hierarchical'] = false;
		if ( in_array( $this->config->get( 'type' ), [ 'cat', 'category' ], true ) ) {
			$config['hierarchical'] = true;
		}

		// show in rest api by default
		$config['show_in_rest'] = true;

		return $config;
	}

	/**
	 * Set default labels
	 *
	 * Create an labels array and implement default singular and plural labels
	 *
	 * @return array The list of Labels
	 */
	private function get_default_labels() {
		$labels = [
			'menu_name'                  => $this->many,
			'name'                       => $this->many,
			'singular_name'              => $this->one,
			'search_items'               => 'Search ' . $this->many,
			'popular_items'              => 'Popular ' . $this->many,
			'all_items'                  => 'All ' . $this->many,
			'parent_item'                => 'Parent ' . $this->one,
			'parent_item_colon'          => 'Parent ' . $this->one . ':',
			'edit_item'                  => 'Edit ' . $this->one,
			'view_item'                  => 'View ' . $this->one,
			'update_item'                => 'Update ' . $this->one,
			'add_new_item'               => 'Add New ' . $this->one,
			'new_item_name'              => 'New ' . $this->one . ' Name',
			'separate_items_with_commas' => 'Separate ' . strtolower( $this->many ) . ' with commas',
			'add_or_remove_items'        => 'Add or remove ' . strtolower( $this->many ),
			'choose_from_most_used'      => 'Choose from the most used ' . strtolower( $this->many ),
			'not_found'                  => 'No ' . strtolower( $this->many ) . ' found.',
			'no_terms'                   => 'No ' . strtolower( $this->many ),
			'items_list_navigation'      => $this->many . ' list navigation',
			'items_list'                 => $this->many . ' list',
		];

		return $labels;
	}

	private function get_default_associations() {
		return [];
	}

	/**
	 * Set Object types association to this taxonomy
	 */
	private function set_associations( array $associations ) {
		if ( ! $this->config->has( 'associations' ) ) {
			$this->associations = $associations;
			return;
		}

		$custom_associations = $this->config->get( 'associations' );
		if ( is_array( $custom_associations ) ) {
			$this->associations = array_replace( $associations, $custom_associations );
			return;
		}
		$this->associations = $custom_associations;
	}

	/**
	 *
	 *
	 */
	private function set_meta() {

		$meta = [];
		if ( $this->config->has( 'meta' ) ) {
			$meta = $this->config->get( 'meta' );
		}
		$this->args['meta'] = $meta;
	}

	/**
	 * Register Taxonomy
	 *
	 * Uses extended-cpts if available.
	 *
	 * @see https://github.com/johnbillion/extended-cpts
	 *
	 * @return void
	 */
	private function register() {
		$args = array_merge( $this->args, $this->options );
		register_taxonomy( $this->name, $this->associations, $args );
		add_action( 'init', array( $this, 'register_associations' ) );
	}

	public function register_associations() {
		if ( $this->associations === null || ! is_array( $this->associations ) ) {
			return;
		}

		foreach ( $this->associations as $association ) {
			register_taxonomy_for_object_type( $this->name, $association );
		}
	}

	/**
	 * Get the type of the model
	 *
	 * @return string The type of Model
	 */
	public function get_type() {
		return 'taxonomy';
	}
}
