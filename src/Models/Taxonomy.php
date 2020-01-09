<?php
namespace Saltus\WP\Framework\Models;

class Taxonomy extends BaseModel implements Model {

	// data req for register_taxonomy()
	protected $links = 'post';

	/**
	 * Setup the data needed to register
	 *
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
	protected function get_default_options() {
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
	protected function get_default_labels() {
		$labels = [
			'name'                       => _x( $this->many, 'Taxonomy general name', $this->i18n ),
			'singular_name'              => _x( $this->one, 'Taxonomy singular name', $this->i18n ),
			'search_items'               => __( 'Search ' . $this->many, $this->i18n ),
			'popular_items'              => __( 'Popular ' . $this->many, $this->i18n ),
			'all_items'                  => __( 'All ' . $this->many, $this->i18n ),
			'parent_item'                => __( 'Parent ' . $this->one, $this->i18n ),
			'parent_item_colon'          => __( 'Parent ' . $this->one . ':', $this->i18n ),
			'edit_item'                  => __( 'Edit ' . $this->one, $this->i18n ),
			'view_item'                  => __( 'View ' . $this->one, $this->i18n ),
			'update_item'                => __( 'Update ' . $this->one, $this->i18n ),
			'add_new_item'               => __( 'Add New ' . $this->one, $this->i18n ),
			'new_item_name'              => __( 'New ' . $this->one . ' Name', $this->i18n ),
			'separate_items_with_commas' => __( 'Separate ' . strtolower( $this->many ) . ' with commas', $this->i18n ),
			'add_or_remove_items'        => __( 'Add or remove ' . strtolower( $this->many ), $this->i18n ),
			'choose_from_most_used'      => __( 'Choose from the most used ' . strtolower( $this->many ), $this->i18n ),
			'not_found'                  => __( 'No ' . strtolower( $this->many ) . ' found.', $this->i18n ),
			'no_terms'                   => __( 'No ' . strtolower( $this->many ), $this->i18n ),
			'items_list_navigation'      => __( $this->many . ' list navigation', $this->i18n ),
			'items_list'                 => __( $this->many . ' list', $this->i18n ),
		];

		return $labels;
	}

	protected function get_default_associations() {
		return [];
	}

	/**
	 * Set Object types association to this taxonomy
	 *
	 */
	protected function set_associations( array $associations ) {
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
	protected function set_meta() {

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
	 * @see https://github.com/johnbillion/extended-cpts
	 *
	 * @return void
	 */
	protected function register() {
		$args = array_merge( $this->args, $this->options );

		if ( function_exists( 'register_extended_taxonomy' ) ) {
			register_extended_taxonomy( $this->name, $this->associations, $args );
			return;
		}
		register_taxonomy( $this->name, $this->associations, $args );
		add_action( 'init', array( $this, 'register_associations' ) );
	}

	public function register_associations() {
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
