<?php
namespace Saltus\WP\Framework\Models;

/**
 * Taxonomy Model
 *
 * This model is used to register a custom taxonomy
 *
 * @see https://developer.wordpress.org/reference/functions/register_taxonomy/
 */
class Taxonomy extends BaseModel implements Model {

	// data req for register_taxonomy()
	/** @var array<int, string>|string */
	private $associations = [];

	/**
	 * Setup the data needed to register
	 */
	public function setup(): void {
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
	 * @return array<string, mixed> The list of config settings
	 */
	private function get_default_options(): array {
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
	 * @return array<string, string> The list of Labels
	 */
	private function get_default_labels(): array {
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

	/**
	 * @return array<int, string>
	 */
	private function get_default_associations(): array {
		return [];
	}

	/**
	 * Set Object types association to this taxonomy
	 */
	/**
	 * @param array<int, string> $associations
	 */
	private function set_associations( array $associations ): void {
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
	 * Set meta fields
	 */
	private function set_meta(): void {

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
	private function register(): void {
		$args = array_merge( $this->args, $this->options );
		register_taxonomy( $this->get_registration_name(), $this->associations, $args );
		add_action( 'init', array( $this, 'register_associations' ) );
	}

	public function register_associations(): void {
		if ( ! is_array( $this->associations ) ) {
			return;
		}

		foreach ( $this->associations as $association ) {
			register_taxonomy_for_object_type( $this->get_registration_name(), $association );
		}
	}

	/**
	 * Get the type of the model
	 *
	 * @return string The type of Model
	 */
	public function get_type(): string {
		return 'taxonomy';
	}
}
