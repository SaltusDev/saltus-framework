<?php
namespace Saltus\WP\Framework\Features\Meta;

use Saltus\WP\Framework\Infrastructure\Service\{
	Processable
};


final class CodestarMeta implements Processable {

	private $name;
	private $meta;

	/**
	 * Instantiate the Codestar Framework Fields object.
	 */
	public function __construct( string $name, array $project = null, array $meta = array() ) {
		$this->name    = $name;
		$this->meta    = $meta;
	}

	public function process() {

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

		// add filter to properly save line breaks in this meta box
		add_filter( sprintf( 'csf_%s_save', $box_id ), array( $this, 'sanitize_meta_save' ), 1, 3 );

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

	/**
	 * Function to sanitize meta on save
	 *
	 * @param array $request with meta info
	 * @param int $post_id
	 * @param obj $csf class
	 * @return array
	 */
	public function sanitize_meta_save( $request, $post_id, $csf ) {

		if ( empty( $request ) || ! is_array( $request ) ) {
			return $request;
		}

		//replace line breaks on meta info to make it compatible with export
		array_walk_recursive(
			$request,
			function ( &$value ) {
				$value = str_replace( "\r\n", "\n", $value );
			}
		);

		return $request;

	}
}
