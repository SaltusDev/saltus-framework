<?php
namespace Saltus\WP\Framework\Fields;

final class CMB2Fields {

	private $name;
	private $meta;


	/**
	 * Instantiate the CMB2 Fields object.
	 */
	public function __construct() {

	}

	public function setup( $name, $meta = array(), $settings = array() ) {

		$this->name     = $name;
		$this->meta     = $meta;
		$this->settings = $settings;
	}

	public function init() {

		/**
		 * Create Fields
		 *
		 * Start by setting up Sections, which in turn will create fields
		*/
		foreach ( $this->meta as $section ) {
			if ( empty( $section['fields'] ) ) {
				continue;
			}
			$this->create_section( $section );
			// TODO by pcarvalho: can meta have only fields without a section?
		}

		/**
		 * Create Settings pages
		*/
		foreach ( $this->settings as $settings_page ) {
			if ( empty( $settings_page['fields'] ) ) {
				continue;
			}
			$this->create_settings_page( $settings_page );
		}
	}

	private function create_section( $section ) {
		$args = array(
			'id'           => $section['id'],
			'title'        => $section['title'],
			'object_types' => $this->name,
			'context'      => 'normal',
			'priority'     => 'high',
			'show_names'   => true,
		);

		$cmb = \new_cmb2_box( $args );
		foreach ( $section['fields'] as $id => $fields ) {
			$this->create_field( $cmb, $id, $fields );
		}
	}

	private function create_settings_page( $settings_page ) {

		$default_args = array(
			'id'           => $settings_page['id'],
			'object_types' => array( 'options-page' ),
			'option_key'   => $settings_page['id'],
			'capability'   => 'manage_options',
			'parent_slug'  => 'edit.php?post_type=' . $this->name,
		);

		$args = array_merge( $default_args, $settings_page );

		$cmb = \new_cmb2_box( $args );
		foreach ( $settings_page['fields'] as $id => $fields ) {
			$this->create_field( $cmb, $id, $fields );
		}
	}

	private function create_field( $cmb, $id, $field ) {

		// set default args
		$default_args = array(
			'id'   => $id,
			'name' => $id,
			'type' => 'text',
		);

		// set custom parameters considering the defaults
		$args = array_merge( $default_args, $field );

		$cmb->add_field( $args );
	}

}
