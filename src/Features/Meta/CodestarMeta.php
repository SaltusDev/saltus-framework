<?php
namespace Saltus\WP\Framework\Features\Meta;

final class CodestarMeta {

	private $name;
	private $meta;
	private $project;

	/**
	 * Instantiate the Codestar Framework Fields object.
	 */
	public function __construct( string $name, array $project, array $meta = array() ) {
		$this->name    = $name;
		$this->project = $project;
		$this->meta    = $meta;

		$this->init();

	}

	private function init() {

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

		if ( ! class_exists( '\CSF' ) ) {
			return;
		}

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

		if ( ! class_exists( '\CSF' ) ) {
			return;
		}
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
