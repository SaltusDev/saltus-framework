<?php
namespace Saltus\WP\Framework\Features\Settings;

final class CodestarSettings {

	private $name;
	private $project;
	private $settings;


	public function __construct( string $name, array $project, array $settings = array() ) {

		$this->name     = $name;
		$this->project  = $project;
		$this->settings = $settings;

		$this->init();
	}

	private function init() {

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

			// Each field array needs to have an id,
			// but only if it wasn't provided
			$field_id = $field['id'] ?? $key;

			$fields[ $key ]['id'] = $field_id;

			if ( isset( $field['fields'] ) ) {

				$field['fields'] = $this->prepare_fields( $field['fields'] );

			}
		}

		// codestar framework 'prefers' keys to be a numeric index, so we return the array_values
		return array_values( $fields );
	}


}
