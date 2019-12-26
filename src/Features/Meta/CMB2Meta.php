<?php
namespace Saltus\WP\Framework\Features\Meta;

final class CMB2Meta {

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
		add_action( 'cmb2_admin_init', array( $this, 'init_fields' ), 2 );
	}

	public function init_fields() {

		/**
		 * Create Metaboxes
		 *
		 * Start by setting up metaboxes, which in turn will create sections or fields
		*/
		foreach ( $this->meta as $box ) {
			if ( empty( $box['fields'] ) && empty( $box['sections'] ) ) {
				continue;
			}
			$this->create_metabox( $box );
		}

		/**
		 * Create Settings pages
		*/
		foreach ( $this->settings as $settings_page ) {
			if ( empty( $box['fields'] ) && empty( $box['sections'] ) ) {
				continue;
			}
			$this->create_settings_page( $settings_page );
		}
	}

	private function create_metabox( $box ) {
		$args = array(
			'id'           => $box['id'],
			'title'        => $box['title'],
			'object_types' => $this->name,
			'context'      => 'normal',
			'priority'     => 'high',
			'show_names'   => true,
		);

		$cmb = \new_cmb2_box( $args );

		if ( isset( $box['fields'] ) ) {
			foreach ( $box['fields'] as $id => $field ) {
				$field = $this->prepare_field( $id, $field );
				$this->create_field( $cmb, $id, $field );
			}
			return;
		}

		// Tabs logic would go here
		foreach ( $box['sections'] as $section_id => $section ) {

			// while we don't have tabs, set a title to separate the sections
			$title_field = array(
				'name' => $section['title'],
				'desc' => $section['desc'],
				'type' => 'title',
				'id'   => $section_id,
			);
			$this->create_field( $cmb, $section_id, $title_field );

			foreach ( $section['fields'] as $id => $field ) {
				$field = $this->prepare_field( $id, $field );
				$this->create_field( $cmb, $id, $field );
			}
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

		if ( isset( $settings_page['fields'] ) ) {
			foreach ( $settings_page['fields'] as $id => $field ) {
				$field = $this->prepare_field( $id, $field );
				$this->create_field( $cmb, $id, $field );
			}
			return;
		}

		// Tabs logic would go here
		foreach ( $settings_page['sections'] as $section_id => $section ) {

			// while we don't have tabs, set a title to separate the sections
			$title_field = array(
				'name' => $section['title'],
				'desc' => $section['desc'],
				'type' => 'title',
				'id'   => $section_id,
			);
			$this->create_field( $cmb, $section_id, $title_field );

			foreach ( $section['fields'] as $id => $field ) {
				$field = $this->prepare_field( $id, $field );
				$this->create_field( $cmb, $id, $field );
			}
		}
	}

	private function create_field( $cmb, $id, $field ) {

		if ( $field['type'] === 'group' ) {
			$this->create_group_field( $cmb, $id, $field );
			return;
		}

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

	/**
	 * Create group field
	 * //TODO - Needs improving
	 *
	 * @param object $cmb - new_cmb2_box instance
	 * @param string $id - identifier for the group field
	 * @param array $field - array with field arguments
	 * @return void
	 */
	private function create_group_field( $cmb, $id, $field ) {

		$default_args = [
			'id'      => $id,
			'name'    => $id,
			'type'    => $group,
			'options' => array_diff_key( $field, array_flip( [ 'name', 'type', 'fields' ] ) ),
		];

		// set custom parameters considering the defaults
		$args = array_merge( $default_args, $field );

		$group_field_id = $cmb->add_field( $args );


		foreach ( $args['fields'] as $id => $field ) {
			$field = $this->prepare_field( $id, $field );
			$cmb->add_group_field(
				$group_field_id,
				$field
			);
		}

	}

	/**
	 * Prepare fields to make sure they have all necessary parameters
	 * //TODO - might need review for edge cases
	 *
	 * @param string $id identifier of the field
	 * @param array  $field array of field arguments
	 * @return array $fields array of prepared field to be rendered by CMB2
	 */
	private function prepare_field( $id, $field ) {

		// array for original => converted
		$keys = [
			'title' => 'name',
			'button_title' => 'add_button',
		];

		$values = [
			'code_editor' => 'textarea_code',
			'switcher' => 'checkbox',
		];

		// set keys and values to be recognized by CMB2
		foreach ($field as $key => $value) {
			$field[ strtr( $key, $keys ) ] = is_array( $value ) ? $value : strtr( $value, $values );
		}

		$field['id']   = $id;

		return $field;
	}

}
