<?php
namespace Saltus\WP\Framework\Models;

class PostType extends Model {

	// data req for register_post_type()
	public function setup() {
		if ( $this->isDisabled() ) {
			return;
		}

		$this->setConfig( $this->getDefaultConfig() );

		$this->setLabels( $this->getDefaultLabels() );

		$this->register();
	}

	/**
	 * Get config defaults
	 *
	 * Make public and change menu position
	 *
	 * @return array The list of config settings
	 */
	protected function getDefaultConfig() {
		$config = [
			'public'        => true,
			'menu_position' => 5,
		];

		if ( $this->data->has( 'supports' ) ) {
			$config['supports'] = $this->data->get( 'supports' );
		}
		return $config;
	}

	/**
	 * Set default labels
	 *
	 * Create an labels array and implement default singular and plural labels
	 *
	 * @return array The list of Labels
	 */
	protected function getDefaultLabels() {

		$many_lower = strtolower( $this->many );
		$one_lower  = strtolower( $this->one );

		$labels = [
			'name'                  => $this->many,
			'singular_name'         => _x( $this->one, 'Post type singular name', $this->i18n ),
			'menu_name'             => _x( $this->many, 'Admin Menu text', $this->i18n ),
			'name_admin_bar'        => _x( $this->one, 'Add New on Toolbar', $this->i18n ),
			'add_new_item'          => __( 'Add New ' . $this->one, $this->i18n ),
			'edit_item'             => __( 'Edit ' . $this->one, $this->i18n ),
			'new_item'              => __( 'New ' . $this->one, $this->i18n ),
			'view_item'             => __( 'View ' . $this->one, $this->i18n ),
			'view_items'            => __( 'View ' . $this->many, $this->i18n ),
			'search_items'          => __( 'Search ' . $this->many, $this->i18n ),
			'not_found'             => __( 'No ' . $many_lower . ' found.', $this->i18n ),
			'not_found_in_trash'    => __( 'No ' . $many_lower . ' found in Trash.', $this->i18n ),
			'parent_item_colon'     => __( 'Parent ' . $this->many . ':', $this->i18n ),
			'all_items'             => __( 'All ' . $this->many, $this->i18n ),
			'archives'              => __( $this->one . ' Archives', $this->i18n ),
			'attributes'            => __( $this->one . ' Attributes', $this->i18n ),
			'insert_into_item'      => __( 'Insert into ' . $one_lower, $this->i18n ),
			'uploaded_to_this_item' => __( 'Uploaded to this ' . $one_lower, $this->i18n ),
			'filter_items_list'     => __( 'Filter ' . $many_lower . ' list', $this->i18n ),
			'items_list_navigation' => __( $this->many . ' list navigation', $this->i18n ),
			'items_list'            => __( $this->many . ' list', $this->i18n ),
		];

		return $labels;
	}

	/**
	 * Register Post Type
	 *
	 * Uses extended-cpts if available.
	 * @see https://github.com/johnbillion/extended-cpts
	 *
	 * @return void
	 */
	protected function register() {
		$args = array_merge( $this->args, $this->config );

		if ( function_exists( 'register_extended_post_type' ) ) {
			register_extended_post_type( $this->name, $args );
			return;
		}
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
}

