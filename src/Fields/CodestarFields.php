<?php
namespace Saltus\WP\Framework\Fields;

final class CodestarFields {

	private $name;
	private $meta;
	private $settings;


	/**
	 * Instantiate the Codestar Framework Fields object.
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
		 * Create Metaboxes
		 */
		foreach ( $this->meta as $box_id => $box ) {
			if ( empty( $box['fields'] ) && empty( $box['sections'] ) ) {
				continue;
			}

			// else add just the fields
			$this->create_metabox( $box_id, $box );
		}

		/**
		 * Create Settings pages
		*/
		foreach ( $this->settings as $settings_id => $settings_page ) {
			if ( empty( $settings_page['fields'] ) && empty( $settings_page['sections'] ) ) {
				continue;
			}

			// else add just the fields
			$this->create_settings_page( $settings_id, $settings_page );
		}
	}

	/**
	 * Create settings page
	 *
	 * @param int   $settings_id identifier of the settings
	 * @param array $settings_page paramaters for the page
	 * @return void
	 */
	private function create_settings_page( $settings_id, $settings_page ) {

		$default_args = array(
			'menu_slug'       => $settings_id,
			'menu_parent'     => 'edit.php?post_type=' . $this->name,
			'menu_type'       => 'submenu',
			'theme'           => 'light',
			'footer_credit'   => ' ', // removes codestar default credit
			'framework_title' => isset( $settings_page['title'] ) ? $settings_page['title'] : '',
		);

		$args = array_merge( $default_args, $settings_page );

		// Create options
		\CSF::createOptions( $settings_id, $args );

		if ( isset( $settings_page['fields'] ) ) {
			$settings_page['fields'] = $this->prepare_fields( $settings_page['fields'] );
			$this->create_section( $settings_id, $settings_page );
			return;
		}

		if ( isset( $settings_page['sections'] ) ) {
			foreach ( $settings_page['sections'] as $section ) {
				$section['fields'] = $this->prepare_fields( $section['fields'] );
				$this->create_section( $settings_id, $section );
			}
		}

	}

	/**
	 * Create metabox
	 *
	 * @param int   $box_id identifier of the metabox
	 * @param array $box_settings paramaters for the page
	 * @return void
	 */
	private function create_metabox( $box_id, $box_settings ) {

		$default_args = array(
			'post_type' => $this->name,
			'priority'  => 'high',
			'context'   => 'normal',
			'theme'     => 'light',
			'data_type' => 'unserialize',
		);

		$args = array_merge( $default_args, $box_settings );

		// Create options
		\CSF::createMetabox( $box_id, $args );

		if ( isset( $box_settings['fields'] ) ) {

			$box_settings['fields'] = $this->prepare_fields( $box_settings['fields'] );

			$this->create_section( $box_id, $box_settings );
			return;
		}

		if ( isset( $box_settings['sections'] ) ) {
			foreach ( $box_settings['sections'] as $section ) {

				$section['fields'] = $this->prepare_fields( $section['fields'] );
				$this->create_section( $box_id, $section );
			}
		}

	}

	/**
	 * Create section using builtin Codestart method
	 *
	 * @param string $id - identifier of the section
	 * @param array  $section - parameters for the section
	 * @return void
	 */
	private function create_section( $id, $section ) {

		\CSF::createSection( $id, $section );

	}

	/**
	 * Prepare fields to make sure they have all necessary parameters
	 *
	 * @param array $fields
	 * @return array $fields array of fields prepared to be rendered by CodestarFields
	 */
	private function prepare_fields( $fields ) {

		foreach ( $fields as $key => &$field ) {

			$fields[ $key ]['id'] = $key;

			if ( isset( $field['fields'] ) ) {

				$field['fields'] = $this->prepare_fields( $field['fields'] );

			}
		}

		return $fields;
	}


}
