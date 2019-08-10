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

	public function setup( $name, $meta ) {

		$this->name = $name;
		$this->meta = $meta;
	}

	public function init() {

		/**
		 * Create Fields
		 *
		 * Start by setting up Sections, which in turn will create fields
		*/
		foreach ( $this->meta as $section ) {
			if ( ! empty( $section['fields'] ) ) {
				$this->create_section( $section );
				continue;
			}
			// TODO by pcarvalho: can meta have only fields without a section?
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

	private function create_field( $cmb, $id, $field ) {

		// set default args
		$default_args = array(
			'id'         => $id,
			'name'       => $id,
			'type'       => 'text',
		);

		// set custom parameters considering the defaults
		$args = array_merge( $default_args, $field );

		$cmb->add_field( $args );
	}

}
