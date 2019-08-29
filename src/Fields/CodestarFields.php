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
		 * Create Fields
		 *
		 * Start by setting up Sections, which in turn will create fields
		*/
		/*foreach ( $this->meta as $section ) {
			if ( empty( $section['fields'] ) ) {
				continue;
			}
			$this->create_section( $section );
			// TODO by pcarvalho: can meta have only fields without a section?
		}*/

		/**
		 * Create Settings pages
		*/
		foreach ( $this->settings as $settings_id => $settings_page ) {
			if ( empty( $settings_page['fields'] ) && empty( $settings_page['sections'] ) ) {
				continue;
			}
			$this->create_settings_page( $settings_id, $settings_page );
		}
	}

	private function create_settings_page( $settings_id, $settings_page ) {

		$default_args = array(
			'menu_slug'   => $settings_id,
			'menu_parent' => 'edit.php?post_type=' . $this->name,
			'menu_type'   => 'submenu',
			'theme'       => 'light',
		);

		$args = array_merge( $default_args, $settings_page );

		// Create options
		\CSF::createOptions( $settings_id, $args );

		if ( isset( $settings_page['fields'] ) ) {
			$this->create_section( $settings_id, $settings_page );
			return;
		}

		if ( isset( $settings_page['sections'] ) ) {
			foreach ( $settings_page['sections'] as $section ) {
				$this->create_section( $settings_id, $section );
			}
		}

	}

	private function create_section( $settings_id, $section ) {

		\CSF::createSection( $settings_id, $section );
		return;

	}


}
